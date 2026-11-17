<?php

// Pure parser tests only: no credentials, transport, database or SMS submission.
require_once __DIR__.'/../services/SmsService.php';

$checks=0;$failures=[];
function expectAckDiagnostic(bool $condition,string $message):void
{
    global $checks,$failures;$checks++;
    if(!$condition)$failures[]=$message;
}
function ackHeld(string $body,string $reason):array
{
    $result=SmsService::parseResponse($body);
    expectAckDiagnostic(!$result['success']&&$result['uncertain']&&!$result['retryable']&&$result['error_code']==='INVALID_ACKNOWLEDGEMENT','Invalid acknowledgement remains held');
    expectAckDiagnostic(($result['diagnostic']['rejection_reason']??null)===$reason,'Invalid acknowledgement has a precise bounded rejection reason');
    return $result['diagnostic'];
}

$acceptedBody='<Message><Id>123456</Id><Status>DELIVERED</Status><IdList>123456</IdList><MessageCost>0.3500</MessageCost><Parts>1</Parts></Message>';
$accepted=SmsService::parseResponse($acceptedBody);
$diagnostic=$accepted['diagnostic'];
expectAckDiagnostic($accepted['success']&&$accepted['message_id']==='123456'&&$accepted['status']==='DELIVERED','Valid existing acceptance is unchanged');
expectAckDiagnostic($accepted['provider_cost']===0.35&&$accepted['parts']===1&&$accepted['id_list']==='123456','Existing accepted result fields are unchanged');
expectAckDiagnostic($diagnostic['response_bytes']===strlen($acceptedBody)&&$diagnostic['response_sha256']===hash('sha256',$acceptedBody),'Response identity retains byte count and hash');
expectAckDiagnostic($diagnostic['xml_parsed']&&$diagnostic['xml_error_count']===0&&$diagnostic['xml_error_codes']===[],'Valid XML parser metadata is recorded');
expectAckDiagnostic($diagnostic['xml_root']==='Message'&&$diagnostic['xml_namespace_count']===0&&$diagnostic['xml_namespace_sha256']===[],'Root and absent namespaces are explicit');
foreach(['Id'=>'123456','Status'=>'DELIVERED','IdList'=>'123456','MessageCost'=>'0.3500','Parts'=>'1'] as $field=>$value){
    expectAckDiagnostic($diagnostic['fields'][$field]['value']===$value,'Known safe provider field is logged without coercing formatting');
    expectAckDiagnostic($diagnostic['direct_field_counts'][$field]===1&&$diagnostic['local_field_counts'][$field]===1,'Field multiplicity is explicit');
}
expectAckDiagnostic(!isset($diagnostic['rejection_reason']),'Accepted result has no rejection reason');

$missing=ackHeld('<Message><Id>123</Id></Message>','EXPECTED_SINGLE_ID_AND_STATUS');
expectAckDiagnostic($missing['direct_field_counts']['Id']===1&&$missing['direct_field_counts']['Status']===0,'Missing status is distinguishable from parsing failure');
$duplicate=ackHeld('<Message><Id>123</Id><Id>456</Id><Status>SENT</Status></Message>','EXPECTED_SINGLE_ID_AND_STATUS');
expectAckDiagnostic($duplicate['local_field_counts']['Id']===2&&!isset($duplicate['fields']['Id']),'Ambiguous ID multiplicity is logged without selecting an ID');
ackHeld('<Message><Id>0</Id><Status>SENT</Status></Message>','INVALID_PROVIDER_ID');
ackHeld('<Message><Id>-1</Id><Status>SENT</Status></Message>','INVALID_PROVIDER_ID');
ackHeld('<Message><Id>0</Id><Status>Error</Status><Reason>Invalid credentials</Reason></Message>','INCOMPLETE_EXPLICIT_REJECTION');

$private='synthetic-secret-do-not-log';
$unknown=ackHeld('<Message><Id>123</Id><Status>'.$private.'</Status><Reason>password='.$private.'</Reason><Mobile>+27638082493</Mobile><Content>Private SMS content</Content></Message>','UNRECOGNIZED_PROVIDER_STATUS');
expectAckDiagnostic(!isset($unknown['fields']['Status']['value'])&&$unknown['fields']['Status']['sha256']===hash('sha256',$private),'Unknown status has only length and hash');
expectAckDiagnostic(!isset($unknown['fields']['Reason'])&&$unknown['local_field_counts']['Reason']===1,'Reason presence is counted but its free text is never logged');
expectAckDiagnostic(!str_contains(json_encode($unknown),$private)&&!str_contains(json_encode($unknown),'27638082493')&&!str_contains(json_encode($unknown),'Private SMS content'),'Credentials, destination and content are omitted');

$namespace='https://example.invalid/'.$private;
$namespaced=ackHeld('<n:Message xmlns:n="'.$namespace.'"><n:Id>123</n:Id><n:Status>SENT</n:Status></n:Message>','EXPECTED_SINGLE_ID_AND_STATUS');
expectAckDiagnostic($namespaced['direct_field_counts']['Id']===0&&$namespaced['local_field_counts']['Id']===1,'Namespaced field shape is visible without accepting a new schema');
expectAckDiagnostic($namespaced['xml_namespace_count']===1&&$namespaced['xml_namespace_sha256']===[hash('sha256',$namespace)]&&!str_contains(json_encode($namespaced),$private),'Namespace is fingerprinted, not exposed');

