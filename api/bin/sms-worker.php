<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../core/QueryBuilder.php';
require_once __DIR__.'/../domain/SmsDispatchService.php';
require_once __DIR__.'/../domain/SmsWorkerRunner.php';
require_once __DIR__.'/../services/OperationalLogger.php';

$once=in_array('--once',$argv,true);
$batch=max(1,min(100,(int)(getenv('SMS_WORKER_BATCH_SIZE')?:50)));
// Cron invocations drain several small batches instead of stopping after 50 messages.
// Keep the global lock. Only HTTP requests overlap; settlements stay serialized.
$runUntil=microtime(true)+max(10,min(55,(int)(getenv('SMS_WORKER_RUN_SECONDS')?:55)));
if($once)$batch=min($batch,10);
$rate=max(1,(int)(getenv('SMS_PROVIDER_RATE_PER_SECOND')?:10));
$concurrency=SmsWorkerRunner::boundedConcurrency((int)(getenv('SMS_WORKER_CONCURRENCY')?:5));
$provider=ProviderFactory::make();
$runner=new SmsWorkerRunner($provider,$concurrency,$rate,SmsWorkerRunner::startDeadline($once,$runUntil,SmsService::submissionTimeoutSeconds()));
$instance=gethostname().':'.getmypid();
$workerLock='ieosuia-sms-dispatch-worker';
$lockStatement=db()->prepare('SELECT GET_LOCK(?,0)');
$lockStatement->execute([$workerLock]);
if((int)$lockStatement->fetchColumn()!==1){echo "worker already running\n";exit(0);}
$activeLease=null;
register_shutdown_function(static function()use(&$activeLease,$workerLock):void{
    try{
        if($activeLease!==null)SmsDispatchService::releaseLease($activeLease);
        $release=db()->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$workerLock]);
    }catch(Throwable $e){error_log('SMS worker shutdown cleanup failed: '.$e->getMessage());}
});

do {
    // The deadline used to break only the inner loop, repeatedly reclaiming the
    // same rows. Exit before logging/claiming when no safe request headroom remains.
    if($runner->shouldStop())break;
    $runId=OperationalLogger::startRun('sms-worker',$instance,['batch_size'=>$batch,'provider'=>$provider->name(),'concurrency'=>$concurrency]);
    $heartbeat=db()->prepare("INSERT INTO service_heartbeats(service_name,instance_id,metadata_json,last_seen_at) VALUES('sms-worker',?,?,NOW()) ON DUPLICATE KEY UPDATE instance_id=VALUES(instance_id),metadata_json=VALUES(metadata_json),last_seen_at=NOW()");
    $heartbeat->execute([$instance,json_encode(['provider'=>$provider->name(),'rate_per_second'=>$rate,'concurrency'=>$concurrency,'in_flight'=>0])]);
    $lease=sprintf('%s-%s',getmypid(),bin2hex(random_bytes(12)));
    $activeLease=$lease;
    $messages=SmsDispatchService::claimBatch($batch,$lease);
    try{
        $result=$runner->run($messages,static function(array $metrics)use($heartbeat,$instance,$provider,$rate,$lease):void{
            SmsDispatchService::renewLease($lease);
            $heartbeat->execute([$instance,json_encode(['provider'=>$provider->name(),'rate_per_second'=>$rate]+$metrics)]);
        });
    }catch(Throwable $e){
        OperationalLogger::log('error','sms-worker','pool_interrupted','Concurrent dispatch interrupted; submission holds retained',['error_type'=>get_class($e)]);
        OperationalLogger::finishRun($runId,'failed',0,1,'Transport interrupted; inspect held submissions before any resend');
        throw $e; // Shutdown releases unattempted claims and retains uncertain holds.
    }
    SmsDispatchService::releaseLease($lease,'Worker could not complete this message; retry scheduled');
    $activeLease=null;
    $heartbeat->execute([$instance,json_encode(['provider'=>$provider->name(),'rate_per_second'=>$rate,'concurrency'=>$concurrency,'in_flight'=>0,'last_batch'=>$result])]);
    OperationalLogger::finishRun($runId,$result['failed']?'failed':'completed',$result['processed'],$result['failed'],json_encode($result));
    if($runner->shouldStop()||($once&&!$messages))break;
    if(!$messages)sleep(2);
} while(true);
