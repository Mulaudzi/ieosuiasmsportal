<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

// Production methods against an isolated SQLite fixture. No config, network or SMTP.
final class DeliveryFixturePdo extends PDO {
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        $query=str_replace(' FOR UPDATE','',$query);
        $query=str_replace('TIMESTAMPDIFF(SECOND,',"TIMESTAMPDIFF('SECOND',",$query);
        return parent::prepare($query,$options);
    }
}
$pdo=new DeliveryFixturePdo('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$clock='2026-10-20 12:00:00';
$pdo->sqliteCreateFunction('NOW',static fn()=>$GLOBALS['clock']);
$pdo->sqliteCreateFunction('IF',static fn($test,$yes,$no)=>$test?$yes:$no);
$pdo->sqliteCreateFunction('CONCAT',static fn(...$parts)=>implode('',$parts));
$pdo->sqliteCreateFunction('LEFT',static fn($text,$length)=>substr((string)$text,0,(int)$length));
$pdo->sqliteCreateFunction('TIMESTAMPDIFF',static fn($unit,$start,$end)=>strtotime($end)-strtotime($start));
$pdo->exec("CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,state TEXT,queued_count INTEGER,processing_count INTEGER,sent_count INTEGER,delivered_count INTEGER,failed_count INTEGER,skipped_count INTEGER,actual_cost REAL,completed_at TEXT,updated_at TEXT,created_at TEXT);
CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,campaign_id INTEGER,user_id INTEGER,destination TEXT,state TEXT,provider TEXT,provider_message_id TEXT,sent_at TEXT,delivered_at TEXT,failed_at TEXT,last_error TEXT,actual_charge REAL,segment_count INTEGER,attempt_count INTEGER DEFAULT 1,next_attempt_at TEXT,lease_token TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE sms_provider_events(id INTEGER PRIMARY KEY AUTOINCREMENT,provider TEXT,provider_event_id TEXT,provider_message_id TEXT,event_type TEXT,payload_json TEXT,processed_at TEXT,created_at TEXT,UNIQUE(provider,provider_event_id));
CREATE TABLE contacts(id INTEGER,user_id INTEGER,phone_normalized TEXT,name TEXT,surname TEXT);
CREATE TABLE wallets(id INTEGER,balance REAL,reserved REAL);INSERT INTO wallets VALUES(1,99.5,4.2);
CREATE TABLE wallet_ledger(id INTEGER,amount REAL);INSERT INTO wallet_ledger VALUES(1,.7);");
function db():PDO{return $GLOBALS['pdo'];}
function env(string $key,mixed $default=null):mixed{return in_array($key,['LOGICSMS_WEBHOOK_SECRET','DLR_WEBHOOK_SECRET'],true)?str_repeat('fixture-secret-',4):$default;}
class Response {
    public static function success(mixed $data=null,int $status=200):void{$GLOBALS['response']=['status'=>$status,'data'=>$data];}
    public static function error(string $message,int $status=400,array $errors=[]):void{$GLOBALS['response']=['status'=>$status,'error'=>$message];}
}
require_once __DIR__.'/../core/QueryBuilder.php';
require_once __DIR__.'/../core/Request.php';
require_once __DIR__.'/../controllers/LogicSmsWebhookController.php';
require_once __DIR__.'/../services/PortalReadModel.php';
require_once __DIR__.'/../services/SmsReportDocument.php';
require_once __DIR__.'/../services/CampaignRecipientExport.php';

$checks=0;
function same(mixed $expected,mixed $actual,string $message):void{global $checks;if($expected!==$actual)throw new RuntimeException($message.': '.json_encode([$expected,$actual]));$checks++;}
function message(int $id):array{$q=db()->prepare('SELECT * FROM sms_messages WHERE id=?');$q->execute([$id]);return $q->fetch();}
function addMessage(int $id,string $state='sent',?string $sent='2026-10-20 11:59:55',int $user=1,int $campaign=1,?string $error=null):void{
    db()->prepare("INSERT OR IGNORE INTO sms_campaigns(id,user_id,name,state,created_at,updated_at) VALUES(?,?,'Fixture campaign','processing','2026-10-20 00:00:00',NOW())")->execute([$campaign,$user]);
    db()->prepare("INSERT INTO sms_messages(id,campaign_id,user_id,destination,state,provider,provider_message_id,sent_at,last_error,actual_charge,segment_count,created_at,updated_at) VALUES(?,?,?,'+27630000000',?,'logicsms',?,?,?,?,2,'2026-10-20 00:00:00',NOW())")->execute([$id,$campaign,$user,$state,'fixture-'.$id,$sent,$error,$sent!==null?.70:0]);
}
function callback(array $payload,bool $authenticated=true):array{
    $_GET=$payload;$_POST=[];$_SERVER['CONTENT_TYPE']='application/x-www-form-urlencoded';
    $_SERVER['HTTP_X_WEBHOOK_SECRET']=$authenticated?env('LOGICSMS_WEBHOOK_SECRET'):'incorrect';
    (new ReflectionProperty(Request::class,'input'))->setValue(null,null);
    $GLOBALS['response']=null;(new LogicSmsWebhookController())->delivery();return $GLOBALS['response'];
}
function postback(int $id,string $status,string $event):array{return callback(['Unique'=>'portal-message-'.$id,'Id'=>'fixture-'.$id,'Status'=>$status,'EventId'=>$event]);}
function financialSnapshot():array{return [db()->query('SELECT * FROM wallets')->fetchAll(),db()->query('SELECT * FROM wallet_ledger')->fetchAll()];}
$financial=financialSnapshot();

addMessage(1);
same('Sent - Awaiting Confirmation',SmsDeliveryPresentation::label('sent'), 'Acceptance is not delivery');
same('sent',message(1)['state'],'Missing postback leaves acceptance intact');
same(200,postback(1,'DELIVERED','delivery-1')['status'],'Immediate callback accepted');
same('delivered',message(1)['state'],'Immediate callback updates the record');
$confirmedAt=message(1)['delivered_at'];$clock='2026-10-20 12:00:30';
same(true,postback(1,'DELIVERED','delivery-1')['data']['duplicate'],'Exact duplicate acknowledged');
postback(1,'DELIVERED','delivery-1-again');
same($confirmedAt,message(1)['delivered_at'],'Repeat delivery cannot reset confirmation time');
foreach(['SENT','UNKNOWN','UNDELIVERED'] as $i=>$status)postback(1,$status,'out-of-order-'.$i);
same('delivered',message(1)['state'],'Out-of-order postbacks cannot reverse delivery');

foreach([2=>'2026-10-20 11:59:55',3=>'2026-10-20 08:00:00',4=>'2026-10-17 01:00:00',5=>'2026-08-01 01:00:00'] as $id=>$sent){
    addMessage($id,'sent',$sent);same(200,postback($id,'DELIVRD','late-'.$id)['status'],'Late callback accepted');
    same('delivered',message($id)['state'],'Seconds, hours, >48h and >30d callbacks update immediately');
}
addMessage(6);postback(6,'UNDELIVERED','failure-6');
same('failed',message(6)['state'],'Definitive failure stored');
same('The mobile network reported that your SMS could not be delivered.',PortalReadModel::reason('failed',message(6)['last_error']),'Provider diagnostics are never the customer Result');
same('LogicSMS delivery status: UNDELIVERED',message(6)['last_error'],'Raw provider diagnostics retained internally');
postback(6,'SENT','stale-progress-6');same('failed',message(6)['state'],'Progress cannot reverse definitive failure');
postback(6,'DELIVERED','correction-6');same('delivered',message(6)['state'],'More definitive delivery can correct prior failure');
same(null,message(6)['failed_at'],'Corrected delivery clears obsolete failure timestamp');

addMessage(7);postback(7,'UNKNOWN','unavailable-7');same('dlr_unavailable',message(7)['state'],'Explicit provider unavailable report is separate');
same(null,message(7)['failed_at'],'Unavailable is not a failure');
postback(7,'SENT','old-progress-7');same('dlr_unavailable',message(7)['state'],'Old progress does not erase unavailable result');
postback(7,'DELIVERED','later-confirmation-7');same('delivered',message(7)['state'],'Unavailable can receive a later definitive result');
addMessage(8,'sent','2026-08-01 00:00:00',1,1,'DLR lookup: Cannot Find Message');
same('Sent - Awaiting Confirmation',PortalReadModel::recipientLabel('sent',message(8)['last_error']),'A failed lookup is not authoritative unavailability');
same('sent',message(8)['state'],'No arbitrary age-based state transition');

addMessage(9,'processing',null);db()->exec('UPDATE sms_messages SET provider_message_id=NULL WHERE id=9');
$early=callback(['Unique'=>'portal-message-9','Status'=>'DELIVERED','EventId'=>'early-9']);
same(true,$early['data']['deferred'],'Callback before submission commit is retained');
same('processing',message(9)['state'],'Early callback does not bypass worker settlement');
db()->exec("UPDATE sms_messages SET state='sent',sent_at=NOW(),actual_charge=.7,provider_message_id='fixture-9' WHERE id=9");
LogicSmsDeliveryService::reconcile(db(),9);same('delivered',message(9)['state'],'Deferred result applies once submission commits');
same(true,(bool)db()->query("SELECT processed_at FROM sms_provider_events WHERE provider_event_id='early-9'")->fetchColumn(),'Deferred event marked processed');

$unmatched=callback(['Id'=>'fixture-10','Status'=>'DELIVERED','EventId'=>'early-unmatched-10']);
same(false,$unmatched['data']['matched'],'Unmatched callback acknowledged');
same(1,(int)db()->query("SELECT COUNT(*) FROM sms_provider_events WHERE provider_event_id='early-unmatched-10'")->fetchColumn(),'Unmatched evidence is not discarded');
addMessage(10);LogicSmsDeliveryService::reconcile(db(),10);same('delivered',message(10)['state'],'Provider-ID-only early report recovers without another provider call');

addMessage(11);$before=message(11);
same(403,callback(['Id'=>'fixture-11','Status'=>'DELIVERED'],false)['status'],'Invalid authentication rejected');
same($before,message(11),'Invalid authentication changes no message');
same(422,postback(11,'not-a-provider-status','invalid-11')['status'],'Unrecognized payload rejected');
same(422,callback(['Id'=>'fixture-11','Unique'=>'portal-message-1','Status'=>'DELIVERED'])['status'],'Conflicting identifiers rejected');
same('sent',message(11)['state'],'Rejected payloads cannot mutate delivery');
addMessage(12,'skipped',null);addMessage(13,'failed',null,1,1,'Sensitive provider exception');addMessage(14,'pending',null);
addMessage(15,'sent','2026-10-20 11:00:00',2,2);
same('FAILED_TO_SEND',SmsMessageState::technicalStatus(message(13)),'Failed submission has distinct technical status');
same('Not Sent',SmsDeliveryPresentation::label('skipped'),'Skipped means deliberately not sent');
same('Failed to send',SmsDeliveryPresentation::label('failed','Sensitive provider exception',false),'Failed submission is not a delivery failure');

$rows=PortalReadModel::recipients(db(),1,1)['rows'];$byId=array_column($rows,null,'id');
$recipientCsv=CampaignRecipientExport::csv(PortalReadModel::recipients(db(),1,1));
$csvStream=fopen('php://temp','w+');fwrite($csvStream,$recipientCsv);rewind($csvStream);
same(CampaignRecipientExport::HEADERS,fgetcsv($csvStream,escape:''),'Recipient CSV headers unchanged');
while(($csvRow=fgetcsv($csvStream,escape:''))!==false)same(6,count($csvRow),'Recipient CSV exports only intended public columns');
fclose($csvStream);
same(false,str_contains(CampaignRecipientExport::pdf(PortalReadModel::recipients(db(),1,1)),'FAILED_TO_SEND'),'Technical state not leaked into recipient PDF');
same('Sent - Awaiting Confirmation',$byId[8]['status'],'Recipient read model uses current state');
same('FAILED_TO_SEND',$byId[13]['technical_status'],'Customer payload distinguishes submission failure');
same(true,!str_contains(json_encode($rows),'LogicSMS delivery status'),'No raw provider statuses leak in recipient views');
same(true,!str_contains(json_encode($rows),'Sensitive provider exception'),'No raw submission errors leak in recipient views');
same(0,count(array_filter($rows,fn($row)=>$row['id']==='15')),'Recipient read model is tenant isolated');
same(1,PortalReadModel::recipients(db(),1,1,'failed_to_send')['total'],'Failed-submission filter');
same(0,PortalReadModel::recipients(db(),1,1,'failed')['total'],'Corrected delivery is no longer a failure');
same(1,PortalReadModel::recipients(db(),1,1,'skipped')['total'],'Not Sent filter');
try{PortalReadModel::recipients(db(),2,1);throw new RuntimeException('Tenant check missing');}catch(OutOfBoundsException){}

$campaign=SmsReportingService::campaignRecords(1,[['id'=>1,'state'=>'completed']])[0];
same(14,$campaign['recipient_count'],'All recipient records accounted once');
same(11,$campaign['sent_count'],'Accepted messages only in sent total');
same(1,$campaign['failed_submission_count'],'Unsent failure remains separate');
same(0,$campaign['failed_count'],'No manufactured delivery failures');
same(1,$campaign['skipped_count'],'Deliberately excluded recipients separate');
same(1,$campaign['queued_count'],'Queued is not sent');
same(2,$campaign['awaiting_delivery_count'],'Missing confirmation and failed lookup remain pending');

$report=SmsReportDocument::load(1,1);$csv=SmsReportDocument::csv($report);$pdf=SmsReportDocument::pdf($report);$html=SmsReportDocument::email($report);
foreach([$csv,$pdf,$html] as $document){
    same(false,str_contains($document,'LogicSMS delivery status'),'Provider diagnostics absent from reports');
    same(false,str_contains($document,'Sensitive provider exception'),'Provider exceptions absent from reports');
    same(true,str_contains($document,'Awaiting'),'Customer labels consistent in exports and email');
}
same(true,str_contains(SmsReportDocument::note($report),'Some outcomes are still pending'),'Partial report never claims all results final');
same($financial,financialSnapshot(),'Every postback left wallet balances, reservations and ledger unchanged');
same(15,(int)db()->query('SELECT COUNT(*) FROM sms_messages')->fetchColumn(),'No duplicate message records or sends');
same(true,str_contains(file_get_contents(__DIR__.'/../bin/sms-dlr-poller.php'),'LogicSmsStatusSync::run('),'Background provider lookups use the bounded status-only synchronizer');
same(true,str_contains(file_get_contents(__DIR__.'/../bin/sms-dlr-poller.php'),'LogicSmsStatusSync::runStored('),'Deferred held callbacks use the shared safe reconciliation path');
same(false,str_contains(file_get_contents(__DIR__.'/../domain/LogicSmsDeliveryService.php'),'->send('),'Delivery service never submits an SMS');
same('failed',LogicSmsDeliveryStatus::normalize('BLOCKED'),'Definitive blocked postback is not a missing confirmation');
same(false,SmsMessageState::accepted(['state'=>'skipped','sent_at'=>'stale timestamp']),'Stale timestamps cannot turn exclusions into accepted sends');
same(200,callback(['Id'=>'fixture-8','Status'=>'SENT','EventId'=>''])['status'],'Empty event ID gets a deterministic fallback');
same(200,callback(['Id'=>'fixture-11','Status'=>'SENT','EventId'=>''])['status'],'Empty event ID is independent per message');
same(true,callback(['Id'=>'fixture-11','Status'=>'SENT','EventId'=>''])['data']['duplicate'],'Fallback event IDs remain idempotent');
db()->exec("UPDATE sms_messages SET created_at=datetime('now')");
$summary=SmsReportingService::summary(1,'7d');
foreach(['total_messages'=>11,'delivered'=>9,'failed'=>0,'failed_submissions'=>1,'waiting_to_send'=>1,'pending'=>2,'recipient_count'=>14,'skipped'=>1] as $field=>$expected)same($expected,$summary[$field],'Shared dashboard/report metric '.$field);
addMessage(16,'sent','2026-10-20 08:00:00',1,3);db()->exec("UPDATE sms_campaigns SET state='cancelled' WHERE id=3");
postback(16,'DELIVERED','cancelled-campaign-report');
same('delivered',message(16)['state'],'Valid late delivery remains recorded for cancelled campaign');
same('cancelled',db()->query('SELECT state FROM sms_campaigns WHERE id=3')->fetchColumn(),'Postback does not revive a cancelled campaign');
same($financial,financialSnapshot(),'Late cancelled-campaign report has no financial effect');

// Provider clarification: blocks are final and do not identify the opt-out cause.
addMessage(17,'sent','2026-10-03 18:47:02',1,4);
same(200,postback(17,'blocked 2026-10-03 18:57:24','blocked-17')['status'],'Timestamped block accepted');
same('failed',message(17)['state'],'Block stored as a definitive failure');
same($clock,message(17)['failed_at'],'Failure timestamp remains portal observation time, not provider time');
same('LogicSMS delivery status: blocked 2026-10-03 18:57:24',message(17)['last_error'],'Original status and timestamp preserved internally');
$blockedRows=PortalReadModel::recipients(db(),1,4);$blockedRow=$blockedRows['rows'][0];
same('Not Delivered',$blockedRow['status'],'Existing public failure category retained');
same('provider_blocked',$blockedRow['reason_code'],'Safe reason code enables frontend explanation');
same('Blocked by the messaging provider or recipient restrictions. The exact reason is unavailable.',$blockedRow['result'],'No unsupported network or opt-out attribution');
foreach([CampaignRecipientExport::csv($blockedRows),SmsReportDocument::csv(SmsReportDocument::load(1,4))] as $blockedCsv){
 same(true,str_contains($blockedCsv,'Blocked by the messaging provider or recipient restrictions.'),'Recipient and report CSV explain blocks consistently');
 same(false,str_contains($blockedCsv,'LogicSMS delivery status'),'No raw provider text in block exports');
}
$blockedBefore=message(17);
foreach([CampaignRecipientExport::pdf($blockedRows),SmsReportDocument::pdf(SmsReportDocument::load(1,4))] as $blockedPdf){
 same(true,str_starts_with($blockedPdf,'%PDF-'),'Blocked recipient export generates a PDF');
 same(true,str_contains($blockedPdf,'Blocked by'),'Blocked explanation appears in recipient and report PDFs');
 same(false,str_contains($blockedPdf,'LogicSMS delivery status'),'PDF hides raw block diagnostics');
}
foreach(['SENT','UNKNOWN','DELIVERED'] as $i=>$status)postback(17,$status,'blocked-conflict-'.$i);
same($blockedBefore,message(17),'Final block not silently reversed by conflicting later reports');
same(3,(int)db()->query("SELECT COUNT(*) FROM sms_provider_events WHERE provider_event_id LIKE 'blocked-conflict-%' AND processed_at IS NOT NULL")->fetchColumn(),'Conflicting evidence is retained and consumed, not repeatedly reconciled');
same($financial,financialSnapshot(),'Blocks do not charge again or refund provider-accepted messages');
same(0,(int)db()->query('SELECT COUNT(*) FROM contacts')->fetchColumn(),'Block does not create an inferred opt-out/contact');

addMessage(18,'sent','2026-10-03 18:47:02',1,4);postback(18,'UNDELIVERED','generic-failure-18');
postback(18,'BLOCKED','refined-block-18');
same('LogicSMS delivery status: BLOCKED',message(18)['last_error'],'Specific block refines generic failure diagnostics');
postback(18,'DELIVERED','refined-block-conflict-18');same('failed',message(18)['state'],'Refined block remains final');

addMessage(19,'processing',null,1,4);db()->exec('UPDATE sms_messages SET provider_message_id=NULL WHERE id=19');
callback(['Unique'=>'portal-message-19','Status'=>'BLOCKED','EventId'=>'early-block-19']);
callback(['Unique'=>'portal-message-19','Status'=>'DELIVERED','EventId'=>'early-block-conflict-19']);
db()->exec("UPDATE sms_messages SET state='sent',sent_at=NOW(),actual_charge=.7,provider_message_id='fixture-19' WHERE id=19");
LogicSmsDeliveryService::reconcile(db(),19);
same('failed',message(19)['state'],'Deferred batch preserves terminal block across later events in same reconciliation');
same($financial,financialSnapshot(),'Block reconciliation leaves all financial data unchanged');
echo "PASS: $checks delivery/postback/read-model/export/billing-isolation checks; SQLite fixtures only, no network, SMS, email or production access.\n";
