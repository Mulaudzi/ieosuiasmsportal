<?php
declare(strict_types=1);

// Isolated selection/transport/audit/lock tests. The reconciliation core is a
// spy here; its real settlement/idempotency behavior has a separate fixture suite.
final class SmsStatusReconciliation
{
    public static array $calls=[];
    public static mixed $callback=null;
    public static function apply(PDO $pdo,int $id,array $evidence):array
    {
        if(!$pdo instanceof StatusSyncFixturePDO||!$pdo->ownsWorkerLock)throw new RuntimeException('Worker lock must be held when applying evidence');
        self::$calls[]=['id'=>$id,'evidence'=>$evidence];
        return self::$callback?(self::$callback)($pdo,$id,$evidence):['ok'=>true,'applied'=>true,'duplicate'=>false,'accepted'=>true];
    }
}
require_once __DIR__.'/../domain/LogicSmsStatusSync.php';

final class StatusSyncFixturePDO extends PDO
{
    public array $sql=[];
    public bool $ownsWorkerLock=false;
    public bool $lockBusy=false;
    public bool $auditFails=false;
    public bool $raceNextClaim=false;
    public int $locksAcquired=0;
    public int $locksReleased=0;
    public string $now='2026-10-24 04:00:00';
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        $this->sql[]=$query;
        if($this->auditFails&&str_starts_with($query,'INSERT INTO operational_logs'))throw new RuntimeException('Synthetic audit unavailable');
        if($this->raceNextClaim&&str_starts_with($query,'UPDATE sms_messages AS m')){
            $this->raceNextClaim=false;
            parent::exec('UPDATE sms_messages SET dlr_attempt_count=1,last_dlr_checked_at=NOW() WHERE id=1');
        }
        $query=str_replace(['TIMESTAMPDIFF(SECOND,',' <=> '],["TIMESTAMPDIFF('SECOND',",' IS '],$query);
        return parent::prepare($query,$options);
    }
}

final class StatusSyncFixtureGateway extends SmsService
{
    public array $calls=[];
    public int $sendCalls=0;
    public function __construct(private StatusSyncFixturePDO $pdo,private mixed $response=null){}
    public function getStatus(string $providerMessageId,?string $clientReference=null):array
    {
        if($this->pdo->inTransaction()||$this->pdo->ownsWorkerLock)throw new LogicException('Network called under transaction or worker lock');
        $this->calls[]=[$providerMessageId,$clientReference];
        return is_callable($this->response)?($this->response)($providerMessageId,$clientReference,count($this->calls)):statusSyncResponse('UNKNOWN',(string)$clientReference);
    }
    public function send(string $phone,string $message,?string $senderId=null,?string $clientReference=null):array
    { $this->sendCalls++;throw new LogicException('Status synchronization must never send'); }
}

