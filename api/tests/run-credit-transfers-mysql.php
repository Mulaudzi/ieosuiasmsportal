<?php
declare(strict_types=1);
// Deliberately isolated loopback fixture ONLY. Never loads .env or sends email.
if(PHP_SAPI!=='cli')exit(1);
function env(string $key,mixed $default=null):mixed{return match($key){'SMS_PRICE_PER_SEGMENT'=>'0.35','CREDIT_TRANSFERS_ENABLED','CREDIT_TRANSFER_RECEIPTS_ENABLED'=>'true',default=>$default};}
require_once __DIR__.'/../domain/CreditTransferService.php';
$worker=($argv[1]??'')==='worker';$database=$worker?($argv[2]??''):'sms_transfer_'.bin2hex(random_bytes(8)).'_test';
if(!preg_match('/^sms_transfer_[a-f0-9]{16}_test$/D',$database))throw new RuntimeException('Unsafe fixture database');
$p=new PDO('mysql:host=127.0.0.1;port=33160;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
if(!$worker)$p->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$p->exec("USE `$database`");
if($worker){
    try{$result=CreditTransferService::execute($p,(int)$argv[4],$argv[3],$argv[5],true,($argv[6]??'')==='customer');echo json_encode(['status'=>200,'id'=>$result['id']]);}
    catch(CreditTransferException|CreditApprovalException $e){echo json_encode(['status'=>$e->status]);}
    exit;
}
function workers(array $specs,string $db):array{
    $jobs=[];foreach($specs as[$id,$actor,$secret,$customer]){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'worker',$db,$id,(string)$actor,$secret,$customer?'customer':'admin'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Fixture worker failed');fclose($pipes[0]);$jobs[]=[$proc,$pipes];}
    $out=[];foreach($jobs as[$proc,$pipes]){$body=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($err);$out[]=json_decode($body,true,512,JSON_THROW_ON_ERROR);}return $out;
}
$checks=0;function check(bool $condition,string $label):void{global $checks;$checks++;if(!$condition)throw new RuntimeException($label);}
function draft(int $from,int $to,int $credits):array{return ['source_user_id'=>$from,'destination_user_id'=>$to,'credits'=>$credits,'reason'=>'Synthetic authorized concurrency fixture','authorization_confirmed'=>true,'request_id'=>bin2hex(random_bytes(4)).'-2222-4333-8444-'.bin2hex(random_bytes(6))];}
function conserved(PDO $p):void{check((float)$p->query('SELECT SUM(balance) FROM wallets')->fetchColumn()===70.0,'Total wallet value conserved');check((float)$p->query('SELECT SUM(reserved) FROM wallets')->fetchColumn()===7.0,'Reservations unchanged');check((float)$p->query("SELECT SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END) FROM wallet_ledger")->fetchColumn()===0.0,'Paired ledger conserved');check((int)$p->query('SELECT COUNT(*) FROM wallets WHERE balance<reserved')->fetchColumn()===0,'No overspend');}
try{
    $p->exec("CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(255) NOT NULL,email VARCHAR(255) NOT NULL,role VARCHAR(20),is_active TINYINT,email_verified_at DATETIME) ENGINE=InnoDB;
    INSERT INTO users VALUES(1,'Admin','lufuno@ieosuia.com','admin',1,NOW()),(2,'Source','source@example.invalid','user',1,NOW()),(3,'Destination','dest@example.invalid','user',1,NOW());
    CREATE TABLE wallets(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,balance DECIMAL(10,2),reserved DECIMAL(10,2),currency VARCHAR(3),updated_at DATETIME,KEY(user_id)) ENGINE=InnoDB;
    INSERT INTO wallets VALUES(1,2,35,7,'ZAR',NOW()),(2,3,35,0,'ZAR',NOW());
    CREATE TABLE wallet_ledger(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED,wallet_id BIGINT UNSIGNED,campaign_id BIGINT UNSIGNED NULL,payment_id BIGINT UNSIGNED NULL,operation VARCHAR(30),direction VARCHAR(10),amount DECIMAL(14,4),idempotency_key VARCHAR(120) UNIQUE,metadata_json LONGTEXT,created_at DATETIME) ENGINE=InnoDB;");
    $m=require __DIR__.'/../migrations/015_credit_approval_security.php';$m($p);$m=require __DIR__.'/../migrations/021_credit_transfers.php';$m($p);$m($p);
    $m=require __DIR__.'/../migrations/024_transfer_notifications.php';$m($p);$m($p);
    $password='isolated-fixture-transfer-master';CreditApprovalSecurity::configure($p,$p->query('SELECT * FROM users WHERE id=1')->fetch(),null,$password,$password);
    $a=CreditTransferService::quote($p,1,draft(2,3,10));
    echo "Checking simultaneous replay...\n";
    $r=workers([[$a['id'],1,$password,false],[$a['id'],1,$password,false]],$database);
    check(array_column($r,'status')===[200,200],'Concurrent same-intent retries both recover receipt');check((int)$p->query('SELECT COUNT(*) FROM credit_transfers')->fetchColumn()===1,'Exactly one admin transfer');check((int)$p->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn()===2,'Exactly one debit/credit pair');conserved($p);
    $a=CreditTransferService::quote($p,1,draft(2,3,60));$b=CreditTransferService::quote($p,1,draft(2,3,60));
    echo "Checking available-balance contention...\n";
    $r=workers([[$a['id'],1,$password,false],[$b['id'],1,$password,false]],$database);$statuses=array_column($r,'status');sort($statuses);check($statuses===[200,409],'Two separately quoted requests cannot overspend');conserved($p);
    $a=CreditTransferService::quote($p,1,draft(2,3,1));$b=CreditTransferService::quote($p,1,draft(3,2,1));
    echo "Checking opposite directions...\n";
    $r=workers([[$a['id'],1,$password,false],[$b['id'],1,$password,false]],$database);check(array_column($r,'status')===[200,200],'Opposite admin transfers do not deadlock');conserved($p);
    $cq=CreditTransferService::customerQuote($p,2,['recipient_email'=>'dest@example.invalid','credits'=>1,'authorization_confirmed'=>true,'request_id'=>draft(2,3,1)['request_id']]);
    $code='';CreditTransferService::challenge($p,2,$cq['id'],function($email,$value)use(&$code){$code=$value;return ['success'=>true];});
    $r=workers([[$cq['id'],2,$code,true],[$cq['id'],2,$code,true]],$database);check(array_column($r,'status')===[200,200],'Concurrent customer code confirmation is idempotent');conserved($p);
    $a=CreditTransferService::quote($p,1,draft(3,2,1));$cq=CreditTransferService::customerQuote($p,2,['recipient_email'=>'dest@example.invalid','credits'=>1,'authorization_confirmed'=>true,'request_id'=>draft(2,3,1)['request_id']]);CreditTransferService::challenge($p,2,$cq['id'],function($email,$value)use(&$code){$code=$value;return ['success'=>true];});
    $r=workers([[$a['id'],1,$password,false],[$cq['id'],2,$code,true]],$database);check(array_column($r,'status')===[200,200],'Concurrent admin/customer opposite directions do not deadlock');conserved($p);
    $a=CreditTransferService::quote($p,1,draft(3,2,1));$balances=$p->query('SELECT * FROM wallets ORDER BY id')->fetchAll();$count=(int)$p->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn();
    $p->exec("CREATE TRIGGER fail_transfer BEFORE INSERT ON credit_transfers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic rollback test'");
    $failed=false;try{CreditTransferService::execute($p,1,$a['id'],$password,true);}catch(PDOException){$failed=true;}
    check($failed&&$balances===$p->query('SELECT * FROM wallets ORDER BY id')->fetchAll()&&$count===(int)$p->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn(),'Failure after both wallet updates rolls back the entire transfer');$p->exec('DROP TRIGGER fail_transfer');conserved($p);
    check((int)$p->query('SELECT COUNT(*) FROM credit_transfer_receipts')->fetchColumn()===(int)$p->query('SELECT COUNT(*) FROM credit_transfers')->fetchColumn(),'Every committed concurrent transfer has exactly one queued sender receipt');
    check((int)$p->query("SELECT COUNT(*) FROM credit_transfer_receipts WHERE status<>'pending' OR attempts<>0")->fetchColumn()===0,'Financial requests never dispatch SMTP');
    echo "Credit transfers: {$checks} isolated MariaDB checks passed; concurrency, conservation and rollback. No live systems contacted.\n";
}finally{if($p->inTransaction())$p->rollBack();$p->exec("DROP DATABASE `$database`");}
