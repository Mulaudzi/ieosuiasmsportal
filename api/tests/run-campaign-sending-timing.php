<?php
require_once __DIR__.'/../domain/CampaignSendingTiming.php';
$checks=0;
function timingCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$campaign=['id'=>23,'user_id'=>7,'state'=>'completed','recipient_count'=>485,'queued_at'=>'2026-10-23 14:42:15','started_at'=>'2026-10-23 14:43:01','completed_at'=>'2026-10-23 22:03:35'];
$summary=['message_rows'=>485,'accepted_count'=>481,'timestamp_count'=>481,'first_accepted_at'=>'2026-10-23 14:43:03','last_accepted_at'=>'2026-10-23 15:04:50','outstanding_count'=>0,'held_count'=>0,'skipped_count'=>4,'max_accepted_attempts'=>3];
$now='2026-10-28 12:00:00';
$t=CampaignSendingTiming::calculate($campaign,$summary,$now);
timingCheck($t['queue_wait_seconds']===46&&$t['sending_seconds']===1309&&$t['total_seconds']===1355,'Historical measured window ignores later administrative completion');
timingCheck($t['status']==='complete'&&$t['accepted_count']===481&&$t['skipped_count']===4,'Skipped recipients do not extend sending');
timingCheck($t['accepted_per_minute']===22.05,'Rate counts accepted recipients');
timingCheck(CampaignSendingTiming::calculate($campaign,[...$summary,'timestamp_count'=>480],$now)['sending_seconds']===null,'Incomplete timestamps are unavailable');
$ack=['ack_count'=>481,'first_ack'=>$summary['first_accepted_at'],'last_ack'=>$summary['last_accepted_at']];
$restored=CampaignSendingTiming::calculate($campaign,[...$summary,'timestamp_count'=>0,'max_accepted_attempts'=>1],$now,$ack);
timingCheck($restored['sending_seconds']===1309&&$restored['source']==='original_acknowledgements','Complete first-attempt acknowledgement fallback');
timingCheck(CampaignSendingTiming::calculate($campaign,[...$summary,'timestamp_count'=>0],$now,$ack)['source']===null,'Retry acknowledgements cannot reconstruct missing originals');
timingCheck(CampaignSendingTiming::calculate($campaign,[...$summary,'timestamp_count'=>0,'max_accepted_attempts'=>1],$now,[...$ack,'ack_count'=>480])['sending_seconds']===null,'Partial acknowledgement coverage rejected');
foreach(['2026-10-23 14:40:00','2026-02-30 15:00:00','2027-01-01 00:00:00'] as $bad)timingCheck(CampaignSendingTiming::calculate($campaign,[...$summary,'last_accepted_at'=>$bad],$now)['sending_seconds']===null,'Invalid interval rejected');
$active=CampaignSendingTiming::calculate([...$campaign,'state'=>'processing'],[...$summary,'outstanding_count'=>4],$now);
timingCheck($active['status']==='in_progress'&&$active['elapsed_seconds']>1309,'In progress is elapsed, not final');
$held=CampaignSendingTiming::calculate([...$campaign,'state'=>'processing'],[...$summary,'outstanding_count'=>4,'held_count'=>4],$now);
timingCheck($held['status']==='held','Held state explicit');
foreach(['draft','scheduled'] as $state)timingCheck(CampaignSendingTiming::calculate([...$campaign,'state'=>$state,'started_at'=>null],[],$now)['status']==='not_started','No invented duration before sending');
timingCheck(CampaignSendingTiming::calculate([...$campaign,'state'=>'queued','started_at'=>null],[],$now)['status']==='waiting','Waiting worker state');
timingCheck(CampaignSendingTiming::calculate([...$campaign,'state'=>'cancelled'],[],$now)['status']==='cancelled','Cancelled not completed');
timingCheck(CampaignSendingTiming::calculate($campaign,[],$now)['status']==='unavailable','No records not zero time');
timingCheck(CampaignSendingTiming::calculate($campaign,[...$summary,'message_rows'=>484],$now)['status']==='unavailable','Incomplete recipient population not marked complete');
$avg=CampaignSendingTiming::summary([['state'=>'completed','sending_timing'=>$t],['state'=>'completed','sending_timing'=>[...$t,'sending_seconds'=>1034]],['state'=>'processing','sending_timing'=>$active],['state'=>'processing','sending_timing'=>$held]]);
timingCheck($avg['sample_size']===2&&$avg['average_sending_seconds']===1171.5,'Averages exclude active and held campaigns');
timingCheck(CampaignSendingTiming::summary([])['average_sending_seconds']===null,'Empty average unavailable');

// Execute both aggregate queries against an isolated database, including foreign-owner records.
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER);CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,campaign_id INTEGER,user_id INTEGER,state TEXT,sent_at TEXT,actual_charge REAL,last_error TEXT,next_attempt_at TEXT,attempt_count INTEGER);CREATE TABLE operational_logs(sms_message_id INTEGER,campaign_id INTEGER,user_id INTEGER,service TEXT,event TEXT,created_at TEXT)');
$pdo->exec("INSERT INTO sms_campaigns VALUES(23,7),(24,8);INSERT INTO sms_messages VALUES(1,23,7,'delivered','2026-10-23 15:04:50',.35,NULL,NULL,1),(2,23,99,'sent','2026-10-23 23:00:00',.35,NULL,NULL,1),(3,24,8,'sent',NULL,.35,NULL,NULL,1),(4,24,8,'sent',NULL,.35,NULL,NULL,1);INSERT INTO operational_logs VALUES(3,24,8,'sms-provider','acknowledgement','2026-10-23 15:00:01'),(3,24,8,'sms-provider','acknowledgement','2026-10-23 18:00:00'),(4,24,8,'sms-provider','acknowledgement','2026-10-23 15:00:03'),(4,24,7,'sms-provider','acknowledgement','2026-10-23 23:00:00')");
$before=$pdo->query('SELECT * FROM sms_messages')->fetchAll();
$rows=CampaignSendingTiming::attach($pdo,[[...$campaign,'recipient_count'=>1],['id'=>24,'user_id'=>8,'recipient_count'=>2,'state'=>'completed','queued_at'=>'2026-10-23 14:59:55','started_at'=>'2026-10-23 15:00:00']]);
timingCheck($rows[0]['sending_timing']['accepted_count']===1,'Foreign-owner message excluded from aggregate');
timingCheck($rows[1]['sending_timing']['source']==='original_acknowledgements'&&$rows[1]['sending_timing']['sending_seconds']===3,'Fallback joins owner and uses first acknowledgement per message');
timingCheck($before===$pdo->query('SELECT * FROM sms_messages')->fetchAll(),'Projection never mutates messages');
timingCheck(CampaignSendingTiming::attach($pdo,[])===[],'Empty page no queries');
echo "PASS $checks campaign sending timing checks; isolated fixtures only.\n";