$checks=0;
function syncCheck(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException($label);$checks++;}
function statusSyncResponse(string $status,string $reference):array
{
    return ['success'=>true,'status'=>$status,'lookup_reference'=>$reference,'lookup_attempts'=>[['reference'=>$reference,'method'=>'POST','http_status'=>200,'outcome'=>$status]],'provider_status_at'=>null,'timestamp_semantics'=>null];
}
function syncFixture():StatusSyncFixturePDO
{
    SmsStatusReconciliation::$calls=[];SmsStatusReconciliation::$callback=null;
    $p=new StatusSyncFixturePDO('sqlite::memory:');
    $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $p->sqliteCreateFunction('NOW',fn()=>$p->now);
    $p->sqliteCreateFunction('TIMESTAMPDIFF',static fn($unit,$start,$end)=>$start===null?null:strtotime($end)-strtotime($start));
    $p->sqliteCreateFunction('GREATEST',static fn(...$values)=>max($values));
    $p->sqliteCreateFunction('LEAST',static fn(...$values)=>min($values));
    $p->sqliteCreateFunction('POW',static fn($base,$power)=>$base**$power);
    $p->sqliteCreateFunction('CONCAT',static fn(...$values)=>implode('',$values));
    $p->sqliteCreateFunction('CONNECTION_ID',static fn()=>7);
    $p->sqliteCreateFunction('IS_USED_LOCK',fn($name)=>$p->ownsWorkerLock?7:null);
    $p->sqliteCreateFunction('GET_LOCK',function($name,$wait)use($p){
        if($name!==LogicSmsStatusSync::WORKER_LOCK||$wait!==0)throw new RuntimeException('Wrong worker singleton lock');
        if($p->lockBusy)return 0;$p->ownsWorkerLock=true;$p->locksAcquired++;return 1;
    });
    $p->sqliteCreateFunction('RELEASE_LOCK',function($name)use($p){$p->ownsWorkerLock=false;$p->locksReleased++;return 1;});
    $p->exec("CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER);
        INSERT INTO sms_campaigns VALUES(10,2),(20,9);
        CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,user_id INTEGER,campaign_id INTEGER,state TEXT,provider TEXT,provider_message_id TEXT,attempt_count INTEGER,next_attempt_at TEXT,sent_at TEXT,actual_charge NUMERIC,last_error TEXT,lease_token TEXT,leased_at TEXT,created_at TEXT,last_dlr_checked_at TEXT,dlr_attempt_count INTEGER);
        CREATE TABLE operational_logs(id INTEGER PRIMARY KEY,level TEXT,service TEXT,event TEXT,message TEXT,campaign_id INTEGER,sms_message_id INTEGER,user_id INTEGER,context_json TEXT,created_at TEXT);
        CREATE TABLE sms_provider_events(id INTEGER PRIMARY KEY,provider TEXT,provider_event_id TEXT,provider_message_id TEXT,event_type TEXT,payload_json TEXT,processed_at TEXT);");
    return $p;
}
function syncMessage(StatusSyncFixturePDO $p,int $id,array $changes=[]):void
{
    $row=array_replace(['id'=>$id,'user_id'=>2,'campaign_id'=>10,'state'=>'sent','provider'=>'logicsms','provider_message_id'=>'provider-'.$id,'attempt_count'=>1,'next_attempt_at'=>null,'sent_at'=>'2026-10-24 03:00:00','actual_charge'=>'.35','last_error'=>null,'lease_token'=>null,'leased_at'=>null,'created_at'=>'2026-10-24 02:58:00','last_dlr_checked_at'=>null,'dlr_attempt_count'=>0],$changes);
    $q=$p->prepare('INSERT INTO sms_messages('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));
}
function heldChanges():array
{
    return ['state'=>'retry','provider'=>null,'provider_message_id'=>null,'sent_at'=>null,'actual_charge'=>0,'next_attempt_at'=>'9999-12-31 23:59:59','last_error'=>'Submission started; awaiting provider acknowledgement; automatic resend held.'];
}

