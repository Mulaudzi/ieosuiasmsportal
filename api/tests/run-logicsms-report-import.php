<?php
declare(strict_types=1);
function env(string $key,mixed $default=null):mixed{return $default;}
require_once __DIR__.'/../domain/LogicSmsReportImport.php';
$checks=0;
function importCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT); CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,created_at TEXT,started_at TEXT); CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,user_id INTEGER,campaign_id INTEGER,destination TEXT,content TEXT,state TEXT,provider TEXT,created_at TEXT);
INSERT INTO users VALUES(2,'blwnwupotch@gmail.com'),(7,'vendaboy.lm@gmail.com'),(8,'other@example.test');
INSERT INTO sms_campaigns VALUES(24,2,'SUPER SUNDAY','2026-10-23 21:02:00','2026-10-23 21:03:00'),(25,7,'Test','2026-10-24 00:22:00','2026-10-24 00:23:00'),(208,7,'Test Two','2026-10-24 00:58:00','2026-10-24 00:59:00'),(999,8,'Other','2026-10-24 00:22:00','2026-10-24 00:23:00');
INSERT INTO sms_messages VALUES(941,7,25,'+27638082493','Test Message from IEOSUIA SMS','retry',NULL,'2026-10-24 00:22:00'),(1200,7,208,'+27721101883','Test 2 from IEOSUIA SMS','retry',NULL,'2026-10-24 00:58:00'),(1201,7,208,'+27816545388','Test 2 from IEOSUIA SMS','retry',NULL,'2026-10-24 00:58:00'),(1202,7,208,'+27638067018','Test 2 from IEOSUIA SMS','retry',NULL,'2026-10-24 00:58:00'),(1500,8,999,'+27638082493','Test Message from IEOSUIA SMS','retry',NULL,'2026-10-24 00:22:00');");
$scopes=[['email'=>'vendaboy.lm@gmail.com','campaign_id'=>25,'content_sha256'=>hash('sha256','Test Message from IEOSUIA SMS')],['email'=>'vendaboy.lm@gmail.com','content_sha256'=>hash('sha256','Test 2 from IEOSUIA SMS')]];
$report=['file_sha256'=>str_repeat('a',64),'row_count'=>1390,'rows'=>[
 ['source_row'=>494,'reported_at'=>'2026-10-24 00:23:05','destination'=>'27638082493','content'=>'Test Message from IEOSUIA SMS','status'=>'DELIVERED','provider_credits'=>'1.0000'],
 ['source_row'=>496,'reported_at'=>'2026-10-24 00:59:03','destination'=>'27721101883','content'=>'Test 2 from IEOSUIA SMS','status'=>'DELIVERED','provider_credits'=>'1.0000'],
 ['source_row'=>499,'reported_at'=>'2026-10-24 00:59:06','destination'=>'27816545388','content'=>'Test 2 from IEOSUIA SMS','status'=>'INVALID','provider_credits'=>'1.0000'],
 ['source_row'=>500,'reported_at'=>'2026-10-24 00:59:08','destination'=>'27638067018','content'=>'Test 2 from IEOSUIA SMS','status'=>'working','provider_credits'=>'1.0000'],
 ['source_row'=>2,'reported_at'=>'2026-10-23 14:43:03','destination'=>'27602458699','content'=>'Unrelated campaign','status'=>'DELIVERED','provider_credits'=>'1.0000'],
]];
$before=$pdo->query('SELECT * FROM sms_messages ORDER BY id')->fetchAll();$plan=LogicSmsReportImport::preview($pdo,$report,$scopes);
importCheck($plan['relevant_rows']===4&&$plan['counts']===['MATCHED'=>3,'UNRESOLVED_STATUS'=>1],'Relevant report statuses matched, working remains unknown');
importCheck(array_column($plan['matches'],'message_id')===[941,1200,1201],'Full text and owner prevent collision with another account; second test discovered without guessing ID26');
importCheck($plan['matches'][2]['evidence']['status']==='INVALID'&&$plan['matches'][2]['evidence']['acceptance_confirmed_by_report']===true,'INVALID is preserved as report evidence, not rewritten to delivered');
importCheck($plan['matches'][0]['evidence']['provider_report_credits']==='1.0000','Provider credit units retained separately from portal charges');
importCheck($plan['matches'][0]['evidence']['match_proof']['content_sha256']===hash('sha256','Test Message from IEOSUIA SMS'),'Full content hash in exact matching proof');
importCheck($pdo->query('SELECT * FROM sms_messages ORDER BY id')->fetchAll()===$before,'Preview makes no state/charge changes');
importCheck(LogicSmsReportImport::preview($pdo,$report,$scopes)['plan_sha256']===$plan['plan_sha256'],'Same exact report produces stable plan');
$pdo->exec("UPDATE sms_messages SET state='delivered' WHERE id=941");
importCheck(LogicSmsReportImport::preview($pdo,$report,$scopes)['plan_sha256']===$plan['plan_sha256'],'Already corrected rows keep stable resume plan');
$changed=$report;$changed['rows'][0]['content'].=' ';
importCheck(LogicSmsReportImport::preview($pdo,$changed,$scopes)['relevant_rows']===3,'Message whitespace is not silently discarded');
$changed=$report;$changed['rows'][0]['reported_at']='2026-10-25 01:23:05';
importCheck(LogicSmsReportImport::preview($pdo,$changed,$scopes)['rows'][0]['match_code']==='UNMATCHED','Out-of-window report cannot attach to older same text');
$pdo->exec("INSERT INTO sms_messages VALUES(1600,7,208,'+27721101883','Test 2 from IEOSUIA SMS','retry',NULL,'2026-10-24 00:58:00')");
importCheck(LogicSmsReportImport::preview($pdo,$report,$scopes)['rows'][1]['match_code']==='AMBIGUOUS','Multiple exact candidates remain unresolved');
$pdo->exec('DELETE FROM sms_messages WHERE id=1600');
$changed=$report;$changed['rows'][]=$changed['rows'][0];$caught=false;
try{LogicSmsReportImport::preview($pdo,$changed,$scopes);}catch(DomainException){$caught=true;}
importCheck($caught,'Duplicate report rows cannot double-settle a message');
$changed=$report;$changed['rows'][0]['reported_at']='2026-02-30 00:23:05';$caught=false;
try{LogicSmsReportImport::preview($pdo,$changed,$scopes);}catch(InvalidArgumentException){$caught=true;}
importCheck($caught,'Impossible date rejected');
$caught=false;try{LogicSmsReportImport::apply($pdo,$report,$scopes,str_repeat('b',64));}catch(DomainException){$caught=true;}
importCheck($caught,'Changed or tampered preview hash prevents all writes');
echo "PASS {$checks} exact report matching and tenant isolation checks; no production access\n";
