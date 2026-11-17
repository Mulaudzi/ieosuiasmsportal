<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../domain/CreditTransferSetup.php';
$name='sms_transfer_setup_'.bin2hex(random_bytes(8)).'_test';
if(!preg_match('/^sms_transfer_setup_[a-f0-9]{16}_test$/D',$name))throw new RuntimeException('Unsafe fixture name');
$p=new PDO('mysql:host=127.0.0.1;port=33160;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$p->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$p->exec("USE `$name`");
$checks=0;
function setupCheck(bool $ok,string $label):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
function setupReject(callable $f,string $label):void{try{$f();}catch(RuntimeException){setupCheck(true,$label);return;}throw new RuntimeException('Did not reject: '.$label);}
function setupTables(PDO $p):array{return $p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);}
function setupSnapshot(PDO $p):array{$out=[];foreach(['users','wallets','wallet_ledger','payments','sms_messages']as$t)$out[$t]=$p->query('SELECT * FROM '.$t.' ORDER BY id')->fetchAll();return $out;}
try{
    $p->exec("CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;INSERT INTO users VALUES(1),(2);
    CREATE TABLE wallets(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,balance DECIMAL(10,2) NOT NULL,reserved DECIMAL(10,2) NOT NULL,currency VARCHAR(3)) ENGINE=InnoDB;INSERT INTO wallets VALUES(1,1,175,1.75,'ZAR'),(2,2,35,0,'ZAR');
    CREATE TABLE wallet_ledger(id BIGINT UNSIGNED PRIMARY KEY,idempotency_key VARCHAR(120) UNIQUE,amount DECIMAL(14,4)) ENGINE=InnoDB;INSERT INTO wallet_ledger VALUES(1,'unchanged-fixture',175);
    CREATE TABLE payments(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(30)) ENGINE=InnoDB;INSERT INTO payments VALUES(1,'completed');
    CREATE TABLE sms_messages(id BIGINT UNSIGNED PRIMARY KEY,state VARCHAR(30)) ENGINE=InnoDB;INSERT INTO sms_messages VALUES(1,'sent');
    CREATE TABLE schema_migrations(version VARCHAR(100) PRIMARY KEY,applied_at DATETIME NOT NULL) ENGINE=InnoDB;INSERT INTO schema_migrations VALUES('001_mvp_foundation','2026-01-01 00:00:00'),('019_email_campaigns','2026-01-02 00:00:00');");
    $runner=new CreditTransferSetup($p,__DIR__.'/../migrations');$initialTables=setupTables($p);$initialData=setupSnapshot($p);
    $inspection=$runner->inspect();setupCheck($inspection['ready_to_apply']&&$inspection['security_prerequisite_permission_required'],'Inspection identifies optional prerequisite permission');
    setupCheck(setupTables($p)===$initialTables&&setupSnapshot($p)===$initialData,'Inspection has no DDL or customer writes');
    setupReject(fn()=>$runner->apply('019_email_campaigns',true,true),'No other migration can be named');
    setupReject(fn()=>$runner->apply('021_credit_transfers',false,true),'Verified backup attestation required');
    setupReject(fn()=>$runner->apply('021_credit_transfers',true),'Missing prerequisite cannot be silently applied');
    setupCheck(setupTables($p)===$initialTables,'Blocked apply has no DDL');
    $p->exec("INSERT INTO wallets VALUES(3,1,0,0,'ZAR')");setupCheck(!$runner->inspect()['ready_to_apply'],'Duplicate wallets block preflight');setupReject(fn()=>$runner->apply('021_credit_transfers',true,true),'Duplicates reject before any schema creation');$p->exec('DELETE FROM wallets WHERE id=3');
    $bad=new CreditTransferSetup($p,__DIR__.'/fixtures');setupCheck(!$bad->inspect()['ready_to_apply'],'Missing approved migration bytes block inspection');
    setupReject(fn()=>$bad->apply('021_credit_transfers',true,true),'Wrong migration path cannot run code');
    echo "Checking allowlisted prerequisite and schema application...\n";
    $result=$runner->apply('021_credit_transfers',true,true);
    setupCheck($result['code']==='CREDIT_TRANSFERS_READY'&&$result['security_prerequisite_applied'],'Only explicit 015 prerequisite and 021 are applied');
    setupCheck(setupSnapshot($p)===$initialData,'Wallets, ledger, SMS and payments unchanged');
    setupCheck(!$result['credits_moved']&&!$result['activation_flag_changed']&&!$result['application_deployed'],'Schema setup never moves credits or activates/deploys application');
    setupCheck($p->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)===['001_mvp_foundation','015_credit_approval_security','019_email_campaigns','021_credit_transfers'],'Only the allowlisted migration markers are appended');
    setupCheck($p->query("SELECT applied_at FROM schema_migrations WHERE version='019_email_campaigns'")->fetchColumn()==='2026-01-02 00:00:00','Existing email marker untouched');
    setupCheck((int)$p->query('SELECT COUNT(*) FROM credit_transfers')->fetchColumn()===0,'No transfer created');
    $p->exec("UPDATE credit_approval_security SET password_hash='existing-hash-fixture',version=7 WHERE id=1");$security=$p->query('SELECT * FROM credit_approval_security')->fetchAll();
    setupCheck($runner->apply('021_credit_transfers',true)['code']==='ALREADY_APPLIED','Rerun verifies completed setup without repeated DDL');
    setupCheck($p->query('SELECT * FROM credit_approval_security')->fetchAll()===$security,'Existing finance master hash/version preserved');
    $p->exec('CREATE INDEX fixture_source_fk ON credit_transfers(source_user_id)');$p->exec('ALTER TABLE credit_transfers DROP INDEX idx_credit_transfer_source');setupCheck(!$runner->inspect()['ready_to_apply'],'Changed index detected');setupReject(fn()=>$runner->apply('021_credit_transfers',true),'Recorded incompatible schema not silently repaired');$p->exec('CREATE INDEX idx_credit_transfer_source ON credit_transfers(source_user_id,created_at)');$p->exec('ALTER TABLE credit_transfers DROP INDEX fixture_source_fk');
    $p->exec("DELETE FROM schema_migrations WHERE version='021_credit_transfers'");setupCheck($runner->inspect()['partial_setup_requires_explicit_resume'],'Unrecorded tables identified as partial');
    setupReject(fn()=>$runner->apply('021_credit_transfers',true),'Partial setup requires explicit review/resume');
    setupCheck($runner->apply('021_credit_transfers',true,false,true)['code']==='CREDIT_TRANSFERS_READY','Compatible partial setup can be explicitly resumed');
    $p->exec('ALTER TABLE credit_transfer_quotes DROP COLUMN code_hash');setupCheck(!$runner->inspect()['ready_to_apply'],'Missing verification column detected');setupReject(fn()=>$runner->apply('021_credit_transfers',true),'Incomplete customer verification schema cannot be approved');
    setupCheck(setupSnapshot($p)===$initialData,'All maintenance checks preserve financial and customer data');
    echo "PASS {$checks} isolated MySQL/MariaDB migration-runner checks. No live database, email, SMS or payment access.\n";
}finally{if($p->inTransaction())$p->rollBack();$p->exec("DROP DATABASE `$name`");}
