<?php
require_once __DIR__.'/../services/CustomerNotificationService.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function db():PDO{return $GLOBALS['pdo'];}
$pdo->sqliteCreateFunction('CONCAT',fn(...$parts)=>implode('',$parts));
$pdo->exec('CREATE TABLE notification_events(user_id INTEGER,event_key TEXT,created_at TEXT,PRIMARY KEY(user_id,event_key)); CREATE TABLE notifications(id INTEGER PRIMARY KEY,user_id INTEGER,type TEXT,title TEXT,message TEXT,data TEXT,`read` INTEGER,created_at TEXT,updated_at TEXT); CREATE TABLE sms_campaigns(id INTEGER,user_id INTEGER,name TEXT,state TEXT,completed_at TEXT); CREATE TABLE payments(id INTEGER,user_id INTEGER,status TEXT,credits_added INTEGER,processed_at TEXT)');
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$now=date('Y-m-d H:i:s');$old=date('Y-m-d H:i:s',strtotime('-40 days'));
$q=$pdo->prepare('INSERT INTO sms_campaigns VALUES(?,?,?,?,?)');foreach([[1,1,'First','completed',$now],[2,1,'Second','partially_failed',$now],[3,2,'Other owner','completed',$now],[4,1,'Queued','processing',null],[5,1,'Old','completed',$old]] as $r)$q->execute($r);
$pdo->exec('ALTER TABLE sms_campaigns ADD COLUMN updated_at TEXT;CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,campaign_id INTEGER,user_id INTEGER,state TEXT,sent_at TEXT,last_error TEXT,actual_charge REAL,segment_count INTEGER)');
$pdo->prepare('UPDATE sms_campaigns SET updated_at=?')->execute([$now]);
$m=$pdo->prepare('INSERT INTO sms_messages VALUES(?,?,?,?,?,?,?,?)');
foreach([[1,1,1,'sent',$now,null,.35,1],[2,1,1,'sent',$now,null,.35,1],[3,2,1,'sent',$now,null,.35,1],[4,2,1,'failed',null,'Sensitive rejection',0,1],[5,2,1,'skipped',null,'Opted out',0,1],[6,3,2,'sent',$now,null,.35,1]] as $r)$m->execute($r);
$pdo->exec('ALTER TABLE sms_messages ADD COLUMN next_attempt_at TEXT');
$q=$pdo->prepare('INSERT INTO payments VALUES(?,?,?,?,?)');foreach([[1,1,'completed',28,$now],[2,1,'pending',0,null],[3,2,'completed',100,$now]] as $r)$q->execute($r);
CustomerNotificationService::sync($pdo,1);
check((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===3,'Only committed recent account events');
CustomerNotificationService::sync($pdo,1);check((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===3,'No duplicates');
$row=$pdo->query("SELECT * FROM notifications WHERE type='payment_complete'")->fetch(PDO::FETCH_ASSOC);check(str_contains($row['message'],'28 SMS credits'),'Stored credits');check(json_decode($row['data'],true)['payment_id']===1,'Payment target');
$first=$pdo->query("SELECT * FROM notifications WHERE type='campaign_complete' ORDER BY id LIMIT 1")->fetch();check($first['title']==='Your SMS campaign has been sent successfully!','Acceptance is the primary completion message');
$pdo->exec("UPDATE sms_messages SET state='delivered' WHERE id=1");CustomerNotificationService::sync($pdo,1);
check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='delivery_updated'")->fetchColumn()===1,'Partial delivery notification appears promptly');
CustomerNotificationService::sync($pdo,1);check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='delivery_updated'")->fetchColumn()===1,'No notification per duplicate postback');
$pdo->exec("UPDATE sms_messages SET state='delivered' WHERE id=2");CustomerNotificationService::sync($pdo,1);
check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='delivery_updated'")->fetchColumn()===2,'Final delivery notification is distinct and deduplicated');
$pdo->exec('DELETE FROM notifications');CustomerNotificationService::sync($pdo,1);check((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===0,'Dismissed events do not return');
CustomerNotificationService::sync($pdo,2);check((int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id=2')->fetchColumn()===2,'Owner isolation');
$pdo->exec('CREATE TABLE credit_transfers(id TEXT PRIMARY KEY,source_user_id INTEGER,destination_user_id INTEGER,credits INTEGER,created_at TEXT);CREATE TABLE portal_security_events(event_key TEXT PRIMARY KEY,user_id INTEGER,event_type TEXT,created_at TEXT)');
$pdo->prepare('INSERT INTO credit_transfers VALUES(?,?,?,?,?)')->execute(['fixture-transfer',1,2,80,$now]);
$pdo->prepare('INSERT INTO portal_security_events VALUES(?,?,?,?)')->execute(['fixture-security',1,'password_changed',$now]);
CustomerNotificationService::sync($pdo,1);CustomerNotificationService::sync($pdo,2);
check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=1 AND type='credits_transferred'")->fetchColumn()===1,'Sender gets transfer alert');
check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='credits_received'")->fetchColumn()===1,'Receiver gets transfer alert');
check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=1 AND type='account_security'")->fetchColumn()===1,'Verified security alert');
check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='account_security'")->fetchColumn()===0,'Security event cannot cross accounts');
$notice=$pdo->query("SELECT * FROM notifications WHERE type='credits_transferred'")->fetch(PDO::FETCH_ASSOC);
check(!str_contains(json_encode($notice),'@')&&!str_contains(json_encode($notice),'password_hash'),'Transfer notice excludes counterpart email and secrets');
CustomerNotificationService::sync($pdo,1);check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='credits_transferred'")->fetchColumn()===1,'Transfer alerts deduplicate');
check(CustomerNotificationService::securityCopy('arbitrary-secret')===null,'Only fixed nonsensitive security event copies');
$pdo->exec('DROP TABLE notifications');try{CustomerNotificationService::emit($pdo,1,'test:rollback','test','Test','Test',[],$now);throw new LogicException('Expected failure');}catch(PDOException){}
check((int)$pdo->query("SELECT COUNT(*) FROM notification_events WHERE event_key='test:rollback'")->fetchColumn()===0,'Failed alert rolls back event claim');
echo "PASS: recent committed campaigns/payments, ownership, duplicate suppression, dismissal durability, credit payload and rollback\n";
