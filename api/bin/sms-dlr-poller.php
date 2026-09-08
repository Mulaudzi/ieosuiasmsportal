<?php

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../core/QueryBuilder.php';
require_once __DIR__.'/../services/SmsService.php';
require_once __DIR__.'/../domain/LogicSmsDeliveryStatus.php';
require_once __DIR__.'/../domain/SmsDispatchService.php';
require_once __DIR__.'/../services/OperationalLogger.php';

$pdo=db();
$lockName='ieosuia_sms_dlr_poller';
$lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);
if((int)$lock->fetchColumn()!==1){echo 'poller already running'.PHP_EOL;exit(0);}
register_shutdown_function(static function()use($pdo,$lockName):void{try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable){}});
$service=new SmsService();$limit=max(1,min(200,(int)(getenv('SMS_DLR_POLL_BATCH_SIZE')?:100)));
$runId=OperationalLogger::startRun('sms-dlr-poller',gethostname(),['limit'=>$limit]);
$pollInterval=max(30,(int)(getenv('SMS_DLR_POLL_INTERVAL_SECONDS')?:60));
$stmt=$pdo->prepare("SELECT * FROM sms_messages WHERE provider='logicsms' AND state='sent' AND provider_message_id IS NOT NULL AND sent_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) AND (last_dlr_checked_at IS NULL OR last_dlr_checked_at<=DATE_SUB(NOW(),INTERVAL {$pollInterval} SECOND)) ORDER BY COALESCE(last_dlr_checked_at,'1970-01-01'),sent_at LIMIT {$limit}");$stmt->execute();$messages=$stmt->fetchAll();$updated=0;$failures=0;$providerStatuses=[];$normalizedStatuses=[];$errorTypes=[];
foreach($messages as $message){
    $pdo->prepare('UPDATE sms_messages SET last_dlr_checked_at=NOW(),dlr_attempt_count=dlr_attempt_count+1 WHERE id=?')->execute([$message['id']]);
    $result=$service->getStatus((string)$message['provider_message_id'],'portal-message-'.$message['id']);if(empty($result['success'])){$failures++;$errorType=substr((string)($result['error']??'Unknown provider response'),0,80);$errorTypes[$errorType]=($errorTypes[$errorType]??0)+1;$pdo->prepare("UPDATE sms_messages SET last_error=?,updated_at=NOW() WHERE id=? AND state='sent'")->execute(['DLR lookup: '.$errorType,$message['id']]);OperationalLogger::log('warning','sms-dlr-poller','status_query_failed',(string)($result['error']??'Unknown provider response'),['campaign_id'=>$message['campaign_id'],'sms_message_id'=>$message['id']]);continue;}
    $providerStatus=strtoupper(trim((string)$result['status']));$providerStatuses[$providerStatus]=($providerStatuses[$providerStatus]??0)+1;$state=LogicSmsDeliveryStatus::normalize($providerStatus);$normalizedStatuses[$state]=($normalizedStatuses[$state]??0)+1;if(!in_array($state,['delivered','failed'],true))continue;
    $eventId=hash('sha256','poll|'.$message['provider_message_id'].'|'.strtoupper((string)$result['status']));
    $pdo->beginTransaction();try{
        $insert=$pdo->prepare("INSERT IGNORE INTO sms_provider_events(provider,provider_event_id,provider_message_id,event_type,payload_json,created_at) VALUES('logicsms',?,?,?,?,NOW())");$insert->execute([$eventId,$message['provider_message_id'],'delivery.'.$state,json_encode($result)]);
        if($insert->rowCount()===0){$pdo->commit();continue;}
        $update=['state'=>$state,'updated_at'=>date('Y-m-d H:i:s')];if($state==='delivered'){$update['delivered_at']=date('Y-m-d H:i:s');$update['last_error']=null;}else{$update['failed_at']=date('Y-m-d H:i:s');$update['last_error']='LogicSMS delivery status: '.$result['status'];}
        table('sms_messages')->where('id',$message['id'])->update($update);SmsDispatchService::refreshCampaign($pdo,(int)$message['campaign_id']);table('sms_provider_events')->where('provider','logicsms')->where('provider_event_id',$eventId)->update(['processed_at'=>date('Y-m-d H:i:s')]);$pdo->commit();$updated++;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
$heartbeat=$pdo->prepare("INSERT INTO service_heartbeats(service_name,instance_id,metadata_json,last_seen_at) VALUES('sms-dlr-poller',?,?,NOW()) ON DUPLICATE KEY UPDATE instance_id=VALUES(instance_id),metadata_json=VALUES(metadata_json),last_seen_at=NOW()");$heartbeat->execute([gethostname(),json_encode(['checked'=>count($messages),'updated'=>$updated,'failures'=>$failures,'provider_statuses'=>$providerStatuses,'normalized_statuses'=>$normalizedStatuses,'error_types'=>$errorTypes])]);echo 'checked '.count($messages).', updated '.$updated.PHP_EOL;
OperationalLogger::finishRun($runId,$failures?'failed':'completed',count($messages),$failures,"updated {$updated}");
