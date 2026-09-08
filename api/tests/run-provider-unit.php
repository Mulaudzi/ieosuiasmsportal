<?php

putenv('LOGICSMS_API_URL=https://www.logicsms.co.za/postmsg2.aspx');
putenv('LOGICSMS_USERNAME=test-user');
putenv('LOGICSMS_PASSWORD=test-password');
putenv('SMS_DEFAULT_SENDER=IEOSUIA');
if(!function_exists('env')){function env(string $key,mixed $default=null):mixed{$value=getenv($key);return $value===false?$default:$value;}}
require_once __DIR__.'/../services/SmsService.php';
require_once __DIR__.'/../domain/LogicSmsDeliveryStatus.php';

$failures=[];
function expectProvider(bool $condition,string $message):void{global $failures;if(!$condition)$failures[]=$message;}

$captured=[];
$service=new SmsService(function(string $url,array $params)use(&$captured):array{$captured=[$url,$params];return ['status'=>200,'body'=>'<Response><Id>abc-123</Id><Status>Queued</Status></Response>'];});
$result=$service->send('+27 79 928 2775','Readiness test','IEOSUIA','portal-message-42');
expectProvider($result['success']===true,'valid XML response should be accepted');
expectProvider($result['message_id']==='abc-123','provider message id should be parsed');
expectProvider($captured[0]==='https://www.logicsms.co.za/postmsg2.aspx','configured HTTPS endpoint should be used');
expectProvider($captured[1]['mobile']==='27799282775','destination should use international digits without plus');
expectProvider($captured[1]['Unique']==='portal-message-42','stable message reference should be forwarded');
expectProvider($captured[1]['DCheck']==='Y','provider duplicate checking should be requested');
expectProvider(SmsService::parseResponse('', 'timeout',0)['success']===false,'transport failures should be rejected');
expectProvider(SmsService::parseResponse('<html>bad</html>','',200)['success']===false,'unexpected provider bodies should be rejected');
expectProvider(SmsService::parseResponse('<Response><Id>x</Id></Response>','',500)['success']===false,'non-2xx responses should be rejected');
expectProvider(LogicSmsDeliveryStatus::normalize('DELIVRD')==='delivered','delivered receipt should normalize');
expectProvider(LogicSmsDeliveryStatus::normalize('UNDELIV')==='failed','failed receipt should normalize');
expectProvider(LogicSmsDeliveryStatus::normalize('CREATE')==='sent','accepted receipt should normalize');
expectProvider(SmsService::parseStatusResponse('<Message><Status>DELIVRD</Status></Message>')['status']==='DELIVRD','XML status query should parse');
expectProvider(SmsService::parseStatusResponse('<Response><Message status="DELIVERED" /></Response>')['status']==='DELIVERED','nested status attributes should parse');
expectProvider(SmsService::parseStatusResponse('<Response><Messages><Message><Status>DELIVRD</Status></Message></Messages></Response>')['status']==='DELIVRD','nested XML status should parse');
expectProvider(SmsService::parseStatusResponse('DELIVERED')['status']==='DELIVERED','plain status query should parse');
expectProvider(SmsService::parseStatusResponse('', '',200)['success']===false,'empty status response should fail closed');
expectProvider(SmsService::parseStatusResponse('Cannot Find Message')['success']===false,'missing provider messages should not be successful statuses');
$statusQueries=[];$statusService=new SmsService(function(string $url,array $params)use(&$statusQueries):array{$statusQueries[]=$params;return ['status'=>200,'body'=>'DELIVRD'];});$statusService->getStatus('127658776','portal-message-42');
expectProvider(($statusQueries[0]['Unique']??null)==='127658776'&&!isset($statusQueries[0]['Id']),'status query must pass the provider CREATE id in LogicSMS Unique');
expectProvider(SmsService::parseCreditsResponse('123.50')['credits']===123.5,'plain provider credit balance should parse');
expectProvider(SmsService::parseCreditsResponse('<Account><Credits>42</Credits></Account>')['credits']===42.0,'XML provider credit balance should parse');
expectProvider(SmsService::parseCreditsResponse('not-a-balance')['success']===false,'invalid provider credit response should fail closed');

foreach($failures as $failure)echo "FAIL {$failure}\n";
$passed=22-count($failures);echo "{$passed} passed, ".count($failures)." failed\n";exit($failures?1:0);
