<?php
// Disposable loopback MariaDB only. Schema comes from a read-only production DDL snapshot, never customer rows.
function env($k,$default=null){return $k==='CREDIT_TRANSFER_RECEIPTS_ENABLED'?'true':$default;}
require_once __DIR__.'/../services/CustomerNotificationService.php';
require_once __DIR__.'/../services/CreditTransferReceiptService.php';
require_once __DIR__.'/../core/QueryBuilder.php';
require_once __DIR__.'/../controllers/NotificationController.php';
class Auth{public static int $id=1;public static function id():int{return self::$id;}}
class Request{public static function query(string $key,mixed $default=null):mixed{return $default;}}
class Response{public static array $data=[];public static function success(array $value):void{self::$data=$value;}}
function connection(string $database=''):PDO{return new PDO('mysql:host=127.0.0.1;port=33164;'.($database?'dbname='.$database.';':''),'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
function db():PDO{return $GLOBALS['p'];}
if(($argv[1]??'')==='--child'){
 if(!preg_match('/^transfer_notice_fixture_[a-f0-9]{16}$/D',$argv[2]??''))exit(64);
 $p=connection($argv[2]);$r=CreditTransferReceiptService::dispatchDue($p,25,static function(){usleep(150000);return ['success'=>true];});echo json_encode($r);exit;
}
$snapshot=json_decode(file_get_contents($argv[1]??''),true,512,JSON_THROW_ON_ERROR);
if(!str_starts_with($snapshot['schema']['notifications']??'','CREATE TABLE `notifications`'))throw new RuntimeException('Notification schema snapshot required');
$database='transfer_notice_fixture_'.bin2hex(random_bytes(8));connection()->exec("CREATE DATABASE {$database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$p=connection($database);$n=0;
function checkMysql($ok,$why){global$n;$n++;if(!$ok)throw new RuntimeException($why);}
$p->exec("CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(254),email_verified_at DATETIME,is_active INTEGER) ENGINE=InnoDB;INSERT INTO users VALUES(1,'sender@example.invalid',NOW(),1),(2,'recipient@example.invalid',NOW(),1)");
$p->exec($snapshot['schema']['notifications']);
$p->exec("CREATE TABLE sms_campaigns(id BIGINT,user_id BIGINT,name VARCHAR(255),state VARCHAR(40),completed_at DATETIME,updated_at DATETIME) ENGINE=InnoDB;
CREATE TABLE sms_messages(campaign_id BIGINT,user_id BIGINT,state VARCHAR(40),sent_at DATETIME,last_error TEXT,actual_charge DECIMAL(14,4)) ENGINE=InnoDB;
CREATE TABLE payments(id BIGINT,user_id BIGINT,status VARCHAR(40),credits_added INTEGER,processed_at DATETIME) ENGINE=InnoDB;
CREATE TABLE credit_transfers(id CHAR(32) PRIMARY KEY,source_user_id BIGINT,destination_user_id BIGINT,credits INTEGER,created_at DATETIME) ENGINE=InnoDB");
try{CustomerNotificationService::sync($p,1);throw new RuntimeException('Missing prerequisite accepted');}catch(RuntimeException $e){checkMysql(str_contains($e->getMessage(),'schema setup'),'Reproduces missing notification_events prerequisite');}
$m=require __DIR__.'/../migrations/024_transfer_notifications.php';$m($p);$m($p);
$p->exec("INSERT INTO credit_transfers VALUES('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,2,200,NOW())");
foreach([1,2]as$id)checkMysql(CustomerNotificationService::sync($p,$id)['available'],'Reconciliation runs on MySQL');
foreach([1,2]as$id){Auth::$id=$id;(new NotificationController())->index();checkMysql(Response::$data['sync_available']&&count(Response::$data['notifications'])===1&&Response::$data['unread_count']===1,'Real notification controller returns reconciled account-only alert');checkMysql(is_array(Response::$data['notifications'][0]['data'])&&Response::$data['notifications'][0]['read']===false,'Real controller decodes metadata and unread flags');}
checkMysql((int)$p->query("SELECT COUNT(*) FROM notifications WHERE user_id=1 AND type='credits_transferred'")->fetchColumn()===1,'Sender gets alert');
checkMysql((int)$p->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='credits_received'")->fetchColumn()===1,'Recipient gets alert');
foreach([1,2]as$id)CustomerNotificationService::sync($p,$id);
checkMysql((int)$p->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===2,'Reload deduplicates');
$p->exec('DELETE FROM notifications WHERE user_id=1');CustomerNotificationService::sync($p,1);checkMysql((int)$p->query('SELECT COUNT(*) FROM notifications WHERE user_id=1')->fetchColumn()===0,'Dismissal remains durable');
$p->exec("DROP TABLE payments;INSERT INTO credit_transfers VALUES('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',1,2,10,NOW())");
foreach([1,2]as$id)checkMysql(!CustomerNotificationService::sync($p,$id)['available'],'Partial source failure is visible');
checkMysql((int)$p->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===3,'Broken purchase query cannot block either transfer alert');
$p->beginTransaction();$t=$p->query("SELECT * FROM credit_transfers WHERE id='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'")->fetch();CreditTransferReceiptService::enqueue($p,$t);$p->commit();
$children=[];
for($i=0;$i<2;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--child',$database],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$proc,$pipes];}
$sent=0;foreach($children as[$proc,$pipes]){$r=json_decode(stream_get_contents($pipes[1]),true,512,JSON_THROW_ON_ERROR);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);checkMysql(proc_close($proc)===0&&$err==='','Concurrent receipt worker exits cleanly');$sent+=$r['sent'];}
checkMysql($sent===1&&(int)$p->query('SELECT attempts FROM credit_transfer_receipts')->fetchColumn()===1,'Two workers claim one receipt once');
checkMysql(CreditTransferReceiptService::dispatchDue($p,25,fn()=>throw new RuntimeException('Must not resend'))['processed']===0,'Worker replay cannot email twice');
echo "PASS: {$n} isolated MySQL notification/receipt checks using production notifications DDL. No customer rows, SMTP or live writes.\n";
