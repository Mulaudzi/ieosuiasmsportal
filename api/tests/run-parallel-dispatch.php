<?php
declare(strict_types=1);

// Real dispatch/ledger and cURL multi against a loopback-only fixture. No .env,
// production DB, gateway credentials, provider traffic or real SMS are used.
if(PHP_SAPI!=='cli')exit(1);
$base=$argv[1]??'';
if(!preg_match('#^http://127\.0\.0\.1:[0-9]+$#D',$base))throw new RuntimeException('Loopback fixture URL required');
function env(string $key,mixed $default=null):mixed { return $GLOBALS['parallel_environment'][$key]??$default; }
class ParallelFixture extends PDO {
    public function query(string $sql,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        $q=$this->prepare($sql);$q->execute();if($fetchMode!==null)$q->setFetchMode($fetchMode,...$args);return $q;
    }
    public function prepare(string $sql,array $options=[]):PDOStatement|false {
        $sql=str_replace([' FOR UPDATE SKIP LOCKED',' FOR UPDATE',"LEFT(COALESCE(last_error,''),180)"],['','','SUBSTR(COALESCE(last_error,\'\'),1,180)'],$sql);
        $sql=preg_replace('/DATE_ADD\(NOW\(\),INTERVAL (\d+) SECOND\)/',"datetime(NOW(), '+$1 seconds')",$sql);
        $sql=preg_replace('/DATE_SUB\(NOW\(\), INTERVAL (\d+) SECOND\)/',"datetime(NOW(), '-$1 seconds')",$sql);
        // Match MariaDB DECIMAL(14,4) assignment rounding, absent from SQLite REAL.
        $sql=preg_replace('/balance\s*=\s*balance\s*-\s*\?/','balance=ROUND(balance-?,4)',$sql);
        $sql=preg_replace('/reserved\s*=\s*GREATEST\(0,\s*reserved\s*-\s*\?\)/','reserved=ROUND(GREATEST(0,reserved-?),4)',$sql);
        return parent::prepare($sql,$options);
    }
}
function db():PDO { return $GLOBALS['pdo']; }
require __DIR__.'/../core/QueryBuilder.php';
require __DIR__.'/../domain/SmsWorkerRunner.php';

