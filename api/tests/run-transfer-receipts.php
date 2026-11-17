<?php
// Synthetic data only; injected no-network email transport.
$enabled='true';function env(string $key,mixed $default=null):mixed{return $key==='CREDIT_TRANSFER_RECEIPTS_ENABLED'?$GLOBALS['enabled']:$default;}
require __DIR__.'/../services/CreditTransferReceiptService.php';
$p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,email_verified_at TEXT,is_active INTEGER);INSERT INTO users VALUES(1,'sender@example.invalid','2026-01-01',1),(2,'recipient@example.invalid','2026-01-01',1);CREATE TABLE credit_transfers(id TEXT PRIMARY KEY,source_user_id INTEGER,destination_user_id INTEGER,credits INTEGER,created_at TEXT)");
$m=require __DIR__.'/../migrations/024_transfer_notifications.php';$m($p);$m($p);
$checks=0;function checkReceipt(bool $ok,string $label):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
function queueReceipt(PDO $p,int $n):array{$t=['id'=>str_pad(dechex($n),32,'0',STR_PAD_LEFT),'source_user_id'=>1,'destination_user_id'=>2,'credits'=>200,'created_at'=>date('Y-m-d H:i:s')];$p->beginTransaction();$p->prepare('INSERT INTO credit_transfers VALUES(?,?,?,?,?)')->execute(array_values($t));CreditTransferReceiptService::enqueue($p,$t);$p->commit();return $t;}
$t=queueReceipt($p,1);$sent=[];$send=function(...$args)use(&$sent,$p){checkReceipt(!$p->inTransaction(),'SMTP runs outside a transaction');$sent[]=$args;return ['success'=>true];};
$r=CreditTransferReceiptService::dispatchDue($p,25,$send);checkReceipt($r['sent']===1&&$r['held']===0,'Committed receipt sent once');checkReceipt($sent[0][0]==='sender@example.invalid'&&$sent[0][1]===200&&$sent[0][2]===$t['id'],'Only source account gets exact committed amount and reference');
CreditTransferReceiptService::dispatchDue($p,25,$send);checkReceipt(count($sent)===1,'Worker replay cannot resend');
queueReceipt($p,2);$r=CreditTransferReceiptService::dispatchDue($p,25,fn()=>['success'=>false]);checkReceipt($r['held']===1,'Unconfirmed SMTP result is held');
CreditTransferReceiptService::dispatchDue($p,25,$send);checkReceipt(count($sent)===1,'Unknown result never automatically retries');
queueReceipt($p,3);$p->exec("UPDATE credit_transfer_receipts SET status='processing',locked_at='2020-01-01' WHERE status='pending'");CreditTransferReceiptService::dispatchDue($p,25,$send);checkReceipt(count($sent)===1&&(int)$p->query("SELECT COUNT(*) FROM credit_transfer_receipts WHERE status='held'")->fetchColumn()===2,'Interrupted worker cannot duplicate SMTP acceptance');
queueReceipt($p,4);$p->exec("UPDATE users SET email='changed@example.invalid' WHERE id=1");$r=CreditTransferReceiptService::dispatchDue($p,25,$send);checkReceipt($r['held']===1&&count($sent)===1,'Changed email cannot redirect a queued receipt');
$p->exec("UPDATE users SET email='sender@example.invalid' WHERE id=1");
$t['id']=str_repeat('a',32);$p->beginTransaction();CreditTransferReceiptService::enqueue($p,$t);$p->rollBack();checkReceipt((int)$p->query('SELECT COUNT(*) FROM credit_transfer_receipts')->fetchColumn()===4,'Financial rollback removes queued email');
try{CreditTransferReceiptService::enqueue($p,$t);checkReceipt(false,'No transaction');}catch(LogicException){checkReceipt(true,'Requires financial transaction');}
$p->exec('DROP TABLE credit_transfer_receipts');$p->beginTransaction();try{CreditTransferReceiptService::enqueue($p,$t);checkReceipt(false,'Missing schema');}catch(PDOException){$p->rollBack();checkReceipt(true,'Missing queue fails closed');}
$enabled='false';checkReceipt(CreditTransferReceiptService::dispatchDue($p,25,$send)['disabled']===true,'Kill switch avoids queue and SMTP');
echo "PASS: {$checks} transfer receipt checks; no network or real emails.\n";
