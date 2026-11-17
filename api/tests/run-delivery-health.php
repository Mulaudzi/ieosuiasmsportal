<?php
declare(strict_types=1);
require __DIR__.'/../services/SmsDeliveryHealth.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE sms_messages(provider TEXT,state TEXT,sent_at TEXT,created_at TEXT,last_dlr_checked_at TEXT);
CREATE TABLE sms_provider_events(provider TEXT,event_type TEXT,created_at TEXT,processed_at TEXT);
CREATE TABLE operational_logs(service TEXT,event TEXT,level TEXT,message TEXT,created_at TEXT);
CREATE TABLE wallets(balance REAL,reserved REAL);INSERT INTO wallets VALUES(100,5);");
$now=(new DateTimeImmutable('2026-11-05T11:00:00+02:00'))->getTimestamp();$n=0;
function deliveryHealthCheck(bool $ok,string $why):void{global$n;$n++;if(!$ok)throw new RuntimeException($why);}
$r=SmsDeliveryHealth::report($pdo,true,$now);
deliveryHealthCheck($r['status']==='healthy'&&$r['awaiting_confirmation']===0,'Empty account is healthy when callback authentication exists');
$pdo->exec("INSERT INTO sms_messages VALUES('logicsms','sent','2026-11-03 10:00:00','2026-11-03 10:00:00',NULL),('logicsms','sent','2026-11-05 10:00:00','2026-11-05 10:00:00','2026-11-05 10:30:00'),('logicsms','delivered','2026-11-05 10:00:00','2026-11-05 10:00:00',NULL),('other','sent','2026-11-01 10:00:00','2026-11-01 10:00:00',NULL)");
$r=SmsDeliveryHealth::report($pdo,true,$now);
deliveryHealthCheck($r['awaiting_confirmation']===2&&$r['not_yet_queried']===1&&$r['older_than_day']===1,'Only relevant nonterminal provider states are counted');
deliveryHealthCheck(in_array('NO_RECENT_DELIVERY_CALLBACKS',$r['problems'],true)&&in_array('DELIVERY_CONFIRMATIONS_OVERDUE',$r['problems'],true),'Missing callbacks and old pending reports are flagged without failing delivery');
$pdo->exec("INSERT INTO sms_provider_events VALUES('logicsms','delivery.delivered','2026-11-05 10:59:00',NULL),('logicsms','reply.opt_out','2026-11-05 11:00:00',NULL)");
$r=SmsDeliveryHealth::report($pdo,false,$now);
deliveryHealthCheck($r['last_callback_at']==='2026-11-05 10:59:00'&&$r['unprocessed_callbacks']===1,'Actual delivery events, not replies, determine callback health');
deliveryHealthCheck(!in_array('NO_RECENT_DELIVERY_CALLBACKS',$r['problems'],true)&&in_array('CALLBACK_AUTH_NOT_CONFIGURED',$r['problems'],true),'Missing authentication remains visible even with a recent event');
$pdo->exec("INSERT INTO operational_logs VALUES('sms-status-sync','status_lookup','warning','Read-only provider status query: MESSAGE_NOT_FOUND','2026-11-05 10:59:30')");
$r=SmsDeliveryHealth::report($pdo,true,$now);
deliveryHealthCheck($r['missing_lookups_last_day']===1&&!$r['gateway_cooldown'],'Per-message misses do not falsely show a global cooldown');
$pdo->exec("INSERT INTO operational_logs VALUES('sms-status-sync','status_lookup','warning','Read-only provider status query: TRANSPORT_ERROR','2026-11-05 10:59:30')");
$r=SmsDeliveryHealth::report($pdo,true,$now);
deliveryHealthCheck($r['gateway_cooldown']&&in_array('STATUS_GATEWAY_COOLDOWN',$r['problems'],true),'Real gateway outages are visible');
deliveryHealthCheck($pdo->query('SELECT balance,reserved FROM wallets')->fetch()===['balance'=>100.0,'reserved'=>5.0],'Diagnostics do not change financial records');
deliveryHealthCheck(!str_contains(json_encode($r),'reference')&&!str_contains(json_encode($r),'secret'),'Output contains neither message identifiers nor callback credentials');
class DeliveryHealthAccessDenied extends RuntimeException{}
class Auth{public static ?array $viewer=null;public static function user():?array{return self::$viewer;}}
class Response{public static function error(string $message,int $status):never{throw new DeliveryHealthAccessDenied('denied',$status);}}
function db():never{throw new RuntimeException('Unauthorized viewers must not reach any database query');}
require __DIR__.'/../controllers/AdminOperationsController.php';
foreach([null,['role'=>'user']]as$viewer){
    Auth::$viewer=$viewer;
    try{(new AdminOperationsController())->services();deliveryHealthCheck(false,'Private diagnostics leaked');}
    catch(DeliveryHealthAccessDenied $e){deliveryHealthCheck($e->getCode()===403,'Actual service endpoint rejects anonymous and non-admin viewers before querying');}
}
$worker=file_get_contents(__DIR__.'/../bin/sms-dlr-poller.php');
$start=strpos($worker,'$processed=$checked+');$end=strpos($worker,'OperationalLogger::finishRun($runId,$totalFailures',$start);
if($start===false||$end===false)throw new RuntimeException('Worker accounting block missing');
$checked=0;$failures=0;$updated=0;$heldReceipts=['processed'=>1,'errors'=>0,'applied'=>1];$statusQueries=['attempted'=>2,'errors'=>1,'applied'=>1];
eval(substr($worker,$start,$end-$start));
deliveryHealthCheck($processed===3&&$totalFailures===1&&$totalUpdated===2,'Actual worker run totals include status queries and stored receipt reconciliation');
$checked=0;$failures=0;$updated=0;$heldReceipts=['invalid'=>1];$statusQueries=['attempted'=>2,'missing'=>1,'unavailable'=>1];
eval(substr($worker,$start,$end-$start));
deliveryHealthCheck($processed===2&&$totalFailures===3,'Missing provider records, unavailable results and invalid receipts are visible as job failures rather than false success');
echo "PASS: $n admin delivery-health read-model checks; synthetic data only.\n";
