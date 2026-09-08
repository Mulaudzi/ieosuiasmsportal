<?php

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../services/OperationalLogger.php';

$pdo=db();$instance=gethostname();$runId=OperationalLogger::startRun('sms-scheduler',$instance);$pdo->beginTransaction();
try{
    $rows=$pdo->query("SELECT id FROM sms_campaigns WHERE state='scheduled' AND scheduled_at<=NOW() ORDER BY scheduled_at LIMIT 100 FOR UPDATE SKIP LOCKED")->fetchAll(PDO::FETCH_COLUMN);
    if($rows){$marks=implode(',',array_fill(0,count($rows),'?'));$pdo->prepare("UPDATE sms_campaigns SET state='queued',queued_count=recipient_count,queued_at=NOW(),updated_at=NOW() WHERE id IN ({$marks}) AND state='scheduled'")->execute($rows);}
    $pdo->prepare("INSERT INTO service_heartbeats(service_name,instance_id,metadata_json,last_seen_at) VALUES('sms-scheduler',?,?,NOW()) ON DUPLICATE KEY UPDATE instance_id=VALUES(instance_id),metadata_json=VALUES(metadata_json),last_seen_at=NOW()")->execute([$instance,json_encode(['queued'=>count($rows),'completed'=>true])]);
    $pdo->commit();
    $retention=max(7,min(365,(int)(getenv('OPERATIONAL_LOG_RETENTION_DAYS')?:90)));
    $pdo->exec("DELETE FROM operational_logs WHERE created_at<DATE_SUB(NOW(),INTERVAL {$retention} DAY) LIMIT 1000");
    $pdo->exec("DELETE FROM service_job_runs WHERE started_at<DATE_SUB(NOW(),INTERVAL {$retention} DAY) LIMIT 1000");
    OperationalLogger::finishRun($runId,'completed',count($rows),0);echo 'queued '.count($rows).PHP_EOL;
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();OperationalLogger::log('error','sms-scheduler','scheduler_failed',$e->getMessage());OperationalLogger::finishRun($runId,'failed',0,1,$e->getMessage());fwrite(STDERR,'Scheduler failed'.PHP_EOL);exit(1);
}
