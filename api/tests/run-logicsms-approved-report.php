<?php
declare(strict_types=1);
// Private one-time report adapter fixtures; no network or production credentials.
ob_start();require __DIR__.'/../../.sms-audit.local/report-sync-20261024/export-seed.php';$approvedFixtureSeed=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
function approvedFixtureCanonical(mixed $value):mixed
{
    if(!is_array($value))return $value;
    if(!array_is_list($value))ksort($value,SORT_STRING);
    foreach($value as $key=>$item)$value[$key]=approvedFixtureCanonical($item);
    return $value;
}
$fixtureRoot=sys_get_temp_dir().'/ieosuia-approved-report-'.bin2hex(random_bytes(12));
if(!mkdir($fixtureRoot.'/api/domain',0700,true))throw new RuntimeException('Isolated adapter fixture unavailable');
if(!copy(__DIR__.'/../domain/LogicSmsApprovedReport.php',$fixtureRoot.'/api/domain/LogicSmsApprovedReport.php'))throw new RuntimeException('Fixture source copy unavailable');

final class ApprovedReportFixturePDO extends PDO
{
    public bool $busy=false;
    public bool $ownsLock=false;
    public bool $lockFails=false;
    public bool $releaseFails=false;
    public int $acquired=0;
    public int $released=0;
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        if($this->lockFails&&str_contains($query,'GET_LOCK'))throw new RuntimeException('Synthetic lock failure');
        if($this->releaseFails&&str_contains($query,'RELEASE_LOCK'))throw new RuntimeException('Synthetic release failure');
        return parent::prepare($query,$options);
    }
}
final class LogicSmsReportImport
{
    public static int $previews=0;
    public static int $applies=0;
    public static mixed $previewCallback=null;
    public static mixed $applyCallback=null;
    public static function preview(PDO $pdo,array $report,array $scopes):array
    {
        self::$previews++;
        if(self::$previewCallback)return (self::$previewCallback)($pdo,$report,$scopes);
        $rows=[];$matches=[];$counts=['MATCHED'=>13,'UNRESOLVED_STATUS'=>1];
        foreach($report['rows'] as $index=>$row){
            $hash=hash('sha256',$row['content']);$campaign=$hash===$scopes[0]['content_sha256']?24:($hash===$scopes[1]['content_sha256']?25:777);
            $matched=$row['status']!=='WORKING';$id=10000+$index;
            $rows[]=['source_row'=>$row['source_row'],'message_id'=>$id,'campaign_id'=>$campaign,'destination'=>$row['destination'],'campaign_name'=>'Private test fixture','provider_status'=>$row['status'],'current_state'=>'retry','status_state'=>$row['status_state'],'match_code'=>$matched?'MATCHED':'UNRESOLVED_STATUS'];
            if($matched)$matches[]=['message_id'=>$id,'evidence'=>['private'=>'Not a public receipt field']];
        }
        return ['ok'=>true,'read_only'=>true,'file_sha256'=>$report['file_sha256'],'relevant_rows'=>14,'rows'=>$rows,'matches'=>$matches,'counts'=>$counts,'plan_sha256'=>str_repeat('a',64)];
    }
    public static function apply(PDO $pdo,array $report,array $scopes,string $plan):array
    {
        self::$applies++;
        if(!$pdo instanceof ApprovedReportFixturePDO||!$pdo->ownsLock||$pdo->inTransaction()||$plan!==str_repeat('a',64))throw new RuntimeException('Adapter lost exact preview hash or worker lock');
        if(self::$applyCallback)return (self::$applyCallback)($pdo,$report,$scopes,$plan);
        $pdo->beginTransaction();$pdo->exec('INSERT INTO applied_markers DEFAULT VALUES');$pdo->commit();
        $results=[];for($i=0;$i<13;$i++)$results[]=['message_id'=>10000+$i,'state'=>$i<7?'delivered':($i<11?'sent':'failed'),'applied'=>true,'duplicate'=>false,'charged_now'=>true,'accepted'=>true,'charge_amount'=>'0.3500','destination'=>'Private destination','wallet_balance'=>'9999.00'];
        $campaigns=[];foreach([24,25,777] as $id)$campaigns[]=['id'=>$id,'name'=>'Fixture campaign','state'=>'completed','recipient_count'=>5,'sent_count'=>5,'delivered_count'=>4,'failed_count'=>0,'queued_count'=>0,'processing_count'=>0,'skipped_count'=>0,'actual_cost'=>'1.7500','user_email'=>'private@example.test'];
        return ['ok'=>true,'code'=>'REPORT_RECONCILED','results'=>$results,'errors'=>[],'campaigns'=>$campaigns,'report'=>['private'=>'Never copy the raw report into receipt']];
    }
}
require $fixtureRoot.'/api/domain/LogicSmsApprovedReport.php';
$checks=0;$archives=0;
function approvedCheck(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException($label);$checks++;}
function approvedPDO():ApprovedReportFixturePDO
{
    LogicSmsReportImport::$previews=0;LogicSmsReportImport::$applies=0;LogicSmsReportImport::$previewCallback=null;LogicSmsReportImport::$applyCallback=null;
    $p=new ApprovedReportFixturePDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $p->exec('CREATE TABLE applied_markers(id INTEGER PRIMARY KEY AUTOINCREMENT)');
    $p->sqliteCreateFunction('GET_LOCK',function($name,$wait)use($p){if($name!=='ieosuia-sms-dispatch-worker'||$wait!==0)throw new RuntimeException('Wrong singleton lock');if($p->busy)return 0;$p->ownsLock=true;$p->acquired++;return 1;});
    $p->sqliteCreateFunction('RELEASE_LOCK',function($name)use($p){$p->ownsLock=false;$p->released++;return 1;});
    return $p;
}
function approvedArchive(string $path):void
{
    global $archives;if(file_exists($path)){if(!rename($path,dirname($path).'/fixture-history-'.(++$archives).'.php'))throw new RuntimeException('Could not preserve fixture receipt');}
}
$path=$fixtureRoot.'/api/storage/reconciliation/'.LogicSmsApprovedReport::RECEIPT_NAME;
approvedCheck(LogicSmsApprovedReport::readReceipt($path)===null&&!file_exists(dirname($path)),'Read-only receipt inspection never creates storage directories');
approvedCheck(hash('sha256',json_encode(approvedFixtureCanonical($approvedFixtureSeed),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===LogicSmsApprovedReport::SEED_SHA256,'Exactly approved fourteen-row seed matches its independent canonical fingerprint');
$p=approvedPDO();$result=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);
approvedCheck($result['code']==='REPORT_RECONCILED'&&$result['terminal']&&LogicSmsReportImport::$applies===1,'Missing private directory is safely initialized and approved report is applied once');
approvedCheck(is_dir(dirname($path))&&$p->acquired===1&&$p->released===1&&!$p->ownsLock,'Report import holds the worker lock only during its database operation');
approvedCheck($result['applied_count']===13&&$result['charged_count']===13&&$result['charged_amount']==='4.5500','Receipt aggregates actual per-message settlement results');
$encoded=json_encode($result);
foreach(['destination','private@example.test','wallet_balance','9999.00','Never copy','Not a public receipt field','blwnwupotch@gmail.com','vendaboy.lm@gmail.com'] as $private)approvedCheck(!str_contains($encoded,$private),'Private report/account/contact/wallet fields are omitted from receipt');
approvedCheck(count($result['results'])===13&&count($result['campaigns'])===3&&isset($result['preview_counts']['MATCHED']),'Receipt retains only authorized IDs, states, counts and costs');
$bytes=file_get_contents($path);
approvedCheck(str_starts_with($bytes,'<?php http_response_code(404); exit; /* IEOSUIA_REPORT_RECEIPT_BASE64:')&&str_ends_with($bytes," */\n"),'Runtime receipt is inert PHP404 with a safely parseable base64 marker');
if(PHP_OS_FAMILY!=='Windows')approvedCheck((fileperms(dirname($path))&0777)===0700&&(fileperms($path)&0777)===0600,'New private directory and receipt have restrictive permissions');
$before=file_get_contents($path);$repeat=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);
approvedCheck($repeat['code']==='REPORT_RECONCILED'&&$repeat['from_receipt']&&LogicSmsReportImport::$applies===1&&LogicSmsReportImport::$previews===1,'Completed terminal receipt prevents future automatic preview, charges or audit duplication');
approvedCheck(file_get_contents($path)===$before&&(int)$p->query('SELECT COUNT(*) FROM applied_markers')->fetchColumn()===1,'Completed receipt is immutable and no second operation is invoked');