$p=syncFixture();
syncMessage($p,1);syncMessage($p,2,heldChanges());
foreach([
    3=>['state'=>'pending','sent_at'=>null,'actual_charge'=>0],
    4=>['state'=>'skipped'],5=>['state'=>'delivered'],6=>['state'=>'failed'],
    7=>['provider'=>'mock'],8=>array_replace(heldChanges(),['last_error'=>'Never submitted']),
    9=>array_replace(heldChanges(),['lease_token'=>'owned','leased_at'=>'2026-10-24 03:59:00']),
    10=>array_replace(heldChanges(),['state'=>'processing']),
    11=>['sent_at'=>'2026-10-24 03:59:00'],12=>['sent_at'=>'2026-09-23 03:00:00'],
    13=>['dlr_attempt_count'=>12],14=>['dlr_attempt_count'=>1,'last_dlr_checked_at'=>'2026-10-24 03:59:00'],
    15=>['dlr_attempt_count'=>3,'last_dlr_checked_at'=>'2026-10-24 03:30:00'],16=>['state'=>'dlr_unavailable'],
    17=>['provider'=>null],18=>['lease_token'=>'owned'],19=>['campaign_id'=>20],
    20=>array_replace(heldChanges(),['actual_charge'=>'.35']),
] as $id=>$changes)syncMessage($p,$id,$changes);
$gateway=new StatusSyncFixtureGateway($p);$result=LogicSmsStatusSync::run($p,$gateway,20,microtime(true)+10);
syncCheck($result['selected']===4&&$result['attempted']===4,'Only eligible accepted/uncertain rows enter bounded queries: '.json_encode($result));
syncCheck(array_column($gateway->calls,1)===['portal-message-1','portal-message-2','portal-message-16','portal-message-15'],'Durable lookup order prioritizes never checked then oldest check');
syncCheck(array_column($gateway->calls,0)===['','','',''],'No numeric provider ID fallback, including colliding IDs');
syncCheck($result['unavailable']===4&&$result['applied']===0&&SmsStatusReconciliation::$calls===[],'Unknown lookups never change outcomes or charges');
syncCheck((int)$p->query('SELECT COUNT(*) FROM operational_logs')->fetchColumn()===4,'Every attempted lookup is audited');
syncCheck((int)$p->query('SELECT dlr_attempt_count FROM sms_messages WHERE id=15')->fetchColumn()===4,'Attempt counters increment exactly once');
syncCheck((int)$p->query('SELECT COUNT(*) FROM sms_messages WHERE last_dlr_checked_at IS NOT NULL')->fetchColumn()===5,'Ineligible message lookup metadata is untouched');
syncCheck($gateway->sendCalls===0&&$p->locksAcquired===0,'Unknown queries never acquire worker lock or send SMS');
$repeat=LogicSmsStatusSync::run($p,$gateway,20,microtime(true)+10);
syncCheck($repeat['attempted']===0,'Immediate repeat respects durable backoff');

foreach([[1,'03:55:01',false],[1,'03:55:00',true],[2,'03:50:01',false],[2,'03:50:00',true],[11,'00:00:00',false],[11,'2026-10-23 22:00:00',true],[12,'2026-10-23 20:00:00',false]] as [$count,$time,$due]){
    $p=syncFixture();$at=strlen($time)===8?'2026-10-24 '.$time:$time;syncMessage($p,1,['dlr_attempt_count'=>$count,'last_dlr_checked_at'=>$at]);
    $r=LogicSmsStatusSync::run($p,new StatusSyncFixtureGateway($p));syncCheck(($r['attempted']===1)===$due,'Backoff grows from five minutes to six hours with a twelve-query cap');
}

$p=syncFixture();syncMessage($p,1);syncMessage($p,2,heldChanges());
$gateway=new StatusSyncFixtureGateway($p,fn($id,$ref,$call)=>$call===1?['success'=>false,'error_code'=>'MESSAGE_NOT_FOUND','error'=>'private-provider-text']:statusSyncResponse('DELIVERED',$ref));
$r=LogicSmsStatusSync::run($p,$gateway);
syncCheck($r['missing']===1&&$r['applied']===0&&$r['attempted']===1&&$r['stopped_reason']==='MESSAGE_NOT_FOUND'&&$r['errors']===0,'First missing lookup stops the batch without turning it into failed delivery');
$cooldown=LogicSmsStatusSync::run($p,$gateway);
syncCheck($cooldown['global_cooldown']&&$cooldown['stopped_reason']==='GLOBAL_COOLDOWN'&&$cooldown['attempted']===0&&count($gateway->calls)===1,'Missing result opens a durable global five-minute cooldown across messages');
$p->now='2026-10-24 04:04:59';$cooldown=LogicSmsStatusSync::run($p,$gateway);
syncCheck($cooldown['global_cooldown']&&count($gateway->calls)===1,'Global cooldown remains closed for the whole five minutes');
$p->now='2026-10-24 04:05:00';$cooled=LogicSmsStatusSync::run($p,$gateway);
syncCheck(!$cooled['global_cooldown']&&$cooled['attempted']>0,'Global cooldown expires without an operator reset');
syncCheck(!str_contains((string)$p->query('SELECT GROUP_CONCAT(context_json) FROM operational_logs')->fetchColumn(),'private-provider-text'),'Audit never copies provider error text');
$p=syncFixture();syncMessage($p,2,heldChanges());$gateway=new StatusSyncFixtureGateway($p,fn($id,$ref)=>statusSyncResponse('DELIVERED',$ref));$r=LogicSmsStatusSync::run($p,$gateway);
syncCheck($r['applied']===1&&$r['attempted']===1,'Independently verified original reference can be reconciled');
$call=SmsStatusReconciliation::$calls[0];$e=$call['evidence'];
syncCheck($call['id']===2&&$e['source']==='provider_query'&&$e['original_reference']==='portal-message-2'&&$e['user_id']===2&&$e['campaign_id']===10&&$e['status']==='DELIVERED','Verified evidence is passed with its exact tenant, campaign and original reference');
syncCheck($p->locksAcquired===1&&$p->locksReleased===1&&!$p->ownsWorkerLock,'Worker lock is acquired after query and always released');

