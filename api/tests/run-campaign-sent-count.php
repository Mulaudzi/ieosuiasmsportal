<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/domain/SmsReportingService.php';
$fixture=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db():PDO{global $fixture;return $fixture;}
$fixture->exec('CREATE TABLE sms_messages(campaign_id INTEGER,user_id INTEGER,state TEXT,last_error TEXT,segment_count INTEGER,actual_charge REAL,sent_at TEXT)');
$insert=$fixture->prepare('INSERT INTO sms_messages VALUES(?,?,?,?,?,?,?)');
foreach([['delivered','2026-01-01'],['sent','2026-01-01'],['sent','2026-01-01'],['failed','2026-01-01'],['failed',null],['pending',null],['skipped',null]]as[$state,$sent])$insert->execute([1,1,$state,$state==='sent'?'DLR lookup: Cannot Find Message':null,1,$sent?.35:0,$sent]);
$insert->execute([1,2,'delivered',null,1,.35,'2026-01-01']);
$before=$fixture->query('SELECT * FROM sms_messages')->fetchAll();
$fixture->exec('ALTER TABLE sms_messages ADD next_attempt_at TEXT');
$before=$fixture->query('SELECT * FROM sms_messages')->fetchAll();
$campaign=SmsReportingService::campaignRecords(1,[['id'=>1,'state'=>'completed']])[0];
$expected=['recipient_count'=>7,'sent_count'=>4,'delivered_count'=>1,'failed_count'=>1,'failed_submission_count'=>1,'queued_count'=>1,'skipped_count'=>1,'dlr_unavailable_count'=>0,'awaiting_delivery_count'=>2];
foreach($expected as$key=>$value)if($campaign[$key]!==$value)throw new RuntimeException('Incorrect '.$key);
if($before!==$fixture->query('SELECT * FROM sms_messages')->fetchAll())throw new RuntimeException('Read model mutated message data');
$fixture->exec("INSERT INTO sms_messages(campaign_id,user_id,state,segment_count,actual_charge,next_attempt_at) VALUES(1,1,'retry',1,0,'9999-12-31 23:59:59'),(1,2,'retry',1,0,'9999-12-31 23:59:59')");
$before=$fixture->query('SELECT * FROM sms_messages')->fetchAll();$held=SmsReportingService::campaignRecords(1,[['id'=>1,'state'=>'processing']])[0];
if($held['held_submission_count']!==1||$held['queued_count']!==2||$held['sent_count']!==4)throw new RuntimeException('Held count must be exact and tenant-scoped without inflating accepted totals');
if($before!==$fixture->query('SELECT * FROM sms_messages')->fetchAll())throw new RuntimeException('Held counter changed messages');
echo "PASS: sending, confirmation, unsent failures, lookup errors, tenant isolation and read-only assertions.\n";