$bad=$approvedFixtureSeed;$bad['report']['rows'][0]['destination']='+27000000000';$rejected=LogicSmsApprovedReport::run($p,$bad,$path);
approvedCheck($rejected['code']==='APPROVAL_SEED_REJECTED'&&file_get_contents($path)===$before,'Tampered input cannot reuse or overwrite an earlier successful approval receipt');
approvedArchive($path);
foreach(['source_hash','row_count','row_content','scope_owner','scope_campaign','guessed_second_test','extra_row','different_report'] as $case){
    $p=approvedPDO();$bad=$approvedFixtureSeed;
    switch($case){
        case 'source_hash':$bad['report']['file_sha256']=str_repeat('b',64);break;
        case 'row_count':$bad['report']['row_count']=14;break;
        case 'row_content':$bad['report']['rows'][0]['content'].=' changed';break;
        case 'scope_owner':$bad['scopes'][0]['email']='other@example.test';break;
        case 'scope_campaign':$bad['scopes'][1]['campaign_id']=24;break;
        case 'guessed_second_test':$bad['scopes'][2]['campaign_id']=26;break;
        case 'extra_row':$bad['report']['rows'][]=$bad['report']['rows'][0];break;
        case 'different_report':$bad['report_name']='Other.xlsx';break;
    }
    $r=LogicSmsApprovedReport::run($p,$bad,$path);
    approvedCheck($r['code']==='APPROVAL_SEED_REJECTED'&&$r['terminal']&&LogicSmsReportImport::$previews===0&&LogicSmsReportImport::$applies===0,'Different source/rows/account/campaign cannot enter approved automatic import');
    $again=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);
    approvedCheck($again['code']==='APPROVAL_SEED_REJECTED'&&$again['from_receipt']&&LogicSmsReportImport::$applies===0,'Rejected approval is terminal, not an automatic retry loop');
    approvedArchive($path);
}
foreach(['unmatched','ambiguous','missing_scope','exception'] as $case){
    $p=approvedPDO();LogicSmsReportImport::$previewCallback=function($pdo,$report,$scopes)use($case){
        LogicSmsReportImport::$previewCallback=null;$preview=LogicSmsReportImport::preview($pdo,$report,$scopes);LogicSmsReportImport::$previewCallback=static fn()=>throw new RuntimeException('Preview must not repeat');
        if($case==='exception')throw new RuntimeException('Private report unavailable');
        if($case==='missing_scope')foreach($preview['rows'] as &$row)$row['campaign_id']=24;
        else $preview['counts']=['MATCHED'=>12,'UNRESOLVED_STATUS'=>1,strtoupper($case)=>1];
        return $preview;
    };
    $r=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);
    approvedCheck($r['terminal']&&in_array($r['code'],['APPROVAL_PREVIEW_FAILED','APPROVAL_PREVIEW_UNAVAILABLE'],true)&&LogicSmsReportImport::$applies===0&&$p->acquired===0,'Unexpected matching counts/scopes or preview errors stop before any write or worker lock');
    $again=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);approvedCheck($again['from_receipt']&&LogicSmsReportImport::$applies===0,'Preview failure is terminal for automatic cron');
    approvedArchive($path);
}
$p=approvedPDO();$p->busy=true;$busy=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);
approvedCheck($busy['code']==='WORKER_BUSY'&&!$busy['terminal']&&LogicSmsReportImport::$applies===0,'Busy singleton is the only persisted retryable disposition');
$p->busy=false;$resumed=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);
approvedCheck($resumed['code']==='REPORT_RECONCILED'&&LogicSmsReportImport::$applies===1,'A later cron can replace an ephemeral busy receipt with actual verified completion');approvedArchive($path);

