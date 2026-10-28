<?php

putenv('LOGICSMS_API_URL=https://www.logicsms.co.za/postmsg2.aspx');
putenv('LOGICSMS_USERNAME=test-user');
putenv('LOGICSMS_PASSWORD=test-password');
putenv('SMS_DEFAULT_SENDER=IEOSUIA');
if(!function_exists('env')){function env(string $key,mixed $default=null):mixed{$value=getenv($key);return $value===false?$default:$value;}}
require_once __DIR__.'/../services/SmsService.php';
require_once __DIR__.'/../domain/LogicSmsDeliveryStatus.php';

$failures=[];$checks=0;
function expectProvider(bool $condition,string $message):void{global $failures,$checks;$checks++;if(!$condition)$failures[]=$message;}

foreach (['<Response><Id>0</Id><Status>SENT</Status></Response>','<Response><Id>-1</Id><Status>SENT</Status></Response>','<Response><Id>123</Id><Status>ERROR</Status></Response>','<Response><Id>error</Id><Status>SENT</Status></Response>','<Response><Id>123</Id></Response>','<html>One moment</html>'] as $invalid) { $parsed=SmsService::parseResponse($invalid);expectProvider(!$parsed['success'] && !empty($parsed['uncertain']),'Unverified acknowledgement is held, never accepted or automatically resent'); }
$captured=[];
$rejected=SmsService::parseResponse('<?xml version="1.0" encoding="utf-8" standalone="yes" ?><Message><Id>0</Id><Status>Error</Status><MessageCost>0</MessageCost><Reason>Incorrectly formated username</Reason><Parts>0</Parts><IdList>0</IdList></Message>');
expectProvider(!$rejected['success']&&!$rejected['uncertain']&&!$rejected['retryable']&&$rejected['error_code']==='PROVIDER_AUTHENTICATION','Complete zero-cost explicit rejection is actionable, not an ambiguous send');
foreach(['DELIVERED','UNDELIVERED','BLOCKED','EXPIRED'] as $status){
    $duplicate=SmsService::parseResponse('<Message><Id>123</Id><Status>'.$status.'</Status></Message>');
    expectProvider($duplicate['success']&&$duplicate['message_id']==='123','Duplicate-check response retains existing positive provider acceptance even after delivery status changes');
}
foreach(['<Message><Id>123</Id><Id>456</Id><Status>SENT</Status></Message>','<!DOCTYPE Message [<!ENTITY test SYSTEM "file:///secret">]><Message><Id>123</Id><Status>SENT</Status></Message>','<Message><Id>0</Id><Status>Error</Status><Reason>Invalid password</Reason></Message>'] as $unsafe){
    expectProvider(!empty(SmsService::parseResponse($unsafe)['uncertain']),'Ambiguous/incomplete/entity-bearing acknowledgements stay held');
}
$safeFailure=SmsService::parseResponse('private-body-password','timeout password=secret',0);
expectProvider($safeFailure['error_code']==='TRANSPORT_TIMEOUT'&&!str_contains(json_encode($safeFailure),'password'),'Safe diagnostics categorize timeouts without raw transport errors or response content');
$service=new SmsService(function(string $url,array $params)use(&$captured):array{$captured=[$url,$params];return ['status'=>200,'body'=>'<Response><Id>123456789</Id><Status>SENT</Status></Response>'];});
$result=$service->send('+27 79 928 2775','Readiness test','IEOSUIA','portal-message-42');
expectProvider($result['success']===true,'valid XML response should be accepted');
expectProvider(($result['message_id']??null)==='123456789','provider message id should be parsed');
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
expectProvider(($statusQueries[0]['Unique']??null)==='portal-message-42'&&count($statusQueries)===1,'status query must prefer the original send Unique');
$fallbackQueries=[];$fallbackService=new SmsService(function(string $url,array $params)use(&$fallbackQueries):array{$fallbackQueries[]=$params['Unique'];return ['status'=>200,'body'=>$params['Unique']==='legacy-id'?'DELIVERED':'Cannot Find Message'];});
$fallbackResult=$fallbackService->getStatus('legacy-id','portal-message-42');
expectProvider($fallbackResult['success']===true&&$fallbackQueries===['portal-message-42','portal-message-42','legacy-id'],'missing client reference permits bounded method and legacy fallback');
expectProvider(count($fallbackResult['lookup_attempts'])===3&&$fallbackResult['lookup_reference']==='legacy-id','diagnostics retain all lookup references and methods without credentials');
foreach(['Invalid username or password','Authentication FAILED','ERROR: invalid password'] as $authError){
    $authQueries=0;$authService=new SmsService(function()use(&$authQueries,$authError):array{$authQueries++;return ['status'=>200,'body'=>$authError];});
    expectProvider($authService->getStatus('numeric','portal-message-42')['success']===false&&$authQueries===1,'authentication errors must not become message failures or trigger identifier fallback');
}
expectProvider(SmsService::parseStatusResponse('INVALID')['success']===true&&LogicSmsDeliveryStatus::normalize('INVALID')==='failed','explicit INVALID delivery status is a failure, not an invalid-credentials error');
expectProvider(SmsService::parseStatusResponse('DELIVERED 2026-09-08 20:20:53')['status']==='DELIVERED','live extended delivery status with timestamp must parse');
expectProvider(SmsService::parseStatusResponse('UNDELIVERED 2026-09-08 20:20:53')['status']==='UNDELIVERED','extended undelivered status must not match delivered');
expectProvider(SmsService::parseStatusResponse('INVALID 2026-09-08 20:20:53')['status']==='INVALID','extended invalid-destination status must parse');
foreach(['BLOCKED','blocked 2026-10-03 18:57:24','<Message><Status>BLOCKED 2026-10-03 18:57:24</Status></Message>','<Message status="blocked" />','Status: blocked 2026-10-03 18:57:24'] as $blocked){
    $parsed=SmsService::parseStatusResponse($blocked);
    expectProvider($parsed['success']===true&&$parsed['status']==='BLOCKED'&&$parsed['is_final']===true,'plain, XML and extended BLOCKED responses are final outcomes');
}
$blocked=SmsService::parseStatusResponse('blocked 2026-10-03 18:57:24');
expectProvider($blocked['provider_status_at']==='2026-10-03 18:57:24'&&$blocked['timestamp_semantics']==='status_transition','blocked transition timestamp retained without claiming delivery or opt-out time');
expectProvider(SmsService::parseStatusResponse('DELIVERED 2026-09-08 20:20:53')['timestamp_semantics']==='provider_reported_unverified','other provider timestamps have unverified semantics');
expectProvider(SmsService::parseStatusResponse('SENT')['is_final']===false,'immediately queryable SENT is not final delivery');
foreach(['Cannot Find Message','Message not found.'] as $missing){
    $parsed=SmsService::parseStatusResponse($missing);
    expectProvider(!$parsed['success']&&$parsed['error_code']==='MESSAGE_NOT_FOUND'&&!$parsed['retryable']&&$parsed['requires_investigation']&&!isset($parsed['status']),'missing record requires investigation, not indefinite retries or a delivery transition');
}
foreach([
    ['status'=>200,'body'=>'Invalid username or password'],
    ['status'=>403,'body'=>'Forbidden'],
    ['status'=>429,'body'=>'Rate limit'],
    ['status'=>503,'body'=>'Unavailable'],
    ['status'=>0,'error'=>'timeout password=do-not-print'],
    ['status'=>200,'body'=>'unrecognized response password=do-not-print'],
] as $failedQuery){
    $calls=0;$failedService=new SmsService(function()use(&$calls,$failedQuery):array{$calls++;return $failedQuery;});
    $parsed=$failedService->getStatus('numeric','portal-message-42');
    expectProvider(!$parsed['success']&&$calls===1,'auth, rate-limit, server, transport and invalid errors never amplify into fallback requests');
    expectProvider(!str_contains(json_encode($parsed),'do-not-print')&&!str_contains(json_encode($parsed),'test-password'),'diagnostics never echo response/transport secrets');
}
$missingCalls=0;$missingService=new SmsService(function()use(&$missingCalls):array{$missingCalls++;return ['status'=>200,'body'=>'Cannot Find Message'];});
$missingResult=$missingService->getStatus('numeric','portal-message-42');
expectProvider($missingCalls===4&&count($missingResult['lookup_attempts'])===4,'missing record checks are bounded to two identifiers and methods');
$methods=[];$methodService=new SmsService(function(string $url,array $params,string $method)use(&$methods):array{$methods[]=$method;return $method==='POST'?['status'=>405,'body'=>'Method not allowed']:['status'=>200,'body'=>'BLOCKED'];});
expectProvider($methodService->getStatus('numeric','portal-message-42')['status']==='BLOCKED'&&$methods===['POST','GET'],'unsupported POST permits exactly one GET compatibility attempt');
$duplicateCalls=0;$duplicateService=new SmsService(function()use(&$duplicateCalls):array{$duplicateCalls++;return ['status'=>200,'body'=>'Cannot Find Message'];});
expectProvider($duplicateService->getStatus('same-reference','same-reference')['error_code']==='MESSAGE_NOT_FOUND'&&$duplicateCalls===2,'identical original and provider references are not checked twice');
expectProvider(SmsService::parseStatusResponse('','',429)['retryable']===true&&SmsService::parseStatusResponse('','',403)['retryable']===false,'rate limiting differs from access rejection');
expectProvider(!SmsService::parseStatusResponse('UNKNOWN')['is_final'],'explicit unknown status remains non-final');
$noRefCalls=0;$noRefService=new SmsService(function()use(&$noRefCalls):array{$noRefCalls++;return [];});
expectProvider($noRefService->getStatus('')['error_code']==='MISSING_REFERENCE'&&$noRefCalls===0,'empty reference never queries a provider');
foreach(['BLOCKED 2026-02-30 18:57:24','BLOCKED 2026-10-03 24:00:00','BLOCKED because of spam','<html><body>DELIVERED</body></html>','<!DOCTYPE Message [<!ENTITY test SYSTEM "file:///not-allowed">]><Message><Status>&test;</Status></Message>'] as $invalidStatus)expectProvider(!SmsService::parseStatusResponse($invalidStatus)['success'],'invalid timestamps, prose and unsafe markup never become outcomes');
expectProvider(LogicSmsDeliveryStatus::normalize('blocked 2026-10-03 18:57:24')==='failed','timestamped webhook status normalizes consistently with queries');
expectProvider(!LogicSmsDeliveryStatus::shouldApply('failed','delivered','LogicSMS delivery status: BLOCKED'),'final block cannot automatically become delivered');
expectProvider(LogicSmsDeliveryStatus::shouldApply('failed','delivered','LogicSMS delivery status: UNDELIVERED'),'existing non-block correction policy remains');
expectProvider(SmsService::parseCreditsResponse('123.50')['credits']===123.5,'plain provider credit balance should parse');
expectProvider(SmsService::parseCreditsResponse('<Account><Credits>42</Credits></Account>')['credits']===42.0,'XML provider credit balance should parse');
expectProvider(SmsService::parseCreditsResponse('not-a-balance')['success']===false,'invalid provider credit response should fail closed');
$legacyShell='<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN"><HTML><HEAD><title>QueryCredits</title></HEAD><body><form method="post" action="./querycredits.aspx"><input type="hidden" name="__VIEWSTATE" value="synthetic-only" /></form></body></HTML>';
foreach(['10775.00','0','0.50','-1.25'] as $balance){
    $parsed=SmsService::parseCreditsResponse($balance."\r\n".$legacyShell);
    expectProvider($parsed['success']===true&&$parsed['credits']===(float)$balance,'numeric balance before the real legacy QueryCredits shell must parse');
}
expectProvider(SmsService::parseCreditsResponse("\xEF\xBB\xBF 10775.00\n".$legacyShell)['credits']===10775.0,'BOM and surrounding whitespace must not break the legacy response');
expectProvider(SmsService::parseCreditsResponse('<Credits>42.50</Credits>')['credits']===42.5,'root credit value must parse');
expectProvider(SmsService::parseCreditsResponse('<string>42.50</string>')['credits']===42.5,'scalar XML credit values remain supported');
expectProvider(SmsService::parseCreditsResponse('<Response><Account><Balance>42.50</Balance></Account></Response>')['credits']===42.5,'nested credit value must parse');
foreach([
    'Invalid username or password',
    '<html><title>Login</title><body>403</body></html>',
    '0<html><title>One moment, please...</title><body></body></html>',
    '0<html><title>QueryCredits</title><body>Invalid password</body></html>',
    '0<html><title>QueryCredits</title><body><script>1</script></body></html>',
    '0<html><title>QueryCredits</title></html>',
    '0<html><title>QueryCredits</title><body>',
    '<html><body>1234</body></html>',
    '<Account><Credits>1</Credits><Balance>2</Balance></Account>',
    '<!DOCTYPE Account [<!ENTITY balance SYSTEM "file:///not-allowed">]><Account><Credits>&balance;</Credits></Account>',
    str_repeat('9',400),
] as $badBalance)expectProvider(SmsService::parseCreditsResponse($badBalance)['success']===false,'error, challenge, ambiguous or unsafe response must not become a balance');
expectProvider(SmsService::parseCreditsResponse('42','timeout',0)['success']===false,'credit transport errors remain unavailable');
expectProvider(SmsService::parseCreditsResponse('42','',403)['success']===false,'credit HTTP errors remain unavailable');

expectProvider(LogicSmsDeliveryStatus::normalize('Cannot Find Message')==='unknown','missing DLR must never be treated as delivery failure');
foreach($failures as $failure)echo "FAIL {$failure}\n";
$passed=$checks-count($failures);echo "{$passed} passed, ".count($failures)." failed\n";exit($failures?1:0);
