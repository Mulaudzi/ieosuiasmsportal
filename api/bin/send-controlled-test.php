<?php

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!in_array('--confirm-live-send',$argv,true)){fwrite(STDERR,"Refusing live send without --confirm-live-send\n");exit(2);}
$to=null;foreach($argv as $arg)if(str_starts_with($arg,'--to='))$to=substr($arg,5);
if(!$to){fwrite(STDERR,"Usage: php api/bin/send-controlled-test.php --to=NUMBER --confirm-live-send\n");exit(2);}

require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../domain/PhoneNumber.php';
require_once __DIR__.'/../domain/SmsSegmentCalculator.php';
require_once __DIR__.'/../services/SmsService.php';

$destination=PhoneNumber::normalize($to);
$message='IEOSUIA SMS Portal readiness test. No action is required.';
$analysis=SmsSegmentCalculator::analyze($message);
if($analysis['segment_count']!==1)throw new RuntimeException('Controlled test message must remain one segment');
$reference='readiness-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));
$result=(new SmsService())->send($destination,$message,env('SMS_DEFAULT_SENDER','IEOSUIA'),$reference);
echo json_encode(['success'=>(bool)($result['success']??false),'provider'=>'logicsms','message_id'=>$result['message_id']??null,'status'=>$result['status']??null,'error'=>$result['error']??null,'destination'=>PhoneNumber::mask($destination),'segments'=>1],JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(!empty($result['success'])?0:1);