foreach(['AUTHENTICATION_ERROR','HTTP_ERROR','INVALID_RESPONSE','TRANSPORT_ERROR'] as $code){
    $p=syncFixture();syncMessage($p,1);syncMessage($p,2);
    $gateway=new StatusSyncFixtureGateway($p,fn()=>['success'=>false,'error_code'=>$code,'error'=>'password=private-do-not-log']);$r=LogicSmsStatusSync::run($p,$gateway);
    syncCheck($r['attempted']===1&&$r['errors']===1&&$r['stopped_reason']===$code&&count($gateway->calls)===1,'Authentication, HTTP/rate-limit, malformed and transport failures stop the batch');
    syncCheck((int)$p->query('SELECT dlr_attempt_count FROM sms_messages WHERE id=2')->fetchColumn()===0,'Stopping a batch does not claim unattempted work');
    syncCheck(!str_contains(json_encode($r),'private-do-not-log')&&!str_contains((string)$p->query('SELECT context_json FROM operational_logs')->fetchColumn(),'private-do-not-log'),'Failure output and audit omit raw transport/provider secrets');
    $again=LogicSmsStatusSync::run($p,$gateway);
    syncCheck($again['global_cooldown']&&$again['attempted']===0&&count($gateway->calls)===1,'Authentication, HTTP/rate-limit, malformed and transport errors open the same global circuit');
}
$p=syncFixture();syncMessage($p,1);$gateway=new StatusSyncFixtureGateway($p,fn()=>throw new RuntimeException('password=private exception'));$r=LogicSmsStatusSync::run($p,$gateway);
syncCheck($r['stopped_reason']==='TRANSPORT_ERROR'&&!str_contains(json_encode($r),'private'),'Thrown transport failures remain safe and bounded');

