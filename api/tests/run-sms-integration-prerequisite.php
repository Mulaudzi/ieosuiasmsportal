<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--disposable-fixture')throw new RuntimeException('Explicit disposable fixture required');
$admin=new PDO('mysql:host=127.0.0.1;port=33169','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='sms_api_fixture_'.bin2hex(random_bytes(6));$admin->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
$pdo=new PDO('mysql:host=127.0.0.1;port=33169;dbname='.$name,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB; INSERT INTO users VALUES(1);
CREATE TABLE sms_campaigns(id BIGINT PRIMARY KEY,user_id BIGINT UNSIGNED,idempotency_key VARCHAR(80),queued_at DATETIME NULL,content TEXT) ENGINE=InnoDB;
INSERT INTO sms_campaigns VALUES(1,1,'original-synthetic-job','2026-11-01 10:00:00','Synthetic existing content');
CREATE TABLE schema_migrations(version VARCHAR(100) PRIMARY KEY,applied_at DATETIME) ENGINE=InnoDB;
INSERT INTO schema_migrations VALUES('001_existing','2026-11-01 10:00:00')");
require __DIR__.'/../migrations/Migration.php';
$before=$pdo->query('SELECT * FROM sms_campaigns')->fetchAll();$checks=0;
$check=static function(bool $ok,string $what)use(&$checks):void{if(!$ok)throw new RuntimeException($what);$checks++;};
$migration=require __DIR__.'/../migrations/018_phase1_sms_integrity.php';$migration($pdo);
$check(Migration::columnExists($pdo,'sms_campaigns','request_fingerprint'),'Fingerprint column');
$check(Migration::columnExists($pdo,'sms_campaigns','quota_reserved_at'),'Quota timestamp column');
$check(Migration::tableExists($pdo,'sms_daily_quota_usage'),'Daily quota table');
$after=$pdo->query('SELECT id,user_id,idempotency_key,queued_at,content FROM sms_campaigns')->fetchAll();
$check($before===$after,'Existing campaign values preserved');
$check($pdo->query('SELECT request_fingerprint,quota_reserved_at FROM sms_campaigns')->fetch()===['request_fingerprint'=>null,'quota_reserved_at'=>null],'No historical values invented');
$check((int)$pdo->query('SELECT COUNT(*) FROM sms_daily_quota_usage')->fetchColumn()===0,'No historical quota inferred');
$history=$pdo->query('SELECT * FROM schema_migrations')->fetchAll();$check(count($history)===1&&$history[0]['version']==='001_existing','Migration closure does not rewrite bookkeeping');
$migration($pdo);$check($pdo->query('SELECT id,user_id,idempotency_key,queued_at,content FROM sms_campaigns')->fetchAll()===$before,'Repeat DDL remains additive');
$pdo->exec("INSERT INTO sms_daily_quota_usage VALUES(1,'2026-11-05',2,NOW())");$duplicate=false;
try{$pdo->exec("INSERT INTO sms_daily_quota_usage VALUES(1,'2026-11-05',3,NOW())");}catch(PDOException){$duplicate=true;}
$check($duplicate,'Account/day uniqueness enforced');
$foreign=false;try{$pdo->exec("INSERT INTO sms_daily_quota_usage VALUES(99,'2026-11-05',3,NOW())");}catch(PDOException){$foreign=true;}
$check($foreign,'Real unsigned user foreign key enforced');
$check(strtolower((string)$pdo->query("SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sms_daily_quota_usage'")->fetchColumn())==='innodb','Quota is transactional');
echo json_encode(['passed'=>true,'checks'=>$checks,'fixture_database'=>$name,'production_access'=>false,'customer_records_changed'=>false],JSON_PRETTY_PRINT)."\n";
