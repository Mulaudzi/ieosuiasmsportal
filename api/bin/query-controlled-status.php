<?php

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$id=null;foreach($argv as $arg)if(str_starts_with($arg,'--id='))$id=substr($arg,5);
if(!$id||!preg_match('/^[A-Za-z0-9_-]{1,100}$/',$id)){fwrite(STDERR,"Usage: php api/bin/query-controlled-status.php --id=PROVIDER_ID\n");exit(2);}
require_once __DIR__.'/../config/database.php';require_once __DIR__.'/../services/SmsService.php';require_once __DIR__.'/../domain/LogicSmsDeliveryStatus.php';
$result=(new SmsService())->getStatus($id);if(!empty($result['status']))$result['normalized_status']=LogicSmsDeliveryStatus::normalize((string)$result['status']);unset($result['raw']);echo json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;exit(!empty($result['success'])?0:1);