$checks=0;
function verify(bool $ok,string $label):void { global $checks;if(!$ok)throw new RuntimeException($label);$checks++; }
function row(int $id):array {$q=db()->prepare('SELECT * FROM sms_messages WHERE id=?');$q->execute([$id]);return $q->fetch();}
function wallet(int $user=1):array {$q=db()->prepare('SELECT balance,reserved FROM wallets WHERE user_id=?');$q->execute([$user]);return $q->fetch();}
function countLedger():int {return (int)db()->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn();}
function fixture(int $count):array {
    $pdo=new ParallelFixture('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('IF',fn($c,$a,$b)=>$c?$a:$b,3);$pdo->sqliteCreateFunction('GREATEST',fn(...$values)=>max($values));$pdo->sqliteCreateFunction('CONCAT',fn(...$values)=>implode('',$values));
    $pdo->exec("CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,state TEXT,sender_id TEXT,queued_count INTEGER,processing_count INTEGER,sent_count INTEGER,delivered_count INTEGER,failed_count INTEGER,skipped_count INTEGER,actual_cost REAL,completed_at TEXT,started_at TEXT,updated_at TEXT);
    CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,campaign_id INTEGER,user_id INTEGER,destination TEXT,content TEXT,state TEXT,provider TEXT,provider_message_id TEXT,sent_at TEXT,delivered_at TEXT,failed_at TEXT,last_error TEXT,actual_charge REAL DEFAULT 0,estimated_charge REAL,segment_count INTEGER,attempt_count INTEGER DEFAULT 1,next_attempt_at TEXT,lease_token TEXT,leased_at TEXT,retry_generation INTEGER DEFAULT 0,created_at TEXT,updated_at TEXT,UNIQUE(provider,provider_message_id));
    CREATE TABLE opt_outs(user_id INTEGER,channel TEXT,recipient TEXT);
    CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER,balance REAL,reserved REAL,updated_at TEXT);
    CREATE TABLE wallet_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,wallet_id INTEGER,campaign_id INTEGER,payment_id INTEGER,operation TEXT,direction TEXT,amount REAL,idempotency_key TEXT UNIQUE,created_at TEXT);
    CREATE TABLE sms_provider_events(id INTEGER PRIMARY KEY,provider TEXT,provider_event_id TEXT,provider_message_id TEXT,event_type TEXT,payload_json TEXT,processed_at TEXT,created_at TEXT);
    INSERT INTO sms_campaigns(id,user_id,state,sender_id)VALUES(1,1,'processing','IEOSUIA'),(2,2,'completed','IEOSUIA');
    INSERT INTO wallets VALUES(1,1,20,0,NULL),(2,2,50,3.5,NULL);");
    $pdo->prepare('UPDATE wallets SET reserved=? WHERE user_id=1')->execute([round($count*.35,4)]);
    $q=$pdo->prepare("INSERT INTO sms_messages(id,campaign_id,user_id,destination,content,state,estimated_charge,segment_count,lease_token,leased_at,created_at)VALUES(?,1,1,?,'Synthetic fixture','processing',.35,1,'batch',NOW(),NOW())");
    for($i=1;$i<=$count;$i++)$q->execute([$i,'+2782000'.str_pad((string)$i,4,'0',STR_PAD_LEFT)]);
    $GLOBALS['pdo']=$pdo;return $pdo->query('SELECT * FROM sms_messages ORDER BY id')->fetchAll();
}
final class LoopbackProvider implements ConcurrentSmsProvider {
    public int $created=0;
    public array $completed=[];
    public string $run;
    public function __construct(private string $base,private string $mode='normal',private int $timeout=2000,private int $prepareDelay=0){$this->run=bin2hex(random_bytes(8));}
    public function name():string{return 'fixture';}
    public function send(string $destination,string $content,?string $senderId=null,?string $clientReference=null):array{throw new RuntimeException('Concurrent fixture must not use synchronous send');}
    public function createSubmissionHandle(array $message):CurlHandle|array {
        verify(row((int)$message['id'])['next_attempt_at']==='9999-12-31 23:59:59','Durable no-resend intent must precede every handle');
        verify(!db()->inTransaction(),'No settlement transaction may span network IO');
        $this->created++;
        if($this->prepareDelay)usleep($this->prepareDelay);
        if($this->mode==='preflight'&&(int)$message['id']===2)return ['success'=>false,'retryable'=>false,'error'=>'Fixture pre-network rejection'];
        $ch=curl_init($this->base.'/send?'.http_build_query(['run'=>$this->run,'mode'=>$this->mode]));
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['Unique'=>'portal-message-'.$message['id'],'DCheck'=>'Y','mobile'=>$message['destination'],'message'=>$message['content'],'Originator'=>$message['sender_id']]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT_MS=>$this->timeout,CURLOPT_CONNECTTIMEOUT_MS=>1000,CURLOPT_FOLLOWLOCATION=>false]);
        return $ch;
    }
    public function parseSubmissionResponse(string $body,int $curlResult,int $httpStatus):array {
        $result=SmsService::parseResponse($body,$curlResult===CURLE_OK?'':curl_strerror($curlResult),$httpStatus);
        $this->completed[]=$result['message_id']??null;
        unset($result['diagnostic']); // Parser diagnostics have a separate fixture suite.
        return $result;
    }
    public function stats():array {
        $ch=curl_init($this->base.'/stats?run='.$this->run);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3]);
        $body=curl_exec($ch);curl_close($ch);return json_decode((string)$body,true,32,JSON_THROW_ON_ERROR);
    }
}