$p=syncFixture();syncMessage($p,1);$gateway=new StatusSyncFixtureGateway($p,fn()=>statusSyncResponse('DELIVERED','wrong-reference'));
$r=LogicSmsStatusSync::run($p,$gateway);syncCheck($r['stopped_reason']==='INVALID_RESPONSE'&&SmsStatusReconciliation::$calls===[],'A different resolved reference never becomes evidence for this message');
$p=syncFixture();syncMessage($p,1);$p->auditFails=true;$r=LogicSmsStatusSync::run($p,new StatusSyncFixtureGateway($p,fn($id,$ref)=>statusSyncResponse('DELIVERED',$ref)));
syncCheck($r['stopped_reason']==='AUDIT_UNAVAILABLE'&&SmsStatusReconciliation::$calls===[]&&$p->locksAcquired===0,'Missing durable lookup audit stops before applying evidence');
$p=syncFixture();syncMessage($p,1);$p->lockBusy=true;$r=LogicSmsStatusSync::run($p,new StatusSyncFixtureGateway($p,fn($id,$ref)=>statusSyncResponse('SENT',$ref)));
syncCheck($r['busy']===1&&$r['applied']===0&&SmsStatusReconciliation::$calls===[],'Busy worker singleton prevents settlement without waiting or resending');
$p=syncFixture();syncMessage($p,1);SmsStatusReconciliation::$callback=fn()=>throw new RuntimeException('Private accounting failure');
$r=LogicSmsStatusSync::run($p,new StatusSyncFixtureGateway($p,fn($id,$ref)=>statusSyncResponse('DELIVERED',$ref)));
syncCheck($r['stopped_reason']==='RECONCILIATION_UNAVAILABLE'&&!$p->ownsWorkerLock&&$p->locksReleased===1&&!str_contains(json_encode($r),'Private accounting'),'Reconciliation errors release the worker lock and expose no exception details');

foreach(['CREATE','CREATED','QUEUED','INVALID','ERROR','FAILED','REJECTED','UNKNOWN','UNAVAILABLE'] as $status){
    $p=syncFixture();syncMessage($p,1,heldChanges());$r=LogicSmsStatusSync::run($p,new StatusSyncFixtureGateway($p,fn($id,$ref)=>statusSyncResponse($status,$ref)));
    syncCheck($r['unavailable']===1&&$r['requires_investigation']===1&&SmsStatusReconciliation::$calls===[],'Held message needs acceptance evidence, not create/queue/unknown or generic lookup failure');
}
foreach(['SENT','SUBMITTED','ACCEPTED','DELIVERED','DELIVRD','DELIVERY_SUCCESS','SUCCESS','UNDELIVERED','UNDELIV','BLOCKED','EXPIRED'] as $status){
    $p=syncFixture();syncMessage($p,1,heldChanges());$r=LogicSmsStatusSync::run($p,new StatusSyncFixtureGateway($p,fn($id,$ref)=>statusSyncResponse($status,$ref)));
    syncCheck($r['applied']===1&&count(SmsStatusReconciliation::$calls)===1,'Verified accepted or final provider result reaches the reconciliation core');
}
foreach(['INVALID','ERROR','REJECTED'] as $status){
    $p=syncFixture();syncMessage($p,1);$r=LogicSmsStatusSync::run($p,new StatusSyncFixtureGateway($p,fn($id,$ref)=>statusSyncResponse($status,$ref)));
    syncCheck($r['unavailable']===1&&SmsStatusReconciliation::$calls===[],'Ambiguous lookup INVALID/ERROR/REJECTED cannot change even an already accepted message');
}

