<?php
require_once __DIR__.'/../services/CustomerNotificationService.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('CONCAT',fn(...$parts)=>implode('',$parts));
$pdo->exec('CREATE TABLE notification_events(user_id INTEGER,event_key TEXT,created_at TEXT,PRIMARY KEY(user_id,event_key)); CREATE TABLE notifications(id INTEGER PRIMARY KEY,user_id INTEGER,type TEXT,title TEXT,message TEXT,data TEXT,`read` INTEGER,created_at TEXT,updated_at TEXT); CREATE TABLE sms_campaigns(id INTEGER,user_id INTEGER,name TEXT,state TEXT,completed_at TEXT); CREATE TABLE payments(id INTEGER,user_id INTEGER,status TEXT,credits_added INTEGER,processed_at TEXT)');
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$now=date('Y-m-d H:i:s');$old=date('Y-m-d H:i:s',strtotime('-40 days'));
$q=$pdo->prepare('INSERT INTO sms_campaigns VALUES(?,?,?,?,?)');foreach([[1,1,'First','completed',$now],[2,1,'Second','partially_failed',$now],[3,2,'Other owner','completed',$now],[4,1,'Queued','processing',null],[5,1,'Old','completed',$old]] as $r)$q->execute($r);
$q=$pdo->prepare('INSERT INTO payments VALUES(?,?,?,?,?)');foreach([[1,1,'completed',28,$now],[2,1,'pending',0,null],[3,2,'completed',100,$now]] as $r)$q->execute($r);
CustomerNotificationService::sync($pdo,1);
check((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===3,'Only committed recent account events');
CustomerNotificationService::sync($pdo,1);check((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===3,'No duplicates');
$row=$pdo->query("SELECT * FROM notifications WHERE type='payment_complete'")->fetch(PDO::FETCH_ASSOC);check(str_contains($row['message'],'28 SMS credits'),'Stored credits');check(json_decode($row['data'],true)['payment_id']===1,'Payment target');
$pdo->exec('DELETE FROM notifications');CustomerNotificationService::sync($pdo,1);check((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===0,'Dismissed events do not return');
CustomerNotificationService::sync($pdo,2);check((int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id=2')->fetchColumn()===2,'Owner isolation');
$pdo->exec('DROP TABLE notifications');try{CustomerNotificationService::emit($pdo,1,'test:rollback','test','Test','Test',[],$now);throw new LogicException('Expected failure');}catch(PDOException){}
check((int)$pdo->query("SELECT COUNT(*) FROM notification_events WHERE event_key='test:rollback'")->fetchColumn()===0,'Failed alert rolls back event claim');
echo "PASS: recent committed campaigns/payments, ownership, duplicate suppression, dismissal durability, credit payload and rollback\n";