verify(SmsWorkerRunner::boundedConcurrency(0)===1&&SmsWorkerRunner::boundedConcurrency(5)===5&&SmsWorkerRunner::boundedConcurrency(99)===10,'Concurrency is bounded and supports sequential rollback');
verify(SmsWorkerRunner::startDeadline(true,100,15)===83.0&&SmsWorkerRunner::startDeadline(false,100,15)===null,'CLI budget leaves timeout and cleanup headroom');
$GLOBALS['parallel_environment']=['LOGICSMS_API_URL'=>'https://gateway.example.invalid/postmsg2.aspx','LOGICSMS_USERNAME'=>'fixture-user','LOGICSMS_PASSWORD'=>'fixture-password'];
$handle=(new LogicSmsProvider())->createSubmissionHandle(['id'=>42,'destination'=>'+27820000001','content'=>'Synthetic fixture','sender_id'=>'IEOSUIA']);
verify($handle instanceof CurlHandle&&curl_getinfo($handle,CURLINFO_EFFECTIVE_URL)==='https://gateway.example.invalid/postmsg2.aspx','Production adapter constructs an unexecuted HTTPS handle');curl_close($handle);
$GLOBALS['parallel_environment']['LOGICSMS_API_URL']='http://127.0.0.1:1';
verify(is_array((new LogicSmsProvider())->createSubmissionHandle(['id'=>42,'destination'=>'+27820000001','content'=>'Fixture'])),'Production adapter rejects cleartext transport before network');
$GLOBALS['parallel_environment']=[];
verify(is_array((new LogicSmsProvider())->createSubmissionHandle(['id'=>42,'destination'=>'+27820000001','content'=>'Fixture'])),'Missing credentials return known pre-network failure');

$messages=fixture(10);$other=wallet(2);$provider=new LoopbackProvider($base);
$start=microtime(true);$serial=(new SmsWorkerRunner($provider,1,100))->run($messages);$serialSeconds=microtime(true)-$start;
verify($serial['processed']===10&&$serial['failed']===0&&$serial['peak_in_flight']===1,'Concurrency one settles all recipients');
verify($provider->stats()['peak']===1,'Fixture confirms sequential network traffic');

$messages=fixture(10);$provider=new LoopbackProvider($base);$heartbeats=0;
$start=microtime(true);$runner=new SmsWorkerRunner($provider,5,100);$parallel=$runner->run($messages,function(array $metrics)use(&$heartbeats){$heartbeats++;SmsDispatchService::renewLease('batch');});$parallelSeconds=microtime(true)-$start;$remote=$provider->stats();
verify($parallel['processed']===10&&$parallel['failed']===0&&$parallel['peak_in_flight']===5,'Five concurrent requests all finalize independently');
verify($remote['peak']===5&&count($remote['requests'])===10,'Server confirms exactly five overlapping and ten total requests');
verify($parallelSeconds<$serialSeconds*.65,'Actual loopback concurrency materially improves identical delayed requests');
verify($heartbeats>0,'Pool emits heartbeat/lease-renewal callbacks');
verify(abs((float)wallet()['balance']-16.5)<.00001&&(float)wallet()['reserved']===0.0&&countLedger()===10,'One charge and settlement per recipient, reservation exhausted exactly');
verify(wallet(2)===$other,'Another customer wallet is untouched');
foreach($remote['requests'] as $request)verify($request['unique']==='portal-message-'.$request['id']&&$request['dcheck']==='Y','Stable original reference and duplicate flag reach transport');
foreach($messages as $message)SmsDispatchService::process($message,$provider);
verify($provider->created===10&&countLedger()===10,'Repeated dispatch cannot resend or recharge accepted rows');

$messages=fixture(12);$provider=new LoopbackProvider($base);$result=(new SmsWorkerRunner($provider,10,100))->run($messages);
verify($result['processed']===12&&$provider->stats()['peak']===10,'Configurable ten-request ceiling is enforced');

$messages=fixture(2);$provider=new LoopbackProvider($base,'fast');$runner=new SmsWorkerRunner($provider,5,5);
$runner->run([$messages[0]]);$runner->run([$messages[1]]);$requests=$provider->stats()['requests'];
verify($requests[1]['started']-$requests[0]['started']>=.18,'Request start-rate spacing survives batch boundaries');

$messages=fixture(3);$provider=new LoopbackProvider($base,'out-of-order');$result=(new SmsWorkerRunner($provider,5,100))->run($messages);
verify($provider->completed===['100002','100003','100001'],'Responses may arrive out of submission order');
verify(row(1)['provider_message_id']==='100001'&&row(2)['provider_message_id']==='100002'&&row(3)['provider_message_id']==='100003'&&countLedger()===3,'Out-of-order results retain correct message and settlement mapping');

