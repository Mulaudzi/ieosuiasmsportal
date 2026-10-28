<?php

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$id=null;$reference=null;$invalid=false;
foreach(array_slice($argv,1) as $arg){
    if(str_starts_with($arg,'--id=')&&$id===null)$id=substr($arg,5);
    elseif(str_starts_with($arg,'--reference=')&&$reference===null)$reference=substr($arg,12);
    else $invalid=true;
}
foreach([$id,$reference] as $value)if($value!==null&&!preg_match('/^[A-Za-z0-9_-]{1,100}$/',$value))$invalid=true;
if($invalid||($id===null&&$reference===null)){fwrite(STDERR,"Usage: php api/bin/query-controlled-status.php --reference=ORIGINAL_UNIQUE [--id=PROVIDER_ID]\nLegacy: php api/bin/query-controlled-status.php --id=PROVIDER_ID\nRead-only bounded lookup; never resends a message or changes delivery/billing records.\n");exit(2);}
require_once __DIR__.'/../config/database.php';require_once __DIR__.'/../services/SmsService.php';require_once __DIR__.'/../domain/LogicSmsDeliveryStatus.php';
$result=(new SmsService())->getStatus($id??'',$reference);
if(!empty($result['status']))$result['normalized_status']=LogicSmsDeliveryStatus::normalize((string)$result['status']);
$result['observed_at']=gmdate('Y-m-d\TH:i:s\Z');
$result['delivery_record_changed']=false;
unset($result['raw']);echo json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;exit(!empty($result['success'])?0:1);
