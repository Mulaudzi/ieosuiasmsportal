<?php

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../core/QueryBuilder.php';
require_once __DIR__.'/../services/PaymentReceiptService.php';
require_once __DIR__.'/../services/OperationalLogger.php';

$runId=OperationalLogger::startRun('payment-receipt-worker',gethostname());
$result=PaymentReceiptService::dispatchDue((int)(getenv('PAYMENT_EMAIL_BATCH_SIZE')?:25));
$heartbeat=db()->prepare("INSERT INTO service_heartbeats(service_name,instance_id,metadata_json,last_seen_at) VALUES('payment-receipt-worker',?,?,NOW()) ON DUPLICATE KEY UPDATE instance_id=VALUES(instance_id),metadata_json=VALUES(metadata_json),last_seen_at=NOW()");
$heartbeat->execute([gethostname(),json_encode($result)]);
OperationalLogger::finishRun($runId,($result['failed']>0||!empty($result['migration_required']))?'failed':'completed',$result['processed'],$result['failed']);
echo json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($result['failed']>0?1:0);
