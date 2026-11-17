<?php
declare(strict_types=1);
// Only synthetic SQLite fixtures. Never loads .env, sends or contacts production.
$transferEnabled='true';$transferPrice='0.35';
function env(string $key,mixed $default=null):mixed{global $transferEnabled,$transferPrice;return match($key){'CREDIT_TRANSFERS_ENABLED'=>$transferEnabled,'CREDIT_TRANSFER_RECEIPTS_ENABLED'=>'true','SMS_PRICE_PER_SEGMENT'=>$transferPrice,default=>$default};}
require_once __DIR__.'/../domain/CreditTransferService.php';
$p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$p->exec('PRAGMA foreign_keys=ON');
$p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,role TEXT,is_active INTEGER,email_verified_at TEXT);
INSERT INTO users VALUES(1,'Admin','lufuno@ieosuia.com','admin',1,'2026-01-01'),(2,'Source','source@example.invalid','user',1,'2026-01-01'),(3,'Destination','dest@example.invalid','user',1,'2026-01-01'),(4,'Other admin','admin2@example.invalid','admin',1,'2026-01-01'),(5,'Third customer','third@example.invalid','user',1,'2026-01-01');
CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER,balance TEXT,reserved TEXT,currency TEXT,updated_at TEXT);
INSERT INTO wallets VALUES(1,2,'35.0000','7.0000','ZAR','old'),(2,3,'3.5000','0.3500','ZAR','old'),(3,5,'0.0000','0.0000','ZAR','old');
CREATE TABLE wallet_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,wallet_id INTEGER,campaign_id INTEGER,payment_id INTEGER,operation TEXT,direction TEXT,amount TEXT,idempotency_key TEXT UNIQUE,metadata_json TEXT,created_at TEXT);
CREATE TABLE credit_approval_security(id INTEGER PRIMARY KEY,password_hash TEXT,version INTEGER,updated_by INTEGER,updated_at TEXT);
INSERT INTO credit_approval_security(id,version) VALUES(1,0);
CREATE TABLE credit_approval_attempts(user_id INTEGER PRIMARY KEY,failures INTEGER,window_started INTEGER,blocked_until INTEGER);
CREATE TABLE credit_approval_events(id INTEGER PRIMARY KEY,user_id INTEGER,event TEXT,order_id INTEGER,security_version INTEGER,created_at TEXT);
CREATE TABLE payments(id INTEGER,amount TEXT,status TEXT);INSERT INTO payments VALUES(1,'35.00','completed');
CREATE TABLE sms_messages(id INTEGER,state TEXT,actual_charge TEXT);INSERT INTO sms_messages VALUES(1,'processing','0.0000');
CREATE TABLE customer_cash_balances(id INTEGER,balance TEXT);INSERT INTO customer_cash_balances VALUES(1,'10.00');");
$checks=0;function transferCheck(bool $ok,string $label):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
function transferFails(callable $f,int $status,string $label):void{try{$f();}catch(CreditTransferException|CreditApprovalException $e){transferCheck($e->status===$status,$label.' status '.$e->status);return;}throw new RuntimeException('Did not reject '.$label);}
function transferRequest(array $replace=[]):array{return $replace+['source_user_id'=>'2','destination_user_id'=>'3','credits'=>30,'reason'=>'Approved account-to-account allocation','authorization_confirmed'=>true,'request_id'=>substr(bin2hex(random_bytes(16)),0,8).'-2222-4333-8444-'.bin2hex(random_bytes(6))];}
function moneySnapshot(PDO $p):array{$out=[];foreach(['wallets','wallet_ledger','credit_transfers']as$t)$out[$t]=$p->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll();return $out;}
$password='offline-transfer-master-fixture-2026';CreditApprovalSecurity::configure($p,$p->query('SELECT * FROM users WHERE id=1')->fetch(),null,$password,$password);
transferCheck(!CreditTransferService::available($p),'Missing migration detected');
transferFails(fn()=>CreditTransferService::quote($p,1,transferRequest()),503,'No auto-migration');
$migration=require __DIR__.'/../migrations/021_credit_transfers.php';$migration($p);$migration($p);transferCheck(CreditTransferService::available($p),'Additive migration rerunnable');
$repair=require __DIR__.'/../migrations/024_transfer_notifications.php';$repair($p);$repair($p);
$protected=[];foreach(['payments','sms_messages','customer_cash_balances']as$t)$protected[$t]=$p->query('SELECT * FROM '.$t)->fetchAll();
$transferEnabled='false';transferFails(fn()=>CreditTransferService::quote($p,1,transferRequest()),503,'Launch switch off blocks mutation');$transferEnabled='true';
foreach([['credits'=>0],['credits'=>-1],['credits'=>1.5],['credits'=>'1e2'],['credits'=>'01'],['credits'=>true],['credits'=>'1000001'],['source_user_id'=>3],['destination_user_id'=>99],['authorization_confirmed'=>'true'],['reason'=>'short'],['amount'=>'0.01'],['actor_user_id'=>4]]as$bad){$status=isset($bad['destination_user_id'])&&$bad['destination_user_id']===99?409:422;transferFails(fn()=>CreditTransferService::quote($p,1,transferRequest($bad)),$status,'Malformed or unsafe quote');}
transferFails(fn()=>CreditTransferService::quote($p,2,transferRequest()),403,'Customer cannot move any wallet');
$p->exec('UPDATE users SET is_active=0 WHERE id=4');transferFails(fn()=>CreditTransferService::quote($p,4,transferRequest()),403,'Inactive admin forbidden');$p->exec('UPDATE users SET is_active=1 WHERE id=4');
transferFails(fn()=>CreditTransferService::quote($p,1,transferRequest(['credits'=>81])),409,'Reserved credits unavailable');
$before=moneySnapshot($p);$input=transferRequest();$quote=CreditTransferService::quote($p,1,$input);
transferCheck(moneySnapshot($p)===$before,'Quote does not reserve or move funds');transferCheck($quote['credits']===30&&$quote['source_available_after']===50&&$quote['destination_available_after']===39,'Exact credit-count preview');
transferCheck(CreditTransferService::quote($p,1,$input)['id']===$quote['id'],'Quote retry has one stable identity');transferFails(fn()=>CreditTransferService::quote($p,1,['credits'=>31]+$input),409,'Quote request cannot change its intention');
transferFails(fn()=>CreditTransferService::execute($p,4,$quote['id'],$password,true),404,'Another admin cannot execute a private review');
transferFails(fn()=>CreditTransferService::execute($p,1,$quote['id'],$password,false),422,'Explicit confirmation required');
transferFails(fn()=>CreditTransferService::execute($p,1,$quote['id'],'wrong-password',true),422,'Wrong master blocks transfer');
transferCheck(moneySnapshot($p)===$before,'Wrong password leaves money unchanged');transferCheck((int)$p->query('SELECT failures FROM credit_approval_attempts WHERE user_id=1')->fetchColumn()===1,'Failed master attempt persists');
$receipt=CreditTransferService::execute($p,1,$quote['id'],$password,true);
transferCheck((int)$p->query('SELECT COUNT(*) FROM credit_transfer_receipts')->fetchColumn()===1,'Committed transfer queues one sender receipt without SMTP');
transferCheck($p->query('SELECT recipient_email FROM credit_transfer_receipts')->fetchColumn()==='source@example.invalid','Receipt goes to the source, not the acting administrator');
transferCheck($receipt['status']==='completed'&&$receipt['credits']===30,'Transfer completes');
transferCheck($p->query('SELECT balance FROM wallets WHERE id=1')->fetchColumn()==='24.5000'&&$p->query('SELECT balance FROM wallets WHERE id=2')->fetchColumn()==='14.0000','Equal-value source debit and destination credit');
transferCheck($p->query('SELECT reserved FROM wallets WHERE id=1')->fetchColumn()==='7.0000'&&$p->query('SELECT reserved FROM wallets WHERE id=2')->fetchColumn()==='0.3500','Both reservations preserved');
transferCheck((int)$p->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn()===2,'Exactly two canonical ledger entries');
transferCheck((float)$p->query("SELECT SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END) FROM wallet_ledger")->fetchColumn()===0.0,'Transfer ledger conserves total value');
$after=moneySnapshot($p);transferCheck(CreditTransferService::execute($p,1,$quote['id'],null,true)['id']===$receipt['id']&&moneySnapshot($p)===$after,'Response-loss replay never repeats a debit');
transferCheck((int)$p->query('SELECT COUNT(*) FROM credit_transfer_receipts')->fetchColumn()===1,'Response-loss replay never queues a second email');
$own=CreditTransferService::history($p,2,false);transferCheck($own['total']===1&&$own['transfers'][0]['direction']==='sent','Source sees own transfer history');
$own=CreditTransferService::history($p,3,false);transferCheck($own['transfers'][0]['direction']==='received'&&!isset($own['transfers'][0]['source_email'],$own['transfers'][0]['reason']),'Recipient sees history without unrelated account or admin details');
transferCheck(CreditTransferService::history($p,5,false)['total']===0,'Other customer history isolated');
transferCheck(CreditTransferService::history($p,1,true)['transfers'][0]['reason']===$input['reason'],'Admin audit retains authorization reason');
$search=CreditTransferService::accounts($p,1,'source');transferCheck(count($search)===1&&$search[0]['available_credits']===50,'Account search shows actual unreserved credits');
$fresh=fn()=>CreditTransferService::quote($p,1,transferRequest(['credits'=>1]));
$expired=$fresh();$p->prepare('UPDATE credit_transfer_quotes SET expires_at=0 WHERE id=?')->execute([$expired['id']]);transferFails(fn()=>CreditTransferService::execute($p,1,$expired['id'],$password,true),409,'Expired quote rejected');
$changed=$fresh();$transferPrice='0.40';transferFails(fn()=>CreditTransferService::execute($p,1,$changed['id'],$password,true),409,'Price change requires new review');$transferPrice='0.35';
$changed=$fresh();$p->exec("UPDATE users SET email='changed@example.invalid' WHERE id=3");transferFails(fn()=>CreditTransferService::execute($p,1,$changed['id'],$password,true),409,'Changed destination identity requires new review');$p->exec("UPDATE users SET email='dest@example.invalid' WHERE id=3");
$changed=$fresh();$p->exec('UPDATE users SET is_active=0 WHERE id=3');transferFails(fn()=>CreditTransferService::execute($p,1,$changed['id'],$password,true),409,'Deactivated recipient rejected after quote');$p->exec('UPDATE users SET is_active=1 WHERE id=3');
$changed=$fresh();$p->exec("UPDATE wallets SET reserved='24.5000' WHERE id=1");transferFails(fn()=>CreditTransferService::execute($p,1,$changed['id'],$password,true),409,'New SMS reservation cannot be spent');$p->exec("UPDATE wallets SET reserved='7.0000' WHERE id=1");
$changed=$fresh();$p->exec("CREATE TRIGGER block_transfer BEFORE INSERT ON credit_transfers BEGIN SELECT RAISE(ABORT,'fixture failure'); END");$snap=moneySnapshot($p);$threw=false;try{CreditTransferService::execute($p,1,$changed['id'],$password,true);}catch(PDOException){$threw=true;}transferCheck($threw&&moneySnapshot($p)===$snap&&!$p->inTransaction(),'Failure after ledger and wallet changes rolls back both sides');$p->exec('DROP TRIGGER block_transfer');
$p->exec("INSERT INTO wallets VALUES(9,3,'1.0000','0.0000','ZAR','old')");transferFails(fn()=>CreditTransferService::quote($p,1,transferRequest(['credits'=>1])),409,'Legacy duplicate wallets rejected');$p->exec('DELETE FROM wallets WHERE id=9');
$p->exec("UPDATE wallets SET currency='USD' WHERE id=2");transferFails(fn()=>CreditTransferService::quote($p,1,transferRequest(['credits'=>1])),409,'Cross-currency transfer rejected');$p->exec("UPDATE wallets SET currency='ZAR' WHERE id=2");
foreach($protected as$t=>$rows)transferCheck($rows===$p->query('SELECT * FROM '.$t)->fetchAll(),'Existing '.$t.' preserved');
transferCheck((int)$p->query("SELECT COUNT(*) FROM credit_approval_events WHERE event='credits_transferred'")->fetchColumn()===1,'Exactly one successful finance audit');
transferCheck(CreditTransferService::status($p,1,$quote['id'])['status']==='completed','Completed status is read-only recovery');
transferFails(fn()=>CreditTransferService::status($p,4,$quote['id']),404,'Private status ownership');
transferCheck(CreditTransferService::status($p,1,$expired['id'])['new_review_allowed']===true,'Expired incomplete review permits a fresh intent');
$customerInput=fn(array $extra=[])=>$extra+['recipient_email'=>'dest@example.invalid','credits'=>5,'authorization_confirmed'=>true,'request_id'=>transferRequest()['request_id']];
transferFails(fn()=>CreditTransferService::customerQuote($p,2,$customerInput(['source_user_id'=>5])),422,'Customer cannot choose another source');
transferFails(fn()=>CreditTransferService::customerQuote($p,2,$customerInput(['recipient_email'=>'absent@example.invalid'])),422,'Unknown exact recipient rejected');
transferFails(fn()=>CreditTransferService::customerQuote($p,2,$customerInput(['recipient_email'=>'source@example.invalid'])),422,'Customer self-transfer rejected');
transferFails(fn()=>CreditTransferService::customerQuote($p,1,$customerInput()),403,'Admin cannot impersonate customer confirmation');
$draft=$customerInput();$cq=CreditTransferService::customerQuote($p,2,$draft);
transferCheck(!isset($cq['destination_available_after'])&&$cq['destination']['id']==='recipient','Recipient balance and internal account ID not exposed');
transferCheck(CreditTransferService::customerQuote($p,2,$draft)['id']===$cq['id'],'Customer review idempotent');
transferFails(fn()=>CreditTransferService::execute($p,2,$cq['id'],$password,true),403,'Customer cannot use admin approval path');
transferFails(fn()=>CreditTransferService::execute($p,3,$cq['id'],'123456',true,true),404,'Recipient cannot authorize source debit');
transferFails(fn()=>CreditTransferService::execute($p,2,$cq['id'],'123456',true,true),422,'Customer must request a code');
transferFails(fn()=>CreditTransferService::challenge($p,3,$cq['id'],fn()=>['success'=>true]),404,'Code request ownership');
$sent=[];$mail=function($email,$code,$credits,$recipient,$reference)use(&$sent){$sent=compact('email','code','credits','recipient','reference');return ['success'=>true];};
$snap=moneySnapshot($p);$challenge=CreditTransferService::challenge($p,2,$cq['id'],$mail);
transferCheck($sent['email']==='source@example.invalid'&&$sent['recipient']==='dest@example.invalid'&&$sent['credits']===5,'Code sent to source with exact reviewed intent');
transferCheck(moneySnapshot($p)===$snap&&!str_contains(json_encode($challenge),$sent['code']),'Challenge never moves funds or returns a code');
transferCheck($p->query("SELECT code_hash FROM credit_transfer_quotes WHERE id='".$cq['id']."'")->fetchColumn()!==$sent['code'],'Only code hash stored');
transferFails(fn()=>CreditTransferService::challenge($p,2,$cq['id'],$mail),429,'No duplicate code regeneration');
transferFails(fn()=>CreditTransferService::execute($p,2,$cq['id'],'000000',true,true),422,'Incorrect code denied');
transferCheck((int)$p->query('SELECT failures FROM credit_transfer_security WHERE user_id=2')->fetchColumn()===1,'Incorrect code count persists');
transferFails(fn()=>CreditTransferService::execute($p,2,$cq['id'],$sent['code'],false,true),422,'Code does not bypass explicit confirmation');
$customerReceipt=CreditTransferService::execute($p,2,$cq['id'],$sent['code'],true,true);
transferCheck($customerReceipt['credits']===5&&!isset($customerReceipt['destination_available_after'],$customerReceipt['destination_user_id']),'Customer receipt excludes recipient balance and private ID');
$snap=moneySnapshot($p);transferCheck(CreditTransferService::execute($p,2,$cq['id'],null,true,true)['id']===$cq['id']&&moneySnapshot($p)===$snap,'Customer replay recovers result without another debit');
transferCheck(empty($p->query("SELECT code_hash FROM credit_transfer_quotes WHERE id='".$cq['id']."'")->fetchColumn()),'Code discarded on completion');
transferCheck(CreditTransferService::status($p,2,$cq['id'],true)['transfer']['credits']===5,'Customer recovers owned receipt');
$wrong=CreditTransferService::customerQuote($p,2,$customerInput());CreditTransferService::challenge($p,2,$wrong['id'],$mail);
for($i=0;$i<5;$i++)transferFails(fn()=>CreditTransferService::execute($p,2,$wrong['id'],'000000',true,true),$i===4?429:422,'Brute-force attempt denied');
transferFails(fn()=>CreditTransferService::execute($p,2,$wrong['id'],$sent['code'],true,true),429,'Correct code cannot bypass active lockout');
$p->exec('UPDATE credit_transfer_security SET blocked_until=0,failures=0 WHERE user_id=2');
$p->prepare('UPDATE credit_transfer_quotes SET code_expires_at=0 WHERE id=?')->execute([$wrong['id']]);transferFails(fn()=>CreditTransferService::execute($p,2,$wrong['id'],$sent['code'],true,true),422,'Expired code denied');
$failedMail=CreditTransferService::customerQuote($p,2,$customerInput());
transferFails(fn()=>CreditTransferService::challenge($p,2,$failedMail['id'],fn()=>['success'=>false]),503,'Mail failure fails closed');
transferCheck(moneySnapshot($p)===$snap,'All failed confirmation flows preserve money');
$fourth=CreditTransferService::customerQuote($p,2,$customerInput());transferFails(fn()=>CreditTransferService::challenge($p,2,$fourth['id'],$mail),429,'Database-backed account code rate limit across quotes');
$transferEnabled='false';transferCheck(CreditTransferService::status($p,2,$cq['id'],true)['status']==='completed','Kill switch preserves status readback');transferFails(fn()=>CreditTransferService::execute($p,2,$cq['id'],null,true,true),503,'Kill switch blocks commit even replays');
foreach($protected as$t=>$rows)transferCheck($rows===$p->query('SELECT * FROM '.$t)->fetchAll(),'Customer transfer preserves '.$t);
$transferEnabled='true';$queueFailure=CreditTransferService::quote($p,1,transferRequest(['credits'=>1]));$snapshot=moneySnapshot($p);
$p->exec('ALTER TABLE credit_transfer_receipts RENAME TO fixture_saved_receipts');
try{CreditTransferService::execute($p,1,$queueFailure['id'],$password,true);transferCheck(false,'Missing receipt queue accepted');}
catch(PDOException){transferCheck(moneySnapshot($p)===$snapshot,'Receipt enqueue failure rolls back the entire debit, credit and transfer record');}
finally{$p->exec('ALTER TABLE fixture_saved_receipts RENAME TO credit_transfer_receipts');}
echo "Credit transfers: {$checks} isolated SQLite checks passed. No live wallets, sends, payments or migrations.\n";