$unknownRoot=ackHeld('<'.$private.'><Id>123</Id><Status>SENT</Status></'.$private.'>','UNEXPECTED_ROOT');
expectAckDiagnostic($unknownRoot['xml_root']==='OTHER'&&$unknownRoot['xml_root_sha256']===hash('sha256',$private)&&!str_contains(json_encode($unknownRoot),$private),'Arbitrary root names are never logged verbatim');
$html=ackHeld('<html><body>'.$private.'</body></html>','UNEXPECTED_ROOT');
expectAckDiagnostic($html['xml_root']==='html'&&!str_contains(json_encode($html),$private),'An HTML response is identified without copying its body');

$malformed=ackHeld('<Message><Id>'.$private.'</Message>','XML_PARSE_FAILED');
expectAckDiagnostic(!$malformed['xml_parsed']&&$malformed['xml_error_count']>0&&count($malformed['xml_error_codes'])>0,'Malformed XML exposes numeric parser failure metadata');
expectAckDiagnostic(!str_contains(json_encode($malformed),$private),'Parser errors cannot disclose input snippets');
foreach($malformed['xml_error_codes'] as $code)expectAckDiagnostic(is_int($code),'XML error codes are integers, not messages');
ackHeld('<!DOCTYPE Message [<!ENTITY secret SYSTEM "file:///'.$private.'">]><Message><Id>1</Id><Status>SENT</Status></Message>','PROHIBITED_XML_DECLARATION');
ackHeld(str_repeat('x',65537),'RESPONSE_TOO_LARGE');

$structured=ackHeld('<Message><Id><Unexpected>'.$private.'</Unexpected></Id><Status>SENT</Status></Message>','INVALID_PROVIDER_ID');
expectAckDiagnostic($structured['fields']['Id']['has_child_elements']&&!isset($structured['fields']['Id']['value'])&&!str_contains(json_encode($structured),$private),'Structured fields are fingerprinted only');
$unsafeFields=SmsService::parseResponse('<Message><Id>123</Id><Status>SENT</Status><Parts>'.$private.'</Parts><IdList>'.$private.'</IdList><MessageCost>'.$private.'</MessageCost></Message>')['diagnostic'];
foreach(['Parts','IdList','MessageCost'] as $field)expectAckDiagnostic(!isset($unsafeFields['fields'][$field]['value'])&&$unsafeFields['fields'][$field]['sha256']===hash('sha256',$private),'Unexpected optional values are hash-only');
expectAckDiagnostic(!str_contains(json_encode($unsafeFields),$private),'Optional diagnostics do not expose arbitrary provider values');

$namespaceDeclarations='';
for($i=0;$i<20;$i++)$namespaceDeclarations.=' xmlns:n'.$i.'="https://example.invalid/'.$private.'/'.$i.'"';
$manyNamespaces=SmsService::parseResponse('<Message'.$namespaceDeclarations.'><Id>123</Id><Status>SENT</Status></Message>')['diagnostic'];
expectAckDiagnostic($manyNamespaces['xml_namespace_count']===20&&count($manyNamespaces['xml_namespace_sha256'])===8&&$manyNamespaces['xml_namespace_list_truncated'],'Namespace diagnostics are bounded');
expectAckDiagnostic(strlen(json_encode($manyNamespaces))<4096&&!str_contains(json_encode($manyNamespaces),$private),'Diagnostic payload stays bounded without namespace secrets');

$rejected=SmsService::parseResponse('<Message><Id>0</Id><Status>Error</Status><MessageCost>0</MessageCost><Reason>Invalid password '.$private.'</Reason><Parts>0</Parts><IdList>0</IdList></Message>');
expectAckDiagnostic(!$rejected['success']&&!$rejected['uncertain']&&$rejected['error_code']==='PROVIDER_AUTHENTICATION','Verified explicit provider rejection remains definitive');
expectAckDiagnostic(!str_contains(json_encode($rejected),$private),'Explicit rejection diagnostics never leak Reason text');
$transport=SmsService::parseResponse($private,'timeout password='.$private,0);
expectAckDiagnostic($transport['error_code']==='TRANSPORT_TIMEOUT'&&!isset($transport['diagnostic']['xml_parsed'])&&!str_contains(json_encode($transport),$private),'Transport failure retains safe metadata only');
$http=SmsService::parseResponse('<Message><Id>123</Id><Status>SENT</Status></Message>','',403);
expectAckDiagnostic(!$http['success']&&$http['error_code']==='HTTP_403'&&!isset($http['diagnostic']['xml_parsed']),'Non-2xx response is never reinterpreted as acceptance');

foreach($failures as $failure)echo "FAIL {$failure}\n";
echo ($checks-count($failures)).' passed, '.count($failures)." failed\n";
exit($failures?1:0);
