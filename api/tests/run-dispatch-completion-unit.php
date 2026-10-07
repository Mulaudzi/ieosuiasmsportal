<?php
require_once __DIR__.'/../domain/SmsDispatchService.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$clock='2026-10-03 19:03:06';$pdo->sqliteCreateFunction('NOW',function()use(&$clock){return $clock;});$pdo->sqliteCreateFunction('IF',fn($condition,$yes,$no)=>$condition?$yes:$no);
$pdo->exec("CREATE TABLE sms_messages(campaign_id INTEGER,state TEXT,actual_charge REAL); CREATE TABLE sms_campaigns(id INTEGER,state TEXT,queued_count INTEGER,processing_count INTEGER,sent_count INTEGER,delivered_count INTEGER,failed_count INTEGER,skipped_count INTEGER,actual_cost REAL,completed_at TEXT,updated_at TEXT); INSERT INTO sms_campaigns(id,state) VALUES(1,'processing'); INSERT INTO sms_messages VALUES(1,'sent',0.35),(1,'sent',0.35)");
SmsDispatchService::refreshCampaign($pdo,1);
if($pdo->query('SELECT completed_at FROM sms_campaigns')->fetchColumn()!==$clock)throw new RuntimeException('Initial completion not recorded');
$clock='2026-10-06 00:12:26';$pdo->exec("UPDATE sms_messages SET state='delivered'");SmsDispatchService::refreshCampaign($pdo,1);
$r=$pdo->query('SELECT * FROM sms_campaigns')->fetch(PDO::FETCH_ASSOC);
if($r['completed_at']!=='2026-10-03 19:03:06'||(int)$r['delivered_count']!==2)throw new RuntimeException('DLR changed dispatch completion');
$pdo->exec("UPDATE sms_messages SET state='failed'");SmsDispatchService::refreshCampaign($pdo,1);
$r=$pdo->query('SELECT * FROM sms_campaigns')->fetch(PDO::FETCH_ASSOC);
if($r['completed_at']!=='2026-10-03 19:03:06'||(int)$r['failed_count']!==2)throw new RuntimeException('Failure changed dispatch completion');
echo "PASS: dispatch completion timestamp remains stable through later delivery outcomes; counts still refresh\n";
