<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Real domain/controller methods, isolated SQLite only. Never load production config.
final class DraftFixturePdo extends PDO {
    public function exec(string $sql): int|false {
        $sql=preg_replace('/,\s*KEY idx_work_drafts_owner\(user_id,kind,updated_at\)/','',$sql);
        $sql=preg_replace('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/',')',$sql);
        return parent::exec($sql);
    }
}
$pdo=new DraftFixturePdo('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY); INSERT INTO users VALUES(1),(2); CREATE TABLE wallets(balance INTEGER,reserved INTEGER); INSERT INTO wallets VALUES(1000,5); CREATE TABLE sms_campaigns(id INTEGER); CREATE TABLE sms_messages(id INTEGER); CREATE TABLE payments(id INTEGER); CREATE TABLE credit_purchase_orders(id INTEGER);');
$migration=require __DIR__.'/../migrations/016_work_drafts.php';$migration($pdo);$migration($pdo);
function db():PDO {return $GLOBALS['pdo'];}
class Auth {public static int $user=1;public static function id():int{return self::$user;}}
class Request {public static array $data=[];public static array $query=[];public static function all():array{return self::$data;}public static function input(string $key):mixed{return self::$data[$key]??null;}public static function query(string $key):mixed{return self::$query[$key]??null;}}
class DraftResponse extends RuntimeException {public function __construct(public array $data,public int $status){parent::__construct();}}
class Response {public static function success(array $data):never{throw new DraftResponse($data,200);}public static function error(string $message,int $status):never{throw new DraftResponse(['message'=>$message],$status);}}
require __DIR__.'/../controllers/WorkDraftController.php';
$checks=0;
function check(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);$GLOBALS['checks']++;}
function callDraft(callable $f,int $status=200):array{try{$f();throw new RuntimeException('Missing response');}catch(DraftResponse $r){check($r->status===$status,'Expected '.$status.', got '.$r->status.' '.json_encode($r->data));return $r->data;}}
function id(int $i):string{return '10000000-0000-4000-8000-'.str_pad((string)$i,12,'0',STR_PAD_LEFT);}
$sms=['version'=>1,'step'=>5,'name'=>'Unfinished campaign','manual'=>'0638082493','groupIds'=>['8'],'contactIds'=>['4'],'excluded'=>['27630000000'],'selectedTemplate'=>'none','content'=>'Draft message','includeOptOut'=>false,'schedule'=>'2027-01-01T12:00','submissionKey'=>'stable-campaign-key'];
$purchase=['version'=>1,'step'=>'payment','credits'=>1000,'method'=>'eft','requestKey'=>'stable-purchase-key'];
$controller=new WorkDraftController();
Request::$data=['kind'=>'sms','title'=>'My SMS draft','payload'=>$sms,'expected_version'=>0];
$first=callDraft(fn()=>$controller->save(['id'=>id(1)]))['draft'];
check($first['version']===1 && $first['payload']==$sms && !isset($first['user_id']),'Owner receives original payload without owner field');
check(callDraft(fn()=>$controller->save(['id'=>id(1)]))['draft']['version']===1,'Lost create response is idempotent');
Request::$data=['kind'=>'purchase','title'=>'Credit top-up','payload'=>$purchase,'expected_version'=>0];
check(callDraft(fn()=>$controller->save(['id'=>id(2)]))['draft']['payload']['requestKey']==='stable-purchase-key','Purchase idempotency key preserved');
Request::$query=['kind'=>'sms'];$list=callDraft(fn()=>$controller->index())['drafts'];
check(count($list)===1 && $list[0]['id']===id(1) && !isset($list[0]['payload']),'Kind-specific summaries omit message content and recipients');
Auth::$user=2;check(callDraft(fn()=>$controller->index())['drafts']===[],'Other user sees no drafts');
callDraft(fn()=>$controller->show(['id'=>id(1)]),404);
Request::$data=['kind'=>'sms','title'=>'Steal','payload'=>$sms,'expected_version'=>1];callDraft(fn()=>$controller->save(['id'=>id(1)]),404);
Request::$data=['expected_version'=>1];callDraft(fn()=>$controller->destroy(['id'=>id(1)]),404);
Auth::$user=1;
Request::$data=['kind'=>'sms','title'=>'Edited','payload'=>array_replace($sms,['content'=>'Updated message']),'expected_version'=>1];
check(callDraft(fn()=>$controller->save(['id'=>id(1)]))['draft']['version']===2,'Update advances version');
check(callDraft(fn()=>$controller->save(['id'=>id(1)]))['draft']['version']===2,'Lost update response does not duplicate');
Request::$data['payload']['content']='Stale tab overwrite';callDraft(fn()=>$controller->save(['id'=>id(1)]),409);
Request::$data=['expected_version'=>1];callDraft(fn()=>$controller->destroy(['id'=>id(1)]),409);
check(WorkDraftService::get($pdo,1,id(1))['payload']['content']==='Updated message','Stale writes/deletes leave newer work intact');
Request::$data=['expected_version'=>2];callDraft(fn()=>$controller->destroy(['id'=>id(1)]));callDraft(fn()=>$controller->show(['id'=>id(1)]),404);
Request::$data=['kind'=>'sms','title'=>'Stale restore','payload'=>$sms,'expected_version'=>2];callDraft(fn()=>$controller->save(['id'=>id(1)]),404);
foreach ([['kind'=>'other'],['title'=>''],['payload'=>array_replace($purchase,['total_cents'=>1])],['payload'=>array_replace($purchase,['credits'=>1000001])],['payload'=>array_replace($purchase,['credits'=>1.5])],['payload'=>array_replace($purchase,['method'=>'card-number'])],['payload'=>array_replace($purchase,['requestKey'=>[]])],['expected_version'=>'0']] as $bad) {
    Request::$data=array_replace(['kind'=>'purchase','title'=>'Draft','payload'=>$purchase,'expected_version'=>0],$bad);callDraft(fn()=>$controller->save(['id'=>id(3)]),422);
}
foreach ([['content'=>str_repeat('x',16001)],['manual'=>str_repeat('1',100001)],['includeOptOut'=>'false'],['groupIds'=>['a'=>1]],['step'=>6],['submissionKey'=>''],['bank_details'=>['account'=>'secret']]] as $bad) {
    Request::$data=['kind'=>'sms','title'=>'Draft','payload'=>array_replace($sms,$bad),'expected_version'=>0];callDraft(fn()=>$controller->save(['id'=>id(4)]),422);
}
callDraft(fn()=>$controller->show(['id'=>'1 OR 1=1']),422);
Request::$query=['kind'=>'anything'];callDraft(fn()=>$controller->index(),422);
Request::$data=['kind'=>'purchase','title'=>'Unfinished quantity','payload'=>array_replace($purchase,['credits'=>0]),'expected_version'=>0];callDraft(fn()=>$controller->save(['id'=>id(5)]));
check(WorkDraftService::get($pdo,1,id(5))['payload']['credits']===0,'Partial quantity saved without bypassing checkout validation');
for($i=6;$i<=103;$i++)WorkDraftService::save($pdo,1,id($i),['kind'=>'purchase','title'=>'Draft '.$i,'payload'=>$purchase,'expected_version'=>0]);
Request::$data=['kind'=>'purchase','title'=>'Limit','payload'=>$purchase,'expected_version'=>0];callDraft(fn()=>$controller->save(['id'=>id(104)]),409);
check(count(WorkDraftService::listing($pdo,1,'purchase'))===100,'All saved drafts fit in the list with enforced per-kind limit');
check($pdo->query('SELECT balance FROM wallets')->fetchColumn()===1000 && $pdo->query('SELECT reserved FROM wallets')->fetchColumn()===5,'Balance/reservations unchanged');
foreach(['sms_campaigns','sms_messages','payments','credit_purchase_orders'] as $table)check((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'No side effects in '.$table);
$pdo->exec('DROP TABLE work_drafts');Request::$query=['kind'=>'sms'];callDraft(fn()=>$controller->index(),503);
check($pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='work_drafts'")->fetchColumn()===0,'Web requests never create schema');
echo 'PASS: '.$checks.' draft migration, ownership, validation, conflict, retry, isolation and financial-safety checks; isolated fixtures only.'.PHP_EOL;
