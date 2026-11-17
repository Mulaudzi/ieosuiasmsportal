<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Isolated SQLite/temporary filesystem only. No configuration or network.
$fixture=sys_get_temp_dir().'/ieosuia-accounting-snapshot-'.bin2hex(random_bytes(12));
if(!mkdir($fixture.'/api/domain',0700,true))throw new RuntimeException('Fixture unavailable');
foreach(['LogicSmsReportAccountingSnapshot.php','SmsMessageState.php'] as $source)if(!copy(__DIR__.'/../domain/'.$source,$fixture.'/api/domain/'.$source))throw new RuntimeException('Fixture source unavailable');
require $fixture.'/api/domain/LogicSmsReportAccountingSnapshot.php';
final class AccountingSnapshotPDO extends PDO
{
    public array $statements=[];
    public function prepare(string $query,array $options=[]):PDOStatement|false{$this->statements[]=$query;return parent::prepare($query,$options);}
}
$checks=0;$archives=0;
function accountingCheck(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
function accountingPDO():AccountingSnapshotPDO
{
    $p=new AccountingSnapshotPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->sqliteCreateFunction('CONCAT',static fn(...$parts)=>implode('',$parts));
    $p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT);
        CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER,balance DECIMAL(14,4),reserved DECIMAL(14,4),currency TEXT);
        CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,state TEXT,recipient_count INTEGER,sent_count INTEGER,queued_count INTEGER,processing_count INTEGER,skipped_count INTEGER,estimated_cost NUMERIC,actual_cost NUMERIC);
        CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,user_id INTEGER,campaign_id INTEGER,state TEXT,estimated_charge NUMERIC,actual_charge NUMERIC,sent_at TEXT,last_error TEXT,destination TEXT,content TEXT);
        CREATE TABLE wallet_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,wallet_id INTEGER,campaign_id INTEGER,payment_id INTEGER,operation TEXT,direction TEXT,amount NUMERIC,idempotency_key TEXT,metadata_json TEXT);
        CREATE TABLE campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,type TEXT,status TEXT,total_recipients INTEGER,estimated_cost NUMERIC,actual_cost NUMERIC,name TEXT,message TEXT);
        CREATE TABLE messages(id INTEGER PRIMARY KEY,campaign_id INTEGER,status TEXT,cost NUMERIC,recipient TEXT,content TEXT);
        CREATE TABLE email_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,status TEXT,total_recipients INTEGER,estimated_cost NUMERIC,actual_cost NUMERIC,subject TEXT);
        INSERT INTO users VALUES(7,'vendaboy.lm@gmail.com'),(9,'private-other@example.test');
        INSERT INTO wallets VALUES(17,7,100,9.66,'ZAR'),(19,9,70,0,'ZAR');
        INSERT INTO sms_campaigns VALUES(25,7,'processing',4,0,4,0,0,1.4,0),(26,7,'processing',5,0,5,0,0,1.75,0),(90,9,'completed',1,1,0,0,0,.35,.35),(20,7,'completed',1,1,0,0,0,.35,.35);
        INSERT INTO sms_messages VALUES(941,7,25,'retry',.35,0,NULL,'Original private error','+27638082493','Private original content'),(942,7,25,'retry',.35,0,NULL,'Original','+27799292775','Private content'),(943,7,25,'retry',.35,0,NULL,'Original','+27721101883','Private content'),(944,7,25,'retry',.35,0,NULL,'Original','+27816545388','Private content'),(945,7,26,'retry',.35,0,NULL,'Original','+27000000001','Private content'),(946,7,26,'retry',.35,0,NULL,'Original','+27000000002','Private content'),(947,7,26,'retry',.35,0,NULL,'Original','+27000000003','Private content'),(948,7,26,'retry',.35,0,NULL,'Original','+27000000004','Private content'),(949,7,26,'retry',.35,0,NULL,'Original','+27000000005','Private content'),(500,7,20,'sent',.35,.35,'2026-10-20 00:00:00',NULL,'Private phone','Private content'),(900,9,90,'sent',.35,.35,'2026-10-20 00:00:00',NULL,'Other phone','Other content');
        INSERT INTO wallet_ledger VALUES(1,7,17,25,NULL,'reservation','reserve',1.4,'campaign:25:reserve','Private context'),(2,7,17,26,NULL,'reservation','reserve',1.75,'campaign:26:reserve','Private context'),(3,7,17,20,NULL,'reservation','reserve',.35,'campaign:20:reserve','Private context'),(4,7,17,20,NULL,'sms_settlement','debit',.35,'sms-message:500:settle','Private context'),(5,7,17,NULL,55,'payment','credit',100,'Private payment key','Private context'),(6,9,19,90,NULL,'reservation','reserve',.35,'Other key','Other context'),(7,9,19,90,NULL,'sms_settlement','debit',.35,'sms-message:900:settle','Other context');
        INSERT INTO campaigns VALUES(40,7,'sms','Sent',3,1.05,.7,'Private campaign title','Private campaign text'),(41,7,'email','Scheduled',10,5.46,0,'Private campaign title','Private campaign text'),(42,9,'email','Scheduled',100,60,0,'Other private title','Other private text');
        INSERT INTO messages VALUES(400,40,'Sent',.35,'Private recipient','Private content'),(401,40,'Pending',.35,'Private recipient','Private content'),(410,41,'Pending',.546,'Private recipient','Private content');
        INSERT INTO email_campaigns VALUES(50,7,'queued',5,1.25,0,'Private subject'),(51,9,'queued',8,2,0,'Private subject');");
    return $p;
}
function accountingDump(PDO $p):array{$data=[];foreach(['users','wallets','sms_campaigns','sms_messages','wallet_ledger','campaigns','messages','email_campaigns'] as $table)$data[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);return $data;}
function accountingArchive(string $path):void{global $archives;if(file_exists($path)&&!rename($path,dirname($path).'/fixture-previous-'.(++$archives).'.php'))throw new RuntimeException('Fixture archive unavailable');}
$path=$fixture.'/api/storage/reconciliation/'.LogicSmsReportAccountingSnapshot::RECEIPT_NAME;
$p=accountingPDO();$before=accountingDump($p);$p->statements=[];
$r=LogicSmsReportAccountingSnapshot::run($p,$path);
accountingCheck($r['code']==='REPORT_ACCOUNTING_SNAPSHOT'&&$r['ok']&&$r['read_only']&&!$r['database_changes']&&!$r['sms_submitted']&&$r['provider_requests']===0,'Snapshot succeeds without submission or database changes');
accountingCheck(accountingDump($p)===$before&&!$p->inTransaction(),'All source tables preserved and read transaction closed');
foreach($p->statements as $sql)accountingCheck(str_starts_with($sql,'SELECT')&&!str_contains($sql,'FOR UPDATE')&&!str_contains($sql,'GET_LOCK'),'Every database statement is SELECT-only without advisory or row locks');
accountingCheck($r['user_id']===7&&$r['wallet']['id']===17&&$r['wallet']['balance']==='100.0000'&&$r['wallet']['reserved']==='9.6600','Only internally verified owner wallet and financial amounts emitted');
accountingCheck($r['ledger_totals']['canonical_outstanding']==='3.1500'&&$r['ledger_totals']['stored_minus_canonical']==='6.5100'&&!$r['ledger_totals']['reservation_matches'],'Mismatch is measured, never corrected or presumed stale');
accountingCheck($r['ledger_totals']['entries']===5&&$r['ledger_totals']['reservation_entries']===3&&$r['ledger_totals']['settlement_entries']===1,'Full owned ledger aggregated, other customer excluded');
accountingCheck($r['global_messages']['messages']===10&&$r['global_messages']['liability_amount']==='3.1500'&&$r['global_messages']['canonical_settlement_entries']===1,'Account-wide message liabilities and canonical settlement counts included');
accountingCheck(count($r['campaigns'])===2&&$r['campaigns'][0]['id']===25&&$r['campaigns'][1]['id']===26&&$r['campaigns'][0]['messages']['liability_count']===4&&$r['campaigns'][1]['messages']['liability_count']===5,'Target campaign totals are separate and exact');
accountingCheck(count($r['owned_campaigns'])===3&&count($r['legacy_campaigns'])===2&&count($r['email_campaigns'])===1&&count($r['legacy_message_groups'])===3,'All owned canonical and legacy SMS/email financial aggregates included');
accountingCheck($r['legacy_campaigns'][1]['type']==='email'&&$r['legacy_campaigns'][1]['estimated_cost']==='5.4600'&&$r['legacy_message_groups'][2]['cost']==='0.5460','Legacy holds remain evidence, not automatically released');
accountingCheck(count($r['wallet_precision'])===2&&$r['wallet_precision'][0]['column_type']==='DECIMAL(14,4)','Wallet column types included without schema mutation');
$json=json_encode($r,JSON_THROW_ON_ERROR);
foreach(['vendaboy','private-other','@','destination','content','recipient"','subject','metadata','idempotency','Original private','Private campaign','Private phone','Other phone','500:settle'] as $private)accountingCheck(!str_contains($json,$private),'Snapshot excludes private contact/content/email/context/key fields');
$bytes=file_get_contents($path);accountingCheck(str_starts_with($bytes,'<?php http_response_code(404); exit; /* IEOSUIA_REPORT_RECEIPT_BASE64:')&&str_ends_with($bytes," */\n"),'Receipt is an inert private PHP envelope');
if(PHP_OS_FAMILY!=='Windows')accountingCheck((fileperms(dirname($path))&0777)===0700&&(fileperms($path)&0777)===0600,'Receipt and directory permissions are restrictive');
$originalPath=dirname($path).'/report-59b298114f2f4519.php';file_put_contents($originalPath,'original import receipt sentinel');
$p->exec('UPDATE wallets SET balance=5 WHERE user_id=7');$p->statements=[];$repeat=LogicSmsReportAccountingSnapshot::run($p,$path);
accountingCheck($repeat['from_receipt']&&$repeat['wallet']['balance']==='100.0000'&&file_get_contents($path)===$bytes&&$p->statements===[],'One-time receipt is never overwritten or refreshed on next cron');
accountingCheck(file_get_contents($originalPath)==='original import receipt sentinel','Original approved import receipt is preserved');
accountingArchive($path);