$p=syncFixture();syncMessage($p,1);$p->raceNextClaim=true;$gateway=new StatusSyncFixtureGateway($p);$r=LogicSmsStatusSync::run($p,$gateway);
syncCheck($r['selected']===1&&$r['attempted']===0&&$gateway->calls===[],'Concurrent poller claim prevents duplicate network lookup');
$p=syncFixture();syncMessage($p,1);syncMessage($p,2,['campaign_id'=>20,'user_id'=>9]);$gateway=new StatusSyncFixtureGateway($p);
$r=LogicSmsStatusSync::run($p,$gateway,10,null,[20,20]);syncCheck($r['attempted']===1&&$gateway->calls===[['','portal-message-2']]&&$r['scoped_campaign_ids']===[20],'Explicit campaign scopes cannot accidentally include other customers');
$r=LogicSmsStatusSync::run($p,$gateway,10,null,[]);syncCheck($r['selected']===0&&$r['attempted']===0,'Empty explicit scope is not a wildcard');
foreach([[0],[-1],['1 OR 1=1'],[1.5],[true],array_fill(0,101,1)] as $badScope){
    try{LogicSmsStatusSync::run($p,$gateway,10,null,$badScope);syncCheck(false,'Invalid scope must fail');}catch(InvalidArgumentException){syncCheck(true,'Campaign scope rejects malformed or excessive IDs');}
}
$r=LogicSmsStatusSync::run($p,$gateway,10,microtime(true)-1);syncCheck($r['deadline_reached']&&$r['attempted']===0,'Expired deadline never begins a provider request');
$p=syncFixture();for($id=1;$id<=25;$id++)syncMessage($p,$id);$gateway=new StatusSyncFixtureGateway($p);$r=LogicSmsStatusSync::run($p,$gateway,1000);
syncCheck($r['selected']===20&&$r['attempted']===20&&count($gateway->calls)===20,'Each run has a hard twenty-message maximum');
$p=syncFixture();syncMessage($p,1);$p->beginTransaction();try{LogicSmsStatusSync::run($p,new StatusSyncFixtureGateway($p));syncCheck(false,'Transaction must be rejected');}catch(DomainException){syncCheck(true,'No network under caller transaction');}finally{$p->rollBack();}
$p->ownsWorkerLock=true;try{LogicSmsStatusSync::run($p,new StatusSyncFixtureGateway($p));syncCheck(false,'Caller worker lock must be rejected');}catch(DomainException){syncCheck(true,'No network while caller owns dispatch worker lock');}$p->ownsWorkerLock=false;

function syncEvent(StatusSyncFixturePDO $p,int $id,string $reference,array|string $payload,array $changes=[]):void
{
    $row=array_replace(['id'=>$id,'provider'=>'logicsms','provider_event_id'=>'event-'.$id,'provider_message_id'=>$reference,'event_type'=>'delivery.delivered','payload_json'=>is_array($payload)?json_encode($payload):$payload,'processed_at'=>null],$changes);
    $q=$p->prepare('INSERT INTO sms_provider_events('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));
}
$p=syncFixture();syncMessage($p,1,heldChanges());syncEvent($p,101,'portal-message-1',['Unique'=>'portal-message-1','Status'=>'DELIVERED','Mobile'=>'private-number-not-forwarded','Message'=>'Private content not forwarded']);
$r=LogicSmsStatusSync::runStored($p);
syncCheck($r['processed']===1&&$r['applied']===1&&$r['provider_queries']===0&&!$r['sms_submitted'],'Stored authenticated callback settles held message without any provider request');
$e=SmsStatusReconciliation::$calls[0]['evidence'];
syncCheck($e['source']==='authenticated_callback'&&$e['source_event_id']==='event-101'&&$e['original_reference']==='portal-message-1'&&$e['status']==='DELIVERED'&&$e['campaign_id']===10&&$e['user_id']===2,'Stored callback retains exact original reference, event identity and tenant ownership');
syncCheck(!str_contains(json_encode($e),'Private content')&&!str_contains(json_encode($e),'private-number')&&strlen($e['payload_sha256'])===64,'Callback evidence fingerprints raw payload without forwarding phone or content');
syncCheck($p->query('SELECT processed_at FROM sms_provider_events WHERE id=101')->fetchColumn()===$p->now&&$p->locksAcquired===1&&$p->locksReleased===1,'Event is marked processed after verified acceptance with worker lock released');
$r=LogicSmsStatusSync::runStored($p);syncCheck($r['selected']===0&&count(SmsStatusReconciliation::$calls)===1,'Processed stored callback is not reapplied');

