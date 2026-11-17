<?php
declare(strict_types=1);
// Run only through scripts/test-customer-health-mysql.ps1, on its disposable local server.
if(PHP_SAPI!=='cli'||!in_array('--disposable-fixture',$argv,true))throw new RuntimeException('Disposable local fixture flag required');
date_default_timezone_set('Africa/Johannesburg');
function env(string $key,mixed $default=null):mixed{return $default;}
class HealthMysqlPDO extends PDO{
    public bool $legacySkipLocked=false;
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{
        // Local XAMPP predates SKIP LOCKED. This single-process fixture still
        // tests the real due-date predicate and FOR UPDATE, not worker races.
        if($this->legacySkipLocked)$query=str_replace(' FOR UPDATE SKIP LOCKED',' FOR UPDATE',$query);
        return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args);
    }
}
$pdo=new HealthMysqlPDO('mysql:host=127.0.0.1;port=33167;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$version=(string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
$pdo->legacySkipLocked=stripos($version,'mariadb')!==false&&version_compare(explode('-',$version)[0],'10.6.0','<');
$directory=(string)$pdo->query('SELECT @@datadir')->fetchColumn();
if(!str_contains(str_replace('\\','/',$directory),'sms-health-fixture-'))throw new RuntimeException('Not the disposable customer-health server');
$database='sms_health_'.bin2hex(random_bytes(8));
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');$pdo->exec('USE `'.$database.'`');$pdo->exec("SET time_zone='+02:00'");
function db():PDO{return $GLOBALS['pdo'];}
require __DIR__.'/../domain/LogicSmsStatusSync.php';
require __DIR__.'/../domain/SmsDispatchService.php';
$pdo->exec("CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,is_active INTEGER NOT NULL) ENGINE=InnoDB;
INSERT INTO users VALUES(2,1),(9,1),(12,1);
CREATE TABLE sms_campaigns(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,state VARCHAR(30),sender_id VARCHAR(40),scheduled_at DATETIME NULL,started_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB;
INSERT INTO sms_campaigns(id,user_id,state) VALUES(10,2,'completed'),(20,9,'completed'),(30,12,'completed');
CREATE TABLE sms_messages(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,campaign_id BIGINT UNSIGNED,state VARCHAR(30),provider VARCHAR(30),provider_message_id VARCHAR(100),attempt_count INTEGER DEFAULT 1,next_attempt_at DATETIME NULL,sent_at DATETIME NULL,actual_charge DECIMAL(14,4),last_error TEXT,lease_token VARCHAR(100),leased_at DATETIME NULL,created_at DATETIME,last_dlr_checked_at DATETIME NULL,dlr_attempt_count INTEGER DEFAULT 0,updated_at DATETIME NULL) ENGINE=InnoDB;
CREATE TABLE operational_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,level VARCHAR(20),service VARCHAR(60),event VARCHAR(100),message VARCHAR(500),campaign_id BIGINT UNSIGNED,sms_message_id BIGINT UNSIGNED,user_id BIGINT UNSIGNED,context_json LONGTEXT,created_at DATETIME) ENGINE=InnoDB;");
class HealthMysqlGateway extends SmsService{
    public array $calls=[];public string $outcome='UNKNOWN';
    public function __construct(){}
    public function getStatus(string $providerMessageId,?string $clientReference=null):array{
        if(db()->inTransaction())throw new RuntimeException('Network cannot run in a transaction');
        $this->calls[]=$clientReference;
        if($this->outcome==='TRANSPORT_ERROR')return ['success'=>false,'error_code'=>'TRANSPORT_ERROR'];
        if($this->outcome==='MESSAGE_NOT_FOUND')return ['success'=>false,'error_code'=>'MESSAGE_NOT_FOUND'];
        return ['success'=>true,'status'=>'UNKNOWN','lookup_reference'=>$clientReference];
    }
    public function send(string $phone,string $message,?string $senderId=null,?string $clientReference=null):array{throw new RuntimeException('Never send from these tests');}
}
$checks=0;
function healthMysqlCheck(bool $ok,string $why):void{global$checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function healthMysqlMessage(int $id,int $user,int $campaign):void{
    $q=db()->prepare("INSERT INTO sms_messages(id,user_id,campaign_id,state,provider,provider_message_id,sent_at,actual_charge,created_at) VALUES(?,?,?,'sent','logicsms',?,DATE_SUB(NOW(),INTERVAL 10 MINUTE),.35,DATE_SUB(NOW(),INTERVAL 10 MINUTE))");
    $q->execute([$id,$user,$campaign,'synthetic-'.$id]);
}
healthMysqlMessage(1,2,10);healthMysqlMessage(2,2,10);healthMysqlMessage(3,9,20);healthMysqlMessage(4,12,30);
$gateway=new HealthMysqlGateway();
for($i=0;$i<3;$i++)LogicSmsStatusSync::run($pdo,$gateway,1);
healthMysqlCheck($gateway->calls===['portal-message-1','portal-message-3','portal-message-4'],'Real SQL rotates one-slot batches across three owners');
healthMysqlCheck((int)$pdo->query('SELECT dlr_attempt_count FROM sms_messages WHERE id=2')->fetchColumn()===0,'Large first account cannot consume all slots');
$pdo->exec('DELETE FROM sms_messages;DELETE FROM operational_logs');healthMysqlMessage(1,2,10);healthMysqlMessage(2,9,20);
$gateway=new HealthMysqlGateway();$gateway->outcome='MESSAGE_NOT_FOUND';$r=LogicSmsStatusSync::run($pdo,$gateway,2);
healthMysqlCheck($r['missing']===2&&$r['attempted']===2&&!$r['global_cooldown'],'Missing lookups continue across customers on InnoDB');
$again=LogicSmsStatusSync::run($pdo,$gateway,2);
healthMysqlCheck(!$again['global_cooldown']&&$again['attempted']===0,'Per-message backoff retains safety without a global outage');
healthMysqlCheck((int)$pdo->query("SELECT COUNT(*) FROM sms_messages WHERE state='sent' AND actual_charge=.35 AND next_attempt_at IS NULL")->fetchColumn()===2,'Missing status changes no outcome, charge or resend controls');
$pdo->exec('DELETE FROM sms_messages;DELETE FROM operational_logs');healthMysqlMessage(1,2,10);healthMysqlMessage(2,9,20);
$gateway=new HealthMysqlGateway();$gateway->outcome='TRANSPORT_ERROR';$r=LogicSmsStatusSync::run($pdo,$gateway,2);
healthMysqlCheck($r['attempted']===1&&$r['stopped_reason']==='TRANSPORT_ERROR','Actual gateway failure still stops the batch');
healthMysqlCheck(LogicSmsStatusSync::run($pdo,$gateway,2)['global_cooldown'],'Gateway outage circuit remains durable in real SQL');
$pdo->exec("DELETE FROM sms_messages;DELETE FROM operational_logs;UPDATE sms_campaigns SET state='queued',scheduled_at=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=10");
healthMysqlMessage(1,2,10);$pdo->exec("UPDATE sms_messages SET state='pending',sent_at=NULL,actual_charge=0");
healthMysqlCheck(SmsDispatchService::claimBatch(10,'not-due')===[],'Real dispatch query excludes an incorrectly queued future schedule');
$pdo->exec('UPDATE sms_campaigns SET scheduled_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=10');
healthMysqlCheck(count(SmsDispatchService::claimBatch(10,'due'))===1,'Real dispatch query accepts a due schedule');
echo "PASS: $checks disposable InnoDB account-fairness, backoff, circuit and no-early-dispatch checks. No provider or production traffic.\n";
if($pdo->legacySkipLocked)echo "NOTE: Legacy local MariaDB uses FOR UPDATE instead of the existing SKIP LOCKED clause; concurrent worker claiming is not asserted by this fixture.\n";
