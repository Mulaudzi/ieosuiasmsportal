<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Optional CLI argument: owner-supplied SMSSendReport_20261024.xlsx.
// Real report reader/importer/reconciliation/WalletService/refreshCampaign;
// database is always isolated SQLite, with no config or network provider.
function env(string $key,mixed $default=null):mixed{return $default;}
function db():PDO{return $GLOBALS['pdo'];}
final class ReportIntegrationPDO extends PDO
{
    public array $statements=[];
    public bool $ownsWorkerLock=true;
    public function prepare(string $sql,array $options=[]):PDOStatement|false
    {
        $this->statements[]=$sql;
        $sql=str_replace([' FOR UPDATE','balance = balance - ?','reserved - ?'],['','balance = ROUND(balance - ?,4)','ROUND(reserved - ?,4)'],$sql);
        $sql=str_replace('TIMESTAMPDIFF(SECOND,',"TIMESTAMPDIFF('SECOND',",$sql);
        return parent::prepare($sql,$options);
    }
}
require_once __DIR__.'/../domain/LogicSmsReportImport.php';
require_once __DIR__.'/../domain/LogicSmsReportReader.php';
require_once __DIR__.'/../domain/SmsReportingService.php';
const SUNDAY_TEXT='Hello! This is a reminder to come join us for our Special Sunday Service @NWU Potch Campus, Sunday, 25 Oct 2026. Time: 10AM! Venue: E8-B41! God bless you! ~BLW';
const TEST_ONE_TEXT='Test Message from IEOSUIA SMS';
const TEST_TWO_TEXT='Test 2 from IEOSUIA SMS';
const REPORT_TARGETS=[
    936=>[2,24,'+27636644052',SUNDAY_TEXT,'DELIVERED','2026-10-23 21:04:08'],
    937=>[2,24,'+27648634848',SUNDAY_TEXT,'DELIVERED','2026-10-23 21:04:06'],
    938=>[2,24,'+27646412371',SUNDAY_TEXT,'DELIVERED','2026-10-23 21:04:05'],
    // The original screenshots were misread: actual report is 0793372449.
    939=>[2,24,'+27793372449',SUNDAY_TEXT,'SENT','2026-10-23 21:04:04'],
    940=>[2,24,'+27659244814',SUNDAY_TEXT,'DELIVERED','2026-10-23 21:04:03'],
    941=>[7,25,'+27638082493',TEST_ONE_TEXT,'DELIVERED','2026-10-24 00:23:05'],
    942=>[7,25,'+27799292775',TEST_ONE_TEXT,'SENT','2026-10-24 00:23:04'],
    943=>[7,25,'+27721101883',TEST_ONE_TEXT,'SENT','2026-10-24 00:23:03'],
    944=>[7,25,'+27816545388',TEST_ONE_TEXT,'INVALID','2026-10-24 00:23:06'],
    1200=>[7,208,'+27721101883',TEST_TWO_TEXT,'DELIVERED','2026-10-24 00:59:03'],
    1201=>[7,208,'+27799292775',TEST_TWO_TEXT,'DELIVERED','2026-10-24 00:59:04'],
    1202=>[7,208,'+27638082493',TEST_TWO_TEXT,'SENT','2026-10-24 00:59:05'],
    1203=>[7,208,'+27816545388',TEST_TWO_TEXT,'INVALID','2026-10-24 00:59:06'],
    1204=>[7,208,'+27638067018',TEST_TWO_TEXT,'WORKING','2026-10-24 00:59:08'],
];
$checks=0;
function integrationCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function reportScopes():array{return [
    ['email'=>'blwnwupotch@gmail.com','campaign_id'=>24,'content_sha256'=>hash('sha256',SUNDAY_TEXT)],
    ['email'=>'vendaboy.lm@gmail.com','campaign_id'=>25,'content_sha256'=>hash('sha256',TEST_ONE_TEXT)],
    // Intentionally discover the second test's real matching ID, not ID 26.
    ['email'=>'vendaboy.lm@gmail.com','content_sha256'=>hash('sha256',TEST_TWO_TEXT)],
];}
function syntheticReport():array
{
    $rows=[];$source=489;
    foreach(REPORT_TARGETS as [$user,$campaign,$phone,$content,$status,$date])$rows[]=['source_row'=>$source++,'reported_at'=>$date,'destination'=>$phone,'content'=>$content,'status'=>$status,'provider_credits'=>'1.0000','group'=>''];
    $rows[]=['source_row'=>1000,'reported_at'=>'2026-10-20 14:00:00','destination'=>'+27821234567','content'=>'Unrelated owner campaign','status'=>'DELIVERED','provider_credits'=>'1.0000','group'=>''];
    return ['file_sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR)),'row_count'=>count($rows),'rows'=>$rows];
}
function reportFixture(array $preaccepted=[],array $unattributedByUser=[]):ReportIntegrationPDO
{
    $p=new ReportIntegrationPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->sqliteCreateFunction('NOW',fn()=>'2026-10-24 09:00:00');$p->sqliteCreateFunction('IF',fn($test,$yes,$no)=>$test?$yes:$no);$p->sqliteCreateFunction('GREATEST',fn(...$args)=>max($args));
    $p->sqliteCreateFunction('IS_USED_LOCK',fn($name)=>$name===SmsStatusReconciliation::WORKER_LOCK&&$p->ownsWorkerLock?42:null);$p->sqliteCreateFunction('CONNECTION_ID',fn()=>42);
    $p->sqliteCreateFunction('TIMESTAMPDIFF',fn($unit,$from,$to)=>strtotime($to)-strtotime($from));
    $p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT);
        CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER,balance NUMERIC,reserved NUMERIC,currency TEXT,updated_at TEXT);
        CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,state TEXT,recipient_count INTEGER,queued_count INTEGER,processing_count INTEGER,sent_count INTEGER,delivered_count INTEGER,failed_count INTEGER,skipped_count INTEGER,actual_cost NUMERIC,completed_at TEXT,started_at TEXT,created_at TEXT,updated_at TEXT,sender_id TEXT,content TEXT);
        CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,campaign_id INTEGER,user_id INTEGER,destination TEXT,content TEXT,state TEXT,provider TEXT,provider_message_id TEXT,sent_at TEXT,delivered_at TEXT,failed_at TEXT,last_error TEXT,actual_charge NUMERIC DEFAULT 0,estimated_charge NUMERIC,segment_count INTEGER,attempt_count INTEGER DEFAULT 1,next_attempt_at TEXT,lease_token TEXT,leased_at TEXT,retry_generation INTEGER DEFAULT 0,created_at TEXT,updated_at TEXT,last_dlr_checked_at TEXT,dlr_attempt_count INTEGER DEFAULT 0,UNIQUE(provider,provider_message_id));
        CREATE TABLE wallet_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,wallet_id INTEGER,campaign_id INTEGER,payment_id INTEGER,operation TEXT,direction TEXT,amount NUMERIC,idempotency_key TEXT UNIQUE,metadata_json TEXT,created_at TEXT);
        CREATE TABLE operational_logs(id INTEGER PRIMARY KEY,level TEXT,service TEXT,event TEXT,message TEXT,campaign_id INTEGER,sms_message_id INTEGER,user_id INTEGER,context_json TEXT,created_at TEXT);
        CREATE TABLE opt_outs(user_id INTEGER,channel TEXT,recipient TEXT);
        INSERT INTO users VALUES(2,'blwnwupotch@gmail.com'),(7,'vendaboy.lm@gmail.com'),(9,'other@example.test');
        INSERT INTO wallets VALUES(11,2,175,1.75,'ZAR',NULL),(17,7,100,3.15,'ZAR',NULL),(19,9,25,0,'ZAR',NULL);
        INSERT INTO sms_campaigns(id,user_id,name,state,recipient_count,queued_count,processing_count,sent_count,delivered_count,failed_count,skipped_count,actual_cost,created_at,started_at,content) VALUES(24,2,'SUPER SUNDAY','processing',5,5,0,0,0,0,0,0,'2026-10-23 21:02:00','2026-10-23 21:03:00','Original Sunday content'),(25,7,'Test','processing',4,4,0,0,0,0,0,0,'2026-10-24 00:22:00','2026-10-24 00:23:00','Original test one content'),(208,7,'Second test actual ID 208','processing',5,5,0,0,0,0,0,0,'2026-10-24 00:58:00','2026-10-24 00:59:00','Original test two content'),(26,7,'Unrelated campaign ID 26','completed',1,0,0,1,0,0,0,.35,'2026-10-22 00:22:00','2026-10-22 00:23:00','Must not guess campaign 26'),(999,9,'Other account same content','completed',1,0,0,1,0,0,0,.35,'2026-10-24 00:22:00','2026-10-24 00:23:00','Must not touch other customer');
        INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,operation,direction,amount,idempotency_key,metadata_json,created_at) VALUES(2,11,24,'reservation','reserve',1.75,'campaign:24:reserve','{\"original\":true}','2026-10-23 21:02:00'),(7,17,25,'reservation','reserve',1.4,'campaign:25:reserve',NULL,'2026-10-24 00:22:00'),(7,17,208,'reservation','reserve',1.75,'campaign:208:reserve',NULL,'2026-10-24 00:58:00'),(7,17,26,'reservation','reserve',.35,'campaign:26:reserve',NULL,'2026-10-22 00:22:00'),(7,17,26,'sms_settlement','debit',.35,'sms-message:1699:settle',NULL,'2026-10-22 00:23:00'),(9,19,999,'reservation','reserve',.35,'campaign:999:reserve',NULL,'2026-10-24 00:22:00'),(9,19,999,'sms_settlement','debit',.35,'sms-message:1700:settle',NULL,'2026-10-24 00:23:00');
        INSERT INTO sms_messages(id,user_id,campaign_id,destination,content,state,provider,provider_message_id,sent_at,actual_charge,estimated_charge,segment_count) VALUES(1699,7,26,'+27638082493','Unrelated old message','sent','logicsms','unrelated-real-id','2026-10-22 00:23:05',.35,.35,1),(1700,9,999,'+27638082493','Test Message from IEOSUIA SMS','sent','logicsms','9999','2026-10-24 00:23:05',.35,.35,1);");
    $q=$p->prepare("INSERT INTO sms_messages(id,user_id,campaign_id,destination,content,state,actual_charge,estimated_charge,segment_count,attempt_count,next_attempt_at,last_error,created_at,updated_at) VALUES(?,?,?,?,?,'retry',0,.35,1,?,'9999-12-31 23:59:59',?,?, '2026-10-24 01:00:00')");
    foreach(REPORT_TARGETS as $id=>[$user,$campaign,$phone,$content]){
        $created=$campaign===24?'2026-10-23 21:02:00':($campaign===25?'2026-10-24 00:22:00':'2026-10-24 00:58:00');
        $error=$id===936?'Provider accepted reference 9999; awaiting local settlement; original error preserved':'Original acknowledgement could not be verified';
        $q->execute([$id,$user,$campaign,$phone,$content,$id===936?2:1,$error,$created]);
    }
    foreach($preaccepted as $id){
        [$user,$campaign]=REPORT_TARGETS[$id];$wallet=$user===2?11:17;
        $q=$p->prepare("UPDATE sms_messages SET state='sent',provider='logicsms',provider_message_id=?,sent_at='2026-10-23 21:04:00',actual_charge=.35,next_attempt_at=NULL,last_error=NULL WHERE id=?");$q->execute(['actual-prior-ack-'.$id,$id]);
        $q=$p->prepare('UPDATE wallets SET balance=ROUND(balance-.35,4),reserved=ROUND(reserved-.35,4) WHERE id=?');$q->execute([$wallet]);
        $q=$p->prepare("INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,operation,direction,amount,idempotency_key,created_at) VALUES(?,?,?,'sms_settlement','debit',.35,?,NOW())");$q->execute([$user,$wallet,$campaign,'sms-message:'.$id.':settle']);
    }
    foreach($unattributedByUser as $user=>$amount){
        $q=$p->prepare('UPDATE wallets SET reserved=ROUND(reserved+?,4) WHERE user_id=?');$q->execute([$amount,$user]);
    }
    return $p;
}
function integrationDump(PDO $p):array{$out=[];foreach(['users','wallets','sms_campaigns','sms_messages','wallet_ledger','operational_logs'] as $table)$out[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);return $out;}
function integrationRow(PDO $p,int $id):array{$q=$p->prepare('SELECT * FROM sms_messages WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);}
function checkFinalReport(PDO $p,array $before,array $preaccepted=[],array $unattributedByUser=[]):void
{
    $after=integrationDump($p);$states=array_count_values(array_map(static fn($id)=>integrationRow($p,$id)['state'],array_keys(REPORT_TARGETS)));
    integrationCheck($states===['delivered'=>7,'sent'=>4,'failed'=>2,'retry'=>1],'Exactly 7 delivered, 4 sent, 2 INVALID delivery failures and 1 unresolved working');
    foreach(REPORT_TARGETS as $id=>[$user,$campaign,$phone,$content,$status]){
        $row=integrationRow($p,$id);$old=array_values(array_filter($before['sms_messages'],static fn($x)=>(int)$x['id']===$id))[0];
        if($status==='WORKING'){integrationCheck($row===$old&&(float)$row['actual_charge']===0.0,'Undefined working row stays held, uncharged and byte-for-byte unchanged');continue;}
        integrationCheck((float)$row['actual_charge']===.35&&$row['destination']===$phone&&$row['content']===$content&&$row['attempt_count']===$old['attempt_count'],'Matched full-content and exact recipient row settles 35c without changing originals');
        integrationCheck($row['sent_at']===$old['sent_at']&&$row['delivered_at']===$old['delivered_at']&&$row['provider_message_id']===$old['provider_message_id'],'Report never invents provider IDs or send/delivery times');
    }
    $wallets=array_column($after['wallets'],null,'user_id');integrationCheck((float)$wallets[2]['balance']===173.25&&(float)$wallets[2]['reserved']===round((float)($unattributedByUser[2]??0),4)&&(float)$wallets[7]['balance']===97.2&&(float)$wallets[7]['reserved']===round(.35+(float)($unattributedByUser[7]??0),4),'Two customer wallets charged only R1.75/R2.80, retaining 35c for unknown working plus all unattributed reservations');
    $campaigns=array_column($after['sms_campaigns'],null,'id');
    foreach([24=>['completed',5,4,0,0,1.75],25=>['completed',4,1,1,0,1.4],208=>['processing',4,2,1,1,1.4]] as $id=>[$state,$sent,$delivered,$failed,$queued,$cost]){
        $c=$campaigns[$id];integrationCheck($c['state']===$state&&(int)$c['sent_count']===$sent&&(int)$c['delivered_count']===$delivered&&(int)$c['failed_count']===$failed&&(int)$c['queued_count']===$queued&&(float)$c['actual_cost']===$cost,'Real refreshCampaign matches report state/counters/cost for '.$id);
    }
    integrationCheck(integrationRow($p,939)['destination']==='+27793372449'&&integrationRow($p,939)['state']==='sent','Actual Sunday 0793372449 is used, not earlier transposed 0793732449');
    $beforeOther=array_values(array_filter($before['sms_messages'],static fn($x)=>!isset(REPORT_TARGETS[(int)$x['id']])));
    $afterOther=array_values(array_filter($after['sms_messages'],static fn($x)=>!isset(REPORT_TARGETS[(int)$x['id']])));
    integrationCheck($afterOther===$beforeOther&&$after['users']===$before['users']&&$wallets[9]===array_column($before['wallets'],null,'user_id')[9]&&$campaigns[26]===array_column($before['sms_campaigns'],null,'id')[26]&&$campaigns[999]===array_column($before['sms_campaigns'],null,'id')[999],'Unrelated account, 9999 collision and campaign26 preserved');
    integrationCheck(count($after['wallet_ledger'])===count($before['wallet_ledger'])+13-count($preaccepted),'Exactly one new settlement per previously uncharged known message');
    $GLOBALS['pdo']=$p;$summary=SmsReportingService::summary(7,'custom','2026-10-24','2026-10-24');
    integrationCheck($summary['total_messages']===8&&$summary['delivered']===3&&$summary['failed']===2&&$summary['failed_submissions']===0&&$summary['waiting_to_send']===1&&$summary['pending']===3&&$summary['credits_used']===8,'Dashboard/report summary includes state-accepted messages even with null sent_at');
}

$report=isset($argv[1])?LogicSmsReportReader::readFile($argv[1],basename($argv[1])):syntheticReport();
$pdo=reportFixture();$before=integrationDump($pdo);$pdo->statements=[];$plan=LogicSmsReportImport::preview($pdo,$report,reportScopes());
integrationCheck($plan['relevant_rows']===14&&($plan['counts']['MATCHED']??0)===13&&($plan['counts']['UNRESOLVED_STATUS']??0)===1,'Full report resolves 13 known rows across three campaigns and preserves one working row');
integrationCheck(integrationDump($pdo)===$before&&count(array_filter($pdo->statements,static fn($sql)=>!str_starts_with($sql,'SELECT')))===0,'Real import preview is SELECT-only and leaves every table unchanged');
integrationCheck(array_unique(array_column($plan['matches'],'message_id'))===array_column($plan['matches'],'message_id')&&in_array(208,array_column($plan['rows'],'campaign_id'),true)&&!in_array(26,array_column($plan['rows'],'campaign_id'),true),'Plan uniquely targets actual second test ID208 and never guesses ID26');
$result=LogicSmsReportImport::apply($pdo,$report,reportScopes(),$plan['plan_sha256']);
integrationCheck($result['ok']&&count($result['results'])===13&&!$result['resend_performed']&&$result['provider_requests']===0,'Real importer applies known report rows without any send or provider call');
integrationCheck(count(array_filter($result['results'],static fn($r)=>$r['charged_now']))===13,'All-held fixture charges each of 13 verified records exactly once');
checkFinalReport($pdo,$before);$complete=integrationDump($pdo);
$repeat=LogicSmsReportImport::apply($pdo,$report,reportScopes(),$plan['plan_sha256']);
integrationCheck($repeat['ok']&&count(array_filter($repeat['results'],static fn($r)=>$r['duplicate']))===13&&integrationDump($pdo)===$complete,'Whole report retry is idempotent with no extra audit, charge or status rewrite');
$audit=array_map(static fn($row)=>json_decode($row['context_json'],true,512,JSON_THROW_ON_ERROR),$complete['operational_logs']);
integrationCheck(count($audit)===26&&count(array_filter($audit,static fn($context)=>isset($context['before']['message']['last_error'])&&$context['before']['message']['last_error']!==null))===13,'Every original held error is preserved in one mandatory before receipt');
integrationCheck(count(array_filter($audit,static fn($context)=>($context['evidence']['source']??null)==='owner_report'))===26,'All before/after evidence is labelled owner report, not an automated webhook');

$pdo=reportFixture([936,941]);$mixedBefore=integrationDump($pdo);$mixedPlan=LogicSmsReportImport::preview($pdo,$report,reportScopes());$mixed=LogicSmsReportImport::apply($pdo,$report,reportScopes(),$mixedPlan['plan_sha256']);
integrationCheck($mixed['ok']&&count(array_filter($mixed['results'],static fn($r)=>$r['charged_now']))===11,'Already accepted/canonically settled messages receive status updates but no second charge');
checkFinalReport($pdo,$mixedBefore,[936,941]);

// Migration-compatible wallet with an untouched pre-ledger reservation. Model
// the production partial receipt: Sunday already committed, tests still held.
$pdo=reportFixture([],[7=>6.51]);$openingBefore=integrationDump($pdo);$openingPlan=LogicSmsReportImport::preview($pdo,$report,reportScopes());
foreach($openingPlan['matches'] as $match)if($match['evidence']['campaign_id']===24)SmsStatusReconciliation::apply($pdo,$match['message_id'],$match['evidence']);
$openingResume=LogicSmsReportImport::apply($pdo,$report,reportScopes(),$openingPlan['plan_sha256']);
integrationCheck($openingResume['ok']&&count(array_filter($openingResume['results'],static fn($row)=>$row['duplicate']))===5&&count(array_filter($openingResume['results'],static fn($row)=>$row['charged_now']))===8,'Resume retains five completed Sunday settlements and reconciles only eight remaining known test messages with positive unattributed funds');
checkFinalReport($pdo,$openingBefore,[],[7=>6.51]);$openingAfter=integrationDump($pdo);
integrationCheck(count($openingAfter['wallet_ledger'])===count($openingBefore['wallet_ledger'])+13,'No opening/backfill/refund entry is invented for the unattributed 6.51');
$openingAudit=array_map(static fn($row)=>json_decode($row['context_json'],true,512,JSON_THROW_ON_ERROR),$openingAfter['operational_logs']);$allComponentsPreserved=true;
foreach($openingAudit as $receipt){
    $side=isset($receipt['before'])?'before':'after';$expected=$receipt[$side]['message']['user_id']===7?'6.5100':'0.0000';
    $components=$receipt[$side]['financial']['reservation_components'];
    if($components['unattributed_reserved_component']!==$expected||$components['unattributed_component_semantics']!=='retained_unattributed_not_backfilled_released_or_spent')$allComponentsPreserved=false;
}
integrationCheck($allComponentsPreserved&&count($openingAudit)===26,'All real-reader report before/after receipts explicitly preserve each owner unattributed amount without claiming a historical origin');
$openingWallet=array_column($openingAfter['wallets'],null,'user_id')[7];
integrationCheck((float)$openingWallet['reserved']===6.86&&(int)round(((float)$openingWallet['balance']-(float)$openingWallet['reserved'])*10000)===(int)round((100-3.15-6.51)*10000),'Unresolved working retains 35c separately from 6.51 unattributed funds, while customer available balance remains unchanged');
$openingRepeat=LogicSmsReportImport::apply($pdo,$report,reportScopes(),$openingPlan['plan_sha256']);
integrationCheck($openingRepeat['ok']&&integrationDump($pdo)===$openingAfter,'Whole report repeat preserves positive unattributed reservation, all ledger rows and every audit unchanged');

// Exact read-only production accounting snapshot: 358.95 balance, 7.91
// reserved, canonical test reservations 1.40+1.75, unattributed component 4.76.
$pdo=reportFixture([],[7=>4.76]);$pdo->exec('UPDATE wallets SET balance=358.95 WHERE user_id=7');
$actualPlan=LogicSmsReportImport::preview($pdo,$report,reportScopes());
foreach($actualPlan['matches'] as $match)if($match['evidence']['campaign_id']===24)SmsStatusReconciliation::apply($pdo,$match['message_id'],$match['evidence']);
$beforeActualResume=integrationDump($pdo);$actualWalletBefore=array_column($beforeActualResume['wallets'],null,'user_id')[7];
integrationCheck((float)$actualWalletBefore['balance']===358.95&&(float)$actualWalletBefore['reserved']===7.91,'Exact production snapshot regression begins with balance358.95/reserved7.91/canonical3.15/unattributed4.76');
$actualResume=LogicSmsReportImport::apply($pdo,$report,reportScopes(),$actualPlan['plan_sha256']);$actualAfter=integrationDump($pdo);$actualWalletAfter=array_column($actualAfter['wallets'],null,'user_id')[7];
integrationCheck($actualResume['ok']&&count(array_filter($actualResume['results'],static fn($row)=>$row['duplicate']))===5&&count(array_filter($actualResume['results'],static fn($row)=>$row['charged_now']))===8,'Exact production component permits only eight test settlements and never rebills the five Sunday messages');
integrationCheck((float)$actualWalletAfter['balance']===356.15&&(float)$actualWalletAfter['reserved']===5.11,'Eight 35c test settlements retain exact production component4.76 plus unknown-working reservation0.35');
integrationCheck(count($actualAfter['wallet_ledger'])===count($beforeActualResume['wallet_ledger'])+8&&array_column($actualAfter['wallets'],null,'user_id')[2]===array_column($beforeActualResume['wallets'],null,'user_id')[2],'Exact regression adds only eight settlement rows and leaves Sunday customer wallet unchanged');
$actualComponentsPreserved=true;
foreach($actualAfter['operational_logs'] as $entry){
    if((int)$entry['user_id']!==7)continue;$receipt=json_decode($entry['context_json'],true,512,JSON_THROW_ON_ERROR);$side=isset($receipt['before'])?'before':'after';
    if($receipt[$side]['financial']['reservation_components']['unattributed_reserved_component']!=='4.7600')$actualComponentsPreserved=false;
}
integrationCheck($actualComponentsPreserved&&integrationRow($pdo,1204)===array_values(array_filter($beforeActualResume['sms_messages'],static fn($row)=>(int)$row['id']===1204))[0],'Every exact-production before/after test receipt preserves4.7600 and the WORKING row is unchanged');
integrationCheck((int)round(((float)$actualWalletAfter['balance']-(float)$actualWalletAfter['reserved'])*10000)===(int)round(((float)$actualWalletBefore['balance']-(float)$actualWalletBefore['reserved'])*10000),'Exact production regression preserves351.04 customer available funds without spending unattributed reservations');
$actualRepeat=LogicSmsReportImport::apply($pdo,$report,reportScopes(),$actualPlan['plan_sha256']);
integrationCheck($actualRepeat['ok']&&integrationDump($pdo)===$actualAfter,'Exact production regression repeat adds no audits, debits or reservation changes');

$pdo=reportFixture();$pdo->exec("UPDATE sms_messages SET destination='+27793732449' WHERE id=939");$wrong=LogicSmsReportImport::preview($pdo,$report,reportScopes());
$sundayRow=array_values(array_filter($wrong['rows'],static fn($row)=>$row['destination']==='+27793372449'))[0];
integrationCheck($sundayRow['match_code']==='UNMATCHED'&&!in_array(939,array_column($wrong['matches'],'message_id'),true),'Earlier transposed Sunday destination is not silently attached by fuzzy matching');
$pdo=reportFixture();$pdo->exec("UPDATE sms_messages SET content='Test Message from IEOSUIA SMS ' WHERE id=941");$wrong=LogicSmsReportImport::preview($pdo,$report,reportScopes());integrationCheck(!in_array(941,array_column($wrong['matches'],'message_id'),true),'Full message whitespace differences cannot match by a trimmed prefix');
$pdo=reportFixture();$pdo->exec("UPDATE sms_campaigns SET created_at='2026-10-25 01:00:00',started_at='2026-10-25 01:00:00' WHERE id=25");$wrong=LogicSmsReportImport::preview($pdo,$report,reportScopes());integrationCheck(!in_array(25,array_column($wrong['matches'],'campaign_id'),true),'Future-dated campaign cannot absorb a report from an earlier send');

$pdo=reportFixture();$partialBefore=integrationDump($pdo);$partialPlan=LogicSmsReportImport::preview($pdo,$report,reportScopes());$stopIndex=6;$stopId=$partialPlan['matches'][$stopIndex]['message_id'];
$pdo->exec("CREATE TRIGGER reject_one_report_audit BEFORE INSERT ON operational_logs WHEN NEW.sms_message_id=".(int)$stopId." AND NEW.event='status_evidence_after' BEGIN SELECT RAISE(ABORT,'Injected mandatory audit failure');END");
$partial=LogicSmsReportImport::apply($pdo,$report,reportScopes(),$partialPlan['plan_sha256']);
integrationCheck(!$partial['ok']&&$partial['code']==='REPORT_PARTIALLY_APPLIED'&&count($partial['results'])===$stopIndex&&count($partial['errors'])===1&&$partial['errors'][0]['message_id']===$stopId,'Batch stops at first guard failure and truthfully reports already committed per-message results');
$failedOld=array_values(array_filter($partialBefore['sms_messages'],static fn($row)=>(int)$row['id']===$stopId))[0];
integrationCheck(integrationRow($pdo,$stopId)===$failedOld,'Failed row, charge and original held error are fully rolled back');
$q=$pdo->prepare('SELECT COUNT(*) FROM operational_logs WHERE sms_message_id=?');$q->execute([$stopId]);integrationCheck((int)$q->fetchColumn()===0&&!$pdo->inTransaction(),'Failed row leaves no orphan audit receipt or open transaction');
$pdo->exec('DROP TRIGGER reject_one_report_audit');$resume=LogicSmsReportImport::apply($pdo,$report,reportScopes(),$partialPlan['plan_sha256']);
integrationCheck($resume['ok']&&count(array_filter($resume['results'],static fn($row)=>$row['charged_now']))===13-$stopIndex&&count(array_filter($resume['results'],static fn($row)=>$row['duplicate']))===$stopIndex,'Resume skips earlier committed receipts and charges only the remaining rows');
checkFinalReport($pdo,$partialBefore);

echo 'PASS: '.$checks.' report-reader/import/core/wallet/projection integration checks; '.(isset($argv[1])?'actual owner XLSX read-only':'synthetic full target report')."; no production database, sends or network.\n";