$messages=fixture(2);$provider=new LoopbackProvider($base,'heartbeat');$heartbeats=0;
$result=(new SmsWorkerRunner($provider,5,100,microtime(true)+.1))->run($messages,function(array $metrics)use(&$heartbeats){$heartbeats++;SmsDispatchService::renewLease('batch');});
verify($result['processed']===2&&$heartbeats>=2,'Heartbeat and lease renewal continue while draining slow in-flight requests');

$messages=fixture(3);$provider=new LoopbackProvider($base,'preflight');$result=(new SmsWorkerRunner($provider,5,100))->run($messages);
verify($result['requests_started']===2&&$result['processed']===3&&row(2)['state']==='failed','Known pre-network failure finalizes without an HTTP request');
verify((float)wallet()['reserved']===0.0&&abs((float)wallet()['balance']-19.3)<.00001,'Preflight failure releases its own reservation without charging');

$messages=fixture(3);db()->exec("INSERT INTO opt_outs VALUES(1,'sms','+27820000002');UPDATE sms_messages SET campaign_id=3 WHERE id=3;INSERT INTO sms_campaigns(id,user_id,state)VALUES(3,1,'cancelled')");
$messages[2]=row(3);
$provider=new LoopbackProvider($base);$result=(new SmsWorkerRunner($provider,5,100))->run($messages);
verify($result['requests_started']===1&&row(2)['state']==='skipped'&&row(3)['state']==='skipped','Opt-out and cancellation are checked before HTTP');
verify(countLedger()===3&&(float)wallet()['reserved']===0.0&&abs((float)wallet()['balance']-19.65)<.00001,'Skipped recipients release, not spend, their reservation');

foreach(['html','timeout','oversize','reject'] as $mode){
    $messages=fixture(3);$provider=new LoopbackProvider($base,$mode,$mode==='timeout'?150:2000);
    $result=(new SmsWorkerRunner($provider,5,100))->run($messages);
    verify($result['processed']===3&&$result['failed']===0,'Transport/rejection outcomes are handled per message: '.$mode);
    verify(row(1)['state']==='sent'&&row(3)['state']==='sent','Other recipients settle despite peer outcome: '.$mode);
    if($mode==='reject')verify(row(2)['state']==='failed'&&(float)wallet()['reserved']===0.0,'Explicit nonacceptance releases only its own reservation');
    else{
        verify(row(2)['state']==='retry'&&row(2)['next_attempt_at']==='9999-12-31 23:59:59','Uncertain result remains held: '.$mode);
        verify(abs((float)wallet()['reserved']-.35)<.00001&&(float)row(2)['actual_charge']===0.0,'Uncertain result retains unspent reservation: '.$mode);
        SmsDispatchService::releaseLease('batch');
        verify(row(2)['next_attempt_at']==='9999-12-31 23:59:59','Cleanup cannot release an uncertain resend hold: '.$mode);
    }
    verify(abs((float)wallet()['balance']-19.3)<.00001,'Only two accepted messages are charged: '.$mode);
}

$messages=fixture(9);$provider=new LoopbackProvider($base);$runner=new SmsWorkerRunner($provider,2,100,microtime(true)+.12);
$result=$runner->run($messages);SmsDispatchService::releaseLease('batch');
verify($result['requests_started']===2&&$result['processed']===2&&$result['unstarted']===7&&$result['deadline_reached'],'Cutoff drains in-flight requests without starting a new wave');
verify(row(3)['state']==='retry'&&row(3)['next_attempt_at']!=='9999-12-31 23:59:59'&&(int)row(3)['attempt_count']===0,'Unattempted claims are safely released, with no artificial attempt');
$again=$runner->run($messages);verify($runner->shouldStop()&&$again['requests_started']===0&&$provider->created===2,'Expired runner cannot reclaim/restart batches');
verify(abs((float)wallet()['reserved']-2.45)<.00001&&countLedger()===2,'Deadline does not spend or lose queued reservations');

$messages=fixture(1);$provider=new LoopbackProvider($base);$result=(new SmsWorkerRunner($provider,5,10,microtime(true)-1))->run($messages);
verify($provider->created===0&&$result['unstarted']===1&&row(1)['next_attempt_at']===null,'Already-expired budget performs no submission preparation');

