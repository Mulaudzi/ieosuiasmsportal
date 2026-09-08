<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../core/QueryBuilder.php';
require_once __DIR__.'/../domain/SmsDispatchService.php';
require_once __DIR__.'/../services/OperationalLogger.php';

$once=in_array('--once',$argv,true);
$batch=max(1,min(100,(int)(getenv('SMS_WORKER_BATCH_SIZE')?:50)));
$rate=max(1,(int)(getenv('SMS_PROVIDER_RATE_PER_SECOND')?:10));
$minimumInterval=1/$rate;
$provider=ProviderFactory::make();
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
    $runId=OperationalLogger::startRun('sms-worker',$instance,['batch_size'=>$batch,'provider'=>$provider->name()]);$processed=0;$failed=0;
    $heartbeat=db()->prepare("INSERT INTO service_heartbeats(service_name,instance_id,metadata_json,last_seen_at) VALUES('sms-worker',?,?,NOW()) ON DUPLICATE KEY UPDATE instance_id=VALUES(instance_id),metadata_json=VALUES(metadata_json),last_seen_at=NOW()");
    $heartbeat->execute([$instance,json_encode(['provider'=>$provider->name(),'rate_per_second'=>$rate])]);
    $lease=sprintf('%s-%s',getmypid(),bin2hex(random_bytes(12)));
    $activeLease=$lease;
    $messages=SmsDispatchService::claimBatch($batch,$lease);
    $lastAttempt=0.0;
    foreach($messages as $message){
        $heartbeat->execute([$instance,json_encode(['provider'=>$provider->name(),'rate_per_second'=>$rate,'active_message_id'=>(int)$message['id']])]);
        SmsDispatchService::renewLease($lease);
        $wait=$minimumInterval-(microtime(true)-$lastAttempt);
        if($wait>0)usleep((int)round($wait*1000000));
        $lastAttempt=microtime(true);
        try{SmsDispatchService::process($message,$provider);$processed++;}
        catch(Throwable $e){
            $failed++;
            $reason=preg_replace('/(?i)(password|secret|token|api[_ -]?key)(\s*[=:]\s*)[^\s,;]+/','$1$2[REDACTED]',$e->getMessage());
            $reason='Dispatch exception ('.get_class($e).'): '.substr((string)$reason,0,360);
            error_log('SMS worker message '.$message['id'].' failed: '.$reason);
            OperationalLogger::log('error','sms-worker','dispatch_exception',$reason,['campaign_id'=>$message['campaign_id'],'sms_message_id'=>$message['id'],'user_id'=>$message['user_id']]);
            SmsDispatchService::releaseMessageLease((int)$message['id'],$lease,$reason);
        }
    }
    SmsDispatchService::releaseLease($lease,'Worker could not complete this message; retry scheduled');
    $activeLease=null;
    OperationalLogger::finishRun($runId,$failed?'failed':'completed',$processed,$failed,$failed?'One or more messages failed to finalize':'Batch completed');
    if($once)break;
    if(!$messages)sleep(2);
} while(true);
