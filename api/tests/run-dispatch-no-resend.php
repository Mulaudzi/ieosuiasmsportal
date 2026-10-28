<?php
// Real dispatcher and wallet with isolated SQLite/MySQL-expression adapter. No transport.
function env(string $key,mixed $default=null):mixed{return $default;}
class DispatchFixture extends PDO {
 public function query(string $sql,?int $fetchMode=null,mixed ...$args):PDOStatement|false {$q=$this->prepare($sql);$q->execute();if($fetchMode!==null)$q->setFetchMode($fetchMode,...$args);return $q;}
 public function prepare(string $sql,array $options=[]):PDOStatement|false {
  $sql=str_replace('TIMESTAMPDIFF(SECOND,m.created_at,NOW())',"CAST((julianday(NOW())-julianday(m.created_at))*86400 AS INTEGER)",$sql);
  $sql=str_replace([' FOR UPDATE SKIP LOCKED',' FOR UPDATE','LEFT(COALESCE(last_error,\'\'),180)'],['','','SUBSTR(COALESCE(last_error,\'\'),1,180)'],$sql);
  $sql=preg_replace('/DATE_ADD\(NOW\(\),INTERVAL (\d+) SECOND\)/',"datetime(NOW(), '+$1 seconds')",$sql);
  $sql=preg_replace('/DATE_SUB\(NOW\(\), INTERVAL (\d+) SECOND\)/',"datetime(NOW(), '-$1 seconds')",$sql);
  return parent::prepare($sql,$options);
 }
 public function exec(string $sql):int|false {$sql=str_replace("LEFT(COALESCE(last_error,''),180)","SUBSTR(COALESCE(last_error,''),1,180)",$sql);$sql=preg_replace('/DATE_SUB\(NOW\(\), INTERVAL (\d+) SECOND\)/',"datetime(NOW(), '-$1 seconds')",$sql);return parent::exec($sql);}
}
$pdo=new DispatchFixture('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('NOW',fn()=>'2026-10-23 12:00:00');$pdo->sqliteCreateFunction('IF',fn($c,$a,$b)=>$c?$a:$b,3);$pdo->sqliteCreateFunction('GREATEST',fn(...$v)=>max($v));$pdo->sqliteCreateFunction('CONCAT',fn(...$v)=>implode('',$v));
function db():PDO{global $pdo;return $pdo;}
require __DIR__.'/../core/QueryBuilder.php';require __DIR__.'/../domain/SmsDispatchService.php';
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);INSERT INTO users VALUES(1,1);CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER DEFAULT 1,state TEXT,sender_id TEXT,queued_count INTEGER,processing_count INTEGER,sent_count INTEGER,delivered_count INTEGER,failed_count INTEGER,skipped_count INTEGER,actual_cost REAL,completed_at TEXT,started_at TEXT,updated_at TEXT);INSERT INTO sms_campaigns(id,state) VALUES(23,'processing');CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,campaign_id INTEGER,user_id INTEGER,destination TEXT,content TEXT,state TEXT,provider TEXT,provider_message_id TEXT,sent_at TEXT,delivered_at TEXT,failed_at TEXT,last_error TEXT,actual_charge REAL DEFAULT 0,estimated_charge REAL,segment_count INTEGER,attempt_count INTEGER DEFAULT 1,next_attempt_at TEXT,lease_token TEXT,leased_at TEXT,retry_generation INTEGER DEFAULT 0,updated_at TEXT,UNIQUE(provider,provider_message_id));CREATE TABLE opt_outs(user_id INTEGER,channel TEXT,recipient TEXT);CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER,balance REAL,reserved REAL,updated_at TEXT);INSERT INTO wallets VALUES(1,1,10,1.40,NULL);CREATE TABLE wallet_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,wallet_id INTEGER,campaign_id INTEGER,payment_id INTEGER,operation TEXT,direction TEXT,amount REAL,idempotency_key TEXT UNIQUE,created_at TEXT)");
class FixtureProvider implements SmsProvider {public int $calls=0;public array $result=['success'=>true,'message_id'=>'123'];public function name():string{return 'fixture';}public function send(string $destination,string $content,?string $senderId=null,?string $clientReference=null):array{$this->calls++;global $pdo;if($pdo->query('SELECT next_attempt_at FROM sms_messages WHERE id=654')->fetchColumn()!=='9999-12-31 23:59:59')throw new RuntimeException('No durable intent before sending');return $this->result;}}
$provider=new FixtureProvider();$checks=0;
function assertDispatch(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function message():array{return db()->query('SELECT * FROM sms_messages WHERE id=654')->fetch();}
$pdo->exec("INSERT INTO sms_messages(id,campaign_id,user_id,state,provider,provider_message_id,sent_at) VALUES(1,23,1,'sent','fixture','123','2026-10-23 10:00:00');INSERT INTO sms_messages(id,campaign_id,user_id,destination,content,state,estimated_charge,segment_count,lease_token,leased_at) VALUES(654,23,1,'+27820000001','Fixture','processing',0.35,1,'lease','2026-10-23 12:00:00')");
$wallet=$pdo->query('SELECT * FROM wallets')->fetchAll();
try{SmsDispatchService::process(message(),$provider);throw new RuntimeException('Duplicate provider ID unexpectedly settled');}catch(PDOException){$checks++;SmsDispatchService::releaseMessageLease(654,'lease','Database duplicate provider reference');}
assertDispatch($provider->calls===1 && message()['state']==='retry' && message()['next_attempt_at']==='9999-12-31 23:59:59','Post-provider failure is held and cannot enter automatic retry');
assertDispatch(str_contains(message()['last_error'],'123'),'Provider acknowledgement survives settlement rollback');
assertDispatch($wallet===$pdo->query('SELECT * FROM wallets')->fetchAll() && (int)$pdo->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn()===0,'Settlement failure preserves reservation and has no wallet debit');
SmsDispatchService::releaseLease('lease');assertDispatch(message()['next_attempt_at']==='9999-12-31 23:59:59','Shutdown cleanup preserves held outcome');
$pdo->exec("UPDATE sms_messages SET state='processing',lease_token='stale',leased_at='2026-10-23 10:00:00' WHERE id=654");
assertDispatch(SmsDispatchService::claimBatch(1,'next')===[] && message()['next_attempt_at']==='9999-12-31 23:59:59','Stale recovery never requeues a possibly accepted send');
$pdo->exec("UPDATE sms_messages SET state='processing',lease_token='lease',leased_at='2026-10-23 12:00:00',next_attempt_at=NULL WHERE id=654");$provider->result=['success'=>false,'uncertain'=>true];
SmsDispatchService::process(message(),$provider);assertDispatch(message()['next_attempt_at']==='9999-12-31 23:59:59','Unverified provider response is held without charging or resending');
$pdo->exec("UPDATE sms_messages SET state='processing',lease_token='before',next_attempt_at=NULL,attempt_count=3 WHERE id=654");SmsDispatchService::releaseMessageLease(654,'before','Pre-provider exception');
assertDispatch(message()['next_attempt_at']==='9999-12-31 23:59:59','Repeated pre-provider exceptions are bounded, not an endless retry loop');
class Auth {public static function id():int{return 1;}}
class RateLimiter {public static function checkOrFail(...$args):void{}}
class DispatchHttpResult extends Exception {public function __construct(public int $status){}}
class Response {public static function error(string $message,int $status=400):void{throw new DispatchHttpResult($status);}}
require __DIR__.'/../controllers/SmsController.php';
try {(new SmsController())->retry(['id'=>23]);throw new RuntimeException('Manual retry released a hold');}catch(DispatchHttpResult $e){assertDispatch($e->status===409 && message()['next_attempt_at']==='9999-12-31 23:59:59','Customer Retry cannot bypass reconciliation hold');}
$pdo->exec("UPDATE sms_messages SET state='processing',lease_token='lease',next_attempt_at=NULL WHERE id=654");$provider->result=['success'=>true,'message_id'=>'456'];
SmsDispatchService::process(message(),$provider);$calls=$provider->calls;
assertDispatch(message()['state']==='sent' && message()['provider_message_id']==='456' && (float)message()['actual_charge']===.35,'Valid unique acknowledgement settles once');
SmsDispatchService::process(message(),$provider);assertDispatch($provider->calls===$calls,'Repeated process invocation cannot resend an accepted message');
echo "PASS: $checks no-resend crash/duplicate/timeout checks; no SMS sent.\n";