foreach(['different_owner','split_owners','missing_campaign','wallet_owner','foreign_ledger','foreign_campaign_ledger','foreign_message','duplicate_wallet','invalid_currency'] as $case){
    $p=accountingPDO();
    switch($case){
        case 'different_owner':$p->exec("UPDATE users SET email='other@example.test' WHERE id=7");break;
        case 'split_owners':$p->exec('UPDATE sms_campaigns SET user_id=9 WHERE id=26');break;
        case 'missing_campaign':$p->exec('DELETE FROM sms_campaigns WHERE id=26');break;
        case 'wallet_owner':$p->exec('UPDATE wallets SET user_id=9 WHERE id=17');break;
        case 'foreign_ledger':$p->exec('UPDATE wallet_ledger SET user_id=9 WHERE id=1');break;
        case 'foreign_campaign_ledger':$p->exec('UPDATE wallet_ledger SET campaign_id=90 WHERE id=1');break;
        case 'foreign_message':$p->exec('UPDATE sms_messages SET user_id=9 WHERE id=941');break;
        case 'duplicate_wallet':$p->exec("INSERT INTO wallets VALUES(18,7,0,0,'ZAR')");break;
        case 'invalid_currency':$p->exec("UPDATE wallets SET currency='Private @ currency' WHERE id=17");break;
    }
    $before=accountingDump($p);$r=LogicSmsReportAccountingSnapshot::run($p,$path);
    accountingCheck(!$r['ok']&&$r['code']==='ACCOUNTING_SNAPSHOT_UNAVAILABLE'&&!file_exists($path)&&accountingDump($p)===$before,'Ownership and invalid schema/account fields fail closed without receipt or writes');
    accountingCheck(isset($r['error_fingerprint'])&&!isset($r['message'])&&!str_contains(json_encode($r),'@'),'Guard failures emit only a fingerprint, never private details');
}
$p=accountingPDO();$p->beginTransaction();$r=LogicSmsReportAccountingSnapshot::run($p,$path);accountingCheck(!$r['ok']&&$p->inTransaction(),'Existing caller transaction remains untouched');$p->rollBack();
$r=LogicSmsReportAccountingSnapshot::run($p,dirname($path).'/other.php');accountingCheck(!$r['ok']&&!file_exists(dirname($path).'/other.php'),'Arbitrary receipt destination rejected');
$p=accountingPDO();$p->exec('DROP TABLE messages; DROP TABLE campaigns; DROP TABLE email_campaigns');$r=LogicSmsReportAccountingSnapshot::run($p,$path);
accountingCheck($r['ok']&&$r['legacy_campaigns']===[]&&$r['email_campaigns']===[]&&$r['legacy_message_groups']===[],'Missing optional legacy tables do not prevent canonical accounting evidence');accountingArchive($path);
$p=accountingPDO();$r=LogicSmsReportAccountingSnapshot::run($p,$path);$bytes=file_get_contents($path);file_put_contents($path,str_replace('BASE64:','BASE64:AA',$bytes));$p->statements=[];$r=LogicSmsReportAccountingSnapshot::run($p,$path);
accountingCheck(!$r['ok']&&$p->statements===[],'Tampered one-time receipt blocks replacement and database reads');
accountingCheck(file_get_contents($originalPath)==='original import receipt sentinel','Original import receipt preserved through every failure case');
echo json_encode(['ok'=>true,'checks'=>$checks,'database'=>'isolated SQLite','network_requests'=>0,'production_changes'=>false],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