$messages=fixture(1);$provider=new LoopbackProvider($base,'normal',2000,100000);
$result=(new SmsWorkerRunner($provider,5,100,microtime(true)+.04))->run($messages);
verify($result['requests_started']===0&&$result['deferred_before_start']===1&&row(1)['state']==='retry'&&(int)row(1)['attempt_count']===0,'Budget expiring during preparation clears only an unstarted fresh intent');
verify(countLedger()===0&&abs((float)wallet()['reserved']-.35)<.00001,'Unstarted deadline deferral keeps credits reserved');

$messages=fixture(9);$other=wallet(2);db()->exec("INSERT INTO sms_messages(id,campaign_id,user_id,state,provider,provider_message_id,sent_at)VALUES(999,2,2,'sent','fixture','100001',NOW())");
$provider=new LoopbackProvider($base,'settlement-failure');$runner=new SmsWorkerRunner($provider,5,100);$result=$runner->run($messages);SmsDispatchService::releaseLease('batch');
verify($result['failed']===1&&$result['requests_started']===5&&$result['processed']===4&&$runner->shouldStop(),'Settlement failure stops refill but drains four in-flight peer results');
verify(row(1)['state']==='retry'&&row(1)['next_attempt_at']==='9999-12-31 23:59:59'&&str_contains(row(1)['last_error'],'100001'),'Accepted provider ID survives settlement rollback and remains held');
verify(countLedger()===4&&abs((float)wallet()['balance']-18.6)<.00001&&abs((float)wallet()['reserved']-1.75)<.00001,'Failed settlement rolls back its debit while all peers settle once');
verify(wallet(2)===$other&&row(999)['provider_message_id']==='100001','Existing provider reference and other customer data are preserved');

$messages=fixture(1);$prepared=SmsDispatchService::prepareSubmission($messages[0]);
verify($prepared!==null&&SmsDispatchService::prepareSubmission($messages[0])===null,'An existing in-flight intent cannot be prepared twice');
db()->exec("UPDATE sms_messages SET last_error='Provider accepted reference 123; awaiting local settlement' WHERE id=1");
SmsDispatchService::releaseUnstartedSubmission($prepared);
verify(row(1)['state']==='processing'&&row(1)['next_attempt_at']==='9999-12-31 23:59:59','Unstarted helper cannot clear recorded acknowledgement evidence');

final class SynchronousFixtureProvider implements SmsProvider {
    public int $calls=0;
    public function name():string{return 'fixture';}
    public function send(string $destination,string $content,?string $senderId=null,?string $clientReference=null):array{
        $id=(int)substr((string)$clientReference,strlen('portal-message-'));$this->calls++;
        verify(row($id)['next_attempt_at']==='9999-12-31 23:59:59'&&!db()->inTransaction(),'Synchronous fallback preserves intent and transaction boundaries');
        return ['success'=>true,'message_id'=>(string)(200000+$id)];
    }
}
$messages=fixture(3);$provider=new SynchronousFixtureProvider();$result=(new SmsWorkerRunner($provider,5,100))->run($messages);
verify($provider->calls===3&&$result['peak_in_flight']===1&&$result['processed']===3&&countLedger()===3,'Providers without concurrent capability retain the synchronous contract');

$source=file_get_contents(__DIR__.'/../bin/sms-worker.php');
verify(strpos($source,'if($runner->shouldStop())break;')<strpos($source,'$messages=SmsDispatchService::claimBatch('),'Worker exits at cutoff before claiming another batch');
verify(str_contains($source,"SELECT GET_LOCK(?,0)")&&str_contains($source,'$runner=new SmsWorkerRunner'),'Parallel worker retains singleton dispatch coordination');

echo json_encode(['passed'=>true,'checks'=>$checks,'sequential_seconds'=>round($serialSeconds,3),'parallel_five_seconds'=>round($parallelSeconds,3),'speedup'=>round($serialSeconds/$parallelSeconds,2),'fixture_only'=>true,'real_sms_sent'=>false,'production_access'=>false],JSON_PRETTY_PRINT).PHP_EOL;