foreach(['partial','apply_exception','lock_exception','release_exception'] as $case){
    $p=approvedPDO();
    if($case==='lock_exception')$p->lockFails=true;
    elseif($case==='release_exception')$p->releaseFails=true;
    elseif($case==='apply_exception')LogicSmsReportImport::$applyCallback=static fn()=>throw new RuntimeException('Private apply failure');
    else LogicSmsReportImport::$applyCallback=static fn()=>['ok'=>false,'code'=>'REPORT_PARTIALLY_APPLIED','results'=>[['message_id'=>10001,'state'=>'sent','applied'=>true,'charged_now'=>true,'charge_amount'=>'0.3500']],'errors'=>[['message_id'=>10002,'code'=>'RECONCILIATION_GUARD_FAILED','error_fingerprint'=>str_repeat('b',16),'error'=>'Private error text']],'campaigns'=>[]];
    $r=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);
    approvedCheck($r['terminal']&&in_array($r['code'],['REPORT_PARTIALLY_APPLIED','APPROVAL_APPLY_UNAVAILABLE'],true),'Partial import, application, lock and post-commit release failures become terminal receipts');
    approvedCheck(!str_contains(json_encode($r),'Private apply')&&!str_contains(json_encode($r),'Private error text'),'Failure receipts expose fingerprints and IDs, not raw errors');
    $applies=LogicSmsReportImport::$applies;LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);approvedCheck(LogicSmsReportImport::$applies===$applies,'Terminal failure never re-enters automatic apply');
    approvedArchive($path);
}

