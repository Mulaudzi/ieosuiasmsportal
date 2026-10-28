<?php
// Real accounting fixture, pure parser and exact persisted acknowledgement tests.
require __DIR__.'/run-sms-status-reconciliation.php';
require_once __DIR__.'/../domain/SmsAcknowledgementEvidence.php';
$xml='<Message><Id>1537901737</Id><Status>CREATE</Status><MessageCost>1.00</MessageCost><Parts>1</Parts><IdList></IdList></Message>';
$parsed=SmsService::parseResponse($xml);
statusCheck($parsed['success']&&$parsed['status']==='CREATE'&&$parsed['message_id']==='1537901737','Full real-shape CREATE proves accepted submission, never delivery');
foreach(['<Message><Id>123</Id><Status>CREATE</Status></Message>',str_replace('1.00','0',$xml),str_replace('<Parts>1','<Parts>0',$xml),str_replace('<Parts>1</Parts>','<Parts>1</Parts><Parts>2</Parts>',$xml),str_replace('<Id>1537901737</Id>','<Id><Nested>123</Nested></Id>',$xml),str_replace('<Status>CREATE','<Status>WORKING',$xml)] as $bad)statusCheck(!SmsService::parseResponse($bad)['success'],'Incomplete or unrelated CREATE-like data remains held');
function createAckLog(PDO $pdo,string $xml,int $message=1):array{
    $d=SmsService::parseResponse($xml)['diagnostic'];$d['code']='INVALID_ACKNOWLEDGEMENT';$d['rejection_reason']='UNRECOGNIZED_PROVIDER_STATUS';
    $context=['user_id'=>2,'campaign_id'=>24,'sms_message_id'=>$message,'acknowledgement'=>$d];
    $q=$pdo->prepare("INSERT INTO operational_logs(service,event,user_id,campaign_id,sms_message_id,context_json,created_at) VALUES('sms-provider','acknowledgement',2,24,?,?,NOW())");$q->execute([$message,json_encode($context)]);
    return $pdo->query('SELECT * FROM operational_logs WHERE id='.$pdo->lastInsertId())->fetch(PDO::FETCH_ASSOC);
}
$p=statusFixture();$log=createAckLog($p,$xml);$e=SmsAcknowledgementEvidence::fromLog($log);$result=SmsStatusReconciliation::apply($p,1,$e);
statusCheck(($result['code']??null)==='STATUS_RECONCILED','Original held message reconciled from its persisted provider acknowledgement');
$row=$p->query('SELECT * FROM sms_messages WHERE id=1')->fetch(PDO::FETCH_ASSOC);
statusCheck($row['state']==='sent'&&$row['provider_message_id']==='1537901737'&&$row['sent_at']===null&&$row['delivered_at']===null,'Real provider ID restored, no invented send/delivery timestamp');
statusCheck((float)$row['actual_charge']===.35,'Original reserved charge settled once');
$before=statusDump($p);SmsStatusReconciliation::apply($p,1,$e);statusCheck(statusDump($p)===$before,'Repeating recovery writes nothing and never charges twice');
foreach(['user_id','campaign_id','original_reference','provider_message_id','response_sha256'] as $field){$p=statusFixture();$log=createAckLog($p,$xml);$e=SmsAcknowledgementEvidence::fromLog($log);$e[$field]=is_int($e[$field])?999:'wrong';statusReject($p,$e,'Altered acknowledgement evidence rejected: '.$field);}
$p=statusFixture();$log=createAckLog($p,str_replace('1537901737','9999',$xml));$e=SmsAcknowledgementEvidence::fromLog($log);statusReject($p,$e,'Colliding provider ID cannot be assigned or charged');
$p=statusFixture();$log=createAckLog($p,$xml);$e=SmsAcknowledgementEvidence::fromLog($log);$p->exec("CREATE TRIGGER fail_ack_audit BEFORE INSERT ON operational_logs WHEN NEW.event='status_evidence_after' BEGIN SELECT RAISE(ABORT,'no audit');END");statusReject($p,$e,'Audit failure rolls back ID repair, hold removal and charge');
foreach(['http_status'=>500,'xml_namespace_count'=>1,'rejection_reason'=>'WRONG_REASON'] as $key=>$value){$p=statusFixture();$log=createAckLog($p,$xml);$context=json_decode($log['context_json'],true);$context['acknowledgement'][$key]=$value;$log['context_json']=json_encode($context);$caught=false;try{SmsAcknowledgementEvidence::fromLog($log);}catch(DomainException){$caught=true;}statusCheck($caught,'Untrusted or incompatible log rejected: '.$key);}
echo 'PASS: '.$checks." CREATE acknowledgement + accounting regression checks; no production or transport.\n";