foreach([
    ['Unique'=>'portal-message-2','Status'=>'DELIVERED'],
    ['Unique'=>'portal-message-1','reference'=>'portal-message-2','Status'=>'DELIVERED'],
    ['Unique'=>'portal-message-1','Status'=>'DELIVERED','status'=>'SENT'],
    ['Unique'=>'','Status'=>'DELIVERED'],
    ['Status'=>'CREATE'],['Status'=>'INVALID'],['Status'=>'UNKNOWN'],['Status'=>'FAILED'],
    '{malformed-json}',
] as $payload){
    $p=syncFixture();syncMessage($p,1,heldChanges());syncEvent($p,101,'portal-message-1',$payload);$r=LogicSmsStatusSync::runStored($p);
    syncCheck($r['invalid']===1&&$r['processed']===0&&SmsStatusReconciliation::$calls===[]&&$p->query('SELECT processed_at FROM sms_provider_events')->fetchColumn()===null,'Conflicting, malformed or non-acceptance callback stays preserved and unprocessed');
}
$p=syncFixture();syncMessage($p,1,heldChanges());syncEvent($p,101,'9999',['Unique'=>'portal-message-1','Status'=>'DELIVERED']);$r=LogicSmsStatusSync::runStored($p);
syncCheck($r['selected']===0&&SmsStatusReconciliation::$calls===[],'Colliding numeric provider references are never guessed for held messages');
$p=syncFixture();syncMessage($p,1);syncEvent($p,101,'portal-message-1',['Status'=>'DELIVERED']);$r=LogicSmsStatusSync::runStored($p);
syncCheck($r['selected']===0,'Already accepted stored callbacks remain with the existing postback reconciler');
$p=syncFixture();syncMessage($p,1,array_replace(heldChanges(),['lease_token'=>'worker','leased_at'=>$p->now]));syncEvent($p,101,'portal-message-1',['Status'=>'DELIVERED']);$r=LogicSmsStatusSync::runStored($p);
syncCheck($r['selected']===0,'Stored callback does not bypass an active sending lease');
$p=syncFixture();syncMessage($p,1,array_replace(heldChanges(),['created_at'=>'2025-01-01 00:00:00']));syncEvent($p,101,'portal-message-1',['Status'=>'DELIVERED']);$r=LogicSmsStatusSync::runStored($p);
syncCheck($r['processed']===1,'Genuine stored callback has no arbitrary provider-query age cutoff');
$p=syncFixture();syncMessage($p,1,heldChanges());syncEvent($p,101,'portal-message-1',['Status'=>'DELIVERED']);$p->lockBusy=true;$r=LogicSmsStatusSync::runStored($p);
syncCheck($r['busy']===1&&$r['processed']===0&&$p->query('SELECT processed_at FROM sms_provider_events')->fetchColumn()===null,'Busy worker leaves original callback available for later safe reconciliation');
foreach([fn()=>throw new RuntimeException('Private failure'),fn()=>['ok'=>true,'applied'=>false,'accepted'=>false]] as $callback){
    $p=syncFixture();syncMessage($p,1,heldChanges());syncEvent($p,101,'portal-message-1',['Status'=>'DELIVERED']);SmsStatusReconciliation::$callback=$callback;
    $r=LogicSmsStatusSync::runStored($p);
    syncCheck($r['stopped_reason']==='RECONCILIATION_UNAVAILABLE'&&$r['processed']===0&&$p->query('SELECT processed_at FROM sms_provider_events')->fetchColumn()===null&&!$p->ownsWorkerLock,'Failed or unverified reconciliation never consumes stored callback evidence');
}
$p=syncFixture();syncMessage($p,1,heldChanges());syncEvent($p,101,'portal-message-1',['Status'=>'SENT']);SmsStatusReconciliation::$callback=fn()=>['ok'=>true,'applied'=>false,'duplicate'=>true,'accepted'=>true];$r=LogicSmsStatusSync::runStored($p);
syncCheck($r['duplicates']===1&&$r['processed']===1,'Verified idempotent core receipt permits completion of previously unprocessed callback');
$p=syncFixture();syncMessage($p,1,heldChanges());syncEvent($p,101,'portal-message-1',['Status'=>'SENT']);$r=LogicSmsStatusSync::runStored($p,20,microtime(true)-1);
syncCheck($r['deadline_reached']&&$r['selected']===0,'Stored-event work also respects the batch deadline');

echo "PASS {$checks} isolated status-sync checks; no provider, production database, SMS or financial calls\n";