$p=approvedPDO();LogicSmsReportImport::$applyCallback=function($pdo,$report,$scopes,$plan)use($path){
    LogicSmsReportImport::$applyCallback=null;$result=LogicSmsReportImport::apply($pdo,$report,$scopes,$plan);
    if(!mkdir($path,0700))throw new RuntimeException('Receipt failure fixture unavailable');return $result;
};
set_error_handler(static fn()=>true);try{$uncertain=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);}finally{restore_error_handler();}
approvedCheck($uncertain['code']==='RECEIPT_WRITE_UNCERTAIN'&&$uncertain['operation_code']==='REPORT_RECONCILED'&&$uncertain['database_changes_may_have_committed'],'Receipt write failure after commit is explicitly uncertain, never claimed rollback');
approvedCheck((int)$p->query('SELECT COUNT(*) FROM applied_markers')->fetchColumn()===1&&!$p->inTransaction(),'Previously committed database operation survives a receipt filesystem failure');
approvedArchive($path);
$stages=glob(dirname($path).'/.report-59b298114f2f4519-*.php')?:[];
approvedCheck(count($stages)>=1,'Failed inert PHP staging receipt is retained as corrective evidence');
foreach($stages as $stage)approvedCheck(str_starts_with(file_get_contents($stage),'<?php http_response_code(404); exit;'),'Even interrupted temporary receipts cannot expose raw public JSON');

$p=approvedPDO();$r=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);$original=file_get_contents($path);
file_put_contents($path,str_replace('BASE64:','BASE64:AA',$original));$tampered=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);
approvedCheck($tampered['code']==='RECEIPT_INVALID'&&LogicSmsReportImport::$applies===1,'Corrupt receipt blocks further imports rather than silently resetting the approval');approvedArchive($path);
foreach([$fixtureRoot.'/outside.php',dirname($path).'/other.php',dirname($path).'/../'.LogicSmsApprovedReport::RECEIPT_NAME] as $wrong){
    $p=approvedPDO();$r=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$wrong);
    approvedCheck($r['code']==='RECEIPT_INVALID'&&LogicSmsReportImport::$applies===0&&!file_exists($wrong),'Caller cannot select a different receipt filename or directory');
}
$p=approvedPDO();$p->beginTransaction();try{LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);approvedCheck(false,'Caller transaction must fail');}catch(DomainException){approvedCheck(true,'Adapter never mixes its receipt persistence into a caller database transaction');}finally{$p->rollBack();}

$resumePath=dirname($path).'/'.LogicSmsApprovedReport::RESUME_RECEIPT_NAME;
$p=approvedPDO();$resumeRejected=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$resumePath);
approvedCheck($resumeRejected['code']==='RESUME_BASELINE_REJECTED'&&LogicSmsReportImport::$previews===0&&LogicSmsReportImport::$applies===0&&!file_exists($resumePath),'Resume cannot run without the exact preserved stopped receipt');
$p=approvedPDO();LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);$wrongOriginal=file_get_contents($path);
$resumeRejected=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$resumePath);
approvedCheck($resumeRejected['code']==='RESUME_BASELINE_REJECTED'&&LogicSmsReportImport::$applies===1&&file_get_contents($path)===$wrongOriginal&&!file_exists($resumePath),'A successful or different original application cannot be resumed');approvedArchive($path);
if(isset($argv[1])){
    $observed=file_get_contents($argv[1]);
    approvedCheck(is_string($observed)&&hash('sha256',$observed)==='1ff5fa1435f559e0a22c40641346d2743c8640e6ae15fb2292f83b61c245967e','Optional production-receipt fixture is the exact inspected inert partial application');
    if(file_put_contents($path,$observed)!==strlen($observed)||!chmod($path,0600))throw new RuntimeException('Isolated original receipt fixture unavailable');
    $p=approvedPDO();$resumed=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$resumePath);
    approvedCheck($resumed['code']==='REPORT_RECONCILED'&&LogicSmsReportImport::$applies===1&&file_get_contents($path)===$observed,'Explicit resume uses a separate receipt and preserves the exact original partial history');
    $resumeBytes=file_get_contents($resumePath);$again=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$resumePath);
    approvedCheck($again['from_receipt']&&LogicSmsReportImport::$applies===1&&file_get_contents($resumePath)===$resumeBytes&&file_get_contents($path)===$observed,'Resume terminal success cannot reapply or overwrite either receipt');
    $mainAgain=LogicSmsApprovedReport::run($p,$approvedFixtureSeed,$path);
    approvedCheck($mainAgain['code']==='REPORT_PARTIALLY_APPLIED'&&$mainAgain['from_receipt']&&LogicSmsReportImport::$applies===1,'Original terminal partial receipt remains historical evidence after a successful separate resume');
    approvedArchive($resumePath);approvedArchive($path);
}
echo "PASS {$checks} approved-report adapter checks; isolated temporary PHP receipts and SQLite, no network or production writes\n";
