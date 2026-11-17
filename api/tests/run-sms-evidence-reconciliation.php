<?php
declare(strict_types=1);
// Real reconciliation, WalletService and dispatcher; isolated SQLite only.
// There are no production credentials and no provider transport in this test.
function env(string $key,mixed $default=null):mixed{return $default;}
final class EvidenceFixturePDO extends PDO
{
    public array $statements=[];
    public function prepare(string $sql,array $options=[]):PDOStatement|false
    {
        $this->statements[]=$sql;
        // MySQL DECIMAL(14,4) rounds each stored result. SQLite NUMERIC uses a
        // binary float, which otherwise manufactures a final-35c shortfall.
        $sql=str_replace([' FOR UPDATE','balance = balance - ?','reserved - ?'],['','balance = ROUND(balance - ?,4)','ROUND(reserved - ?,4)'],$sql);
        return parent::prepare($sql,$options);
    }
}
require_once __DIR__.'/../core/QueryBuilder.php';
require_once __DIR__.'/../domain/SmsEvidenceReconciliation.php';
$checks=0;
function evidenceCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function db():PDO{global $pdo;return $pdo;}
function fixture():EvidenceFixturePDO
{
    $p=new EvidenceFixturePDO('sqlite::memory:');
    $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $p->sqliteCreateFunction('NOW',fn()=>'2026-10-24 03:00:00');
    $p->sqliteCreateFunction('IF',fn($test,$yes,$no)=>$test?$yes:$no);
    $p->sqliteCreateFunction('GREATEST',fn(...$values)=>max($values));
    $p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT);
        CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER,balance NUMERIC,reserved NUMERIC,currency TEXT,updated_at TEXT);
        CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,state TEXT,recipient_count INTEGER,queued_count INTEGER,processing_count INTEGER,sent_count INTEGER,delivered_count INTEGER,failed_count INTEGER,skipped_count INTEGER,actual_cost NUMERIC,completed_at TEXT,started_at TEXT,created_at TEXT,updated_at TEXT,sender_id TEXT,content TEXT);
        CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,campaign_id INTEGER,user_id INTEGER,destination TEXT,content TEXT,state TEXT,provider TEXT,provider_message_id TEXT,sent_at TEXT,delivered_at TEXT,failed_at TEXT,last_error TEXT,actual_charge NUMERIC DEFAULT 0,estimated_charge NUMERIC,segment_count INTEGER,attempt_count INTEGER DEFAULT 1,next_attempt_at TEXT,lease_token TEXT,leased_at TEXT,retry_generation INTEGER DEFAULT 0,created_at TEXT,updated_at TEXT,last_dlr_checked_at TEXT,dlr_attempt_count INTEGER DEFAULT 0,UNIQUE(provider,provider_message_id));
        CREATE TABLE wallet_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,wallet_id INTEGER,campaign_id INTEGER,payment_id INTEGER,operation TEXT,direction TEXT,amount NUMERIC,idempotency_key TEXT UNIQUE,metadata_json TEXT,created_at TEXT);
        CREATE TABLE operational_logs(id INTEGER PRIMARY KEY,level TEXT,service TEXT,event TEXT,message TEXT,campaign_id INTEGER,sms_message_id INTEGER,user_id INTEGER,context_json TEXT,created_at TEXT);
        CREATE TABLE opt_outs(user_id INTEGER,channel TEXT,recipient TEXT);
        INSERT INTO users VALUES(2,'blwnwupotch@gmail.com'),(9,'other@example.test');
        INSERT INTO wallets VALUES(11,2,175,1.75,'ZAR','2026-10-23 20:00:00'),(19,9,9,0,'ZAR',NULL);
        INSERT INTO sms_campaigns(id,user_id,name,state,recipient_count,queued_count,processing_count,sent_count,delivered_count,failed_count,skipped_count,actual_cost,started_at,created_at,updated_at,content) VALUES(24,2,'SUPER SUNDAY','processing',5,5,0,0,0,0,0,0,'2026-10-23 21:03:00','2026-10-23 21:02:00','2026-10-23 21:50:00','Hello! This is a reminder to come join us for our Special Sunday Service @NWU Potch Campus');
        INSERT INTO sms_campaigns(id,user_id,name,state,recipient_count,created_at) VALUES(90,9,'Other campaign','completed',1,'2026-10-22');
        INSERT INTO sms_messages(id,campaign_id,user_id,destination,state,provider,provider_message_id,sent_at,actual_charge,estimated_charge,last_error) VALUES(100,90,9,'+27820000000','sent','logicsms','9999','2026-10-22 12:00:00',.35,.35,'Preserve other message');
        INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,operation,direction,amount,idempotency_key,metadata_json,created_at) VALUES(2,11,24,'reservation','reserve',1.75,'campaign:24:reserve','{\"original\":true}','2026-10-23 21:02:00');
        CREATE TRIGGER production_lease_width BEFORE UPDATE OF lease_token ON sms_messages WHEN length(NEW.lease_token)>36 BEGIN SELECT RAISE(ABORT,'Production CHAR(36) lease constraint'); END;");
    $errors=[
        936=>'Provider accepted reference 9999; awaiting local settlement; automatic resend held. Recovery interrupted; original reference must be reconciled.',
        937=>'Submission started; awaiting provider acknowledgement; automatic resend held. Provider acknowledgement could not be verified. Contact support before any resend.',
    ];
    // Pin fixtures to the actual submitted diagnostics, not weaker dummy errors.
    evidenceCheck(hash('sha256',$errors[936])==='be9a3f21b73a937b5706f71c489b72de962df2e17da43c31890cfbb4f0d13424','Recovered error fixture matches supplied production fingerprint');
    evidenceCheck(hash('sha256',$errors[937])==='a394aade847af2d970904181b73a108159389ec160c9e32a5cedeb32dacc830f','Original hold fixture matches supplied production fingerprint');
    $s=$p->prepare("INSERT INTO sms_messages(id,campaign_id,user_id,destination,content,state,provider,actual_charge,estimated_charge,segment_count,attempt_count,next_attempt_at,last_error,created_at,updated_at) VALUES(?,24,2,?,'Original content preserved','retry',NULL,0,.35,1,?,'9999-12-31 23:59:59',?,'2026-10-23 21:02:00','2026-10-23 21:50:00')");
    foreach(SmsEvidenceReconciliation::evidence()['messages'] as $row)$s->execute([$row['id'],$row['destination'],$row['id']===936?2:1,$errors[$row['id']===936?936:937]]);
    return $p;
}
function dumpFixture(PDO $p):array
{
    $out=[];foreach(['users','wallets','sms_campaigns','sms_messages','wallet_ledger','operational_logs','opt_outs'] as $table)$out[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY '.($table==='opt_outs'?'user_id':'id'))->fetchAll(PDO::FETCH_ASSOC);return $out;
}
function mustReject(EvidenceFixturePDO $p,string $label):void
{
    $before=dumpFixture($p);$caught=false;
    try{SmsEvidenceReconciliation::apply($p);}catch(DomainException|PDOException){$caught=true;}
    evidenceCheck($caught&&dumpFixture($p)===$before&&!$p->inTransaction(),$label);
}

$pdo=fixture();$before=dumpFixture($pdo);$pdo->statements=[];
$preview=SmsEvidenceReconciliation::preview($pdo);
evidenceCheck($preview['code']==='READY_TO_RECONCILE'&&dumpFixture($pdo)===$before,'Preview never changes any table');
evidenceCheck(count(array_filter($pdo->statements,fn($sql)=>!preg_match('/^SELECT\b/i',$sql)))===0,'Preview executes SELECT statements only');
$pdo->statements=[];
$result=SmsEvidenceReconciliation::apply($pdo);
evidenceCheck($result['code']==='RECONCILED_FROM_OWNER_VERIFIED_REPORT'&&$result['sms_submitted']===false&&$result['provider_requests']===0,'Apply is a local evidence reconciliation, not a send');
evidenceCheck($result['campaign']['state']==='completed'&&(int)$result['campaign']['sent_count']===5&&(int)$result['campaign']['delivered_count']===4&&(float)$result['campaign']['actual_cost']===1.75,'Real dispatcher projection reports five accepted, four delivered and R1.75');
evidenceCheck((float)$result['wallet']['balance']===173.25&&(float)$result['wallet']['reserved']===0.0,'Exactly R1.75 consumed from existing reservation, with no new reservation or refund');
foreach($result['messages'] as $index=>$row){
    evidenceCheck($row['state']===SmsEvidenceReconciliation::evidence()['messages'][$index]['state']&&$row['actual_charge']==='0.3500'&&!$row['provider_reference_saved']&&$row['sent_at']===null&&$row['delivered_at']===null,'Only screenshot-supported state recorded; no invented provider ID or delivery/send timestamp');
}
$after=dumpFixture($pdo);
evidenceCheck($after['sms_messages'][0]===$before['sms_messages'][0]&&$after['sms_campaigns'][1]===$before['sms_campaigns'][1]&&$after['wallets'][1]===$before['wallets'][1],'Other messages, 9999 collision, campaign and customer wallet unchanged');
evidenceCheck(count($after['wallet_ledger'])===6&&count($after['operational_logs'])===2,'Five unique real settlement entries and both mandatory audit events recorded');
$auditBefore=json_decode($after['operational_logs'][0]['context_json'],true,512,JSON_THROW_ON_ERROR);
$auditAfter=json_decode($after['operational_logs'][1]['context_json'],true,512,JSON_THROW_ON_ERROR);
evidenceCheck($auditBefore['before']['messages'][0]===$before['sms_messages'][1]&&$auditBefore['before']['campaign']===$before['sms_campaigns'][0]&&$auditBefore['before']['wallet']===$before['wallets'][0],'Full original error, row metadata, campaign and wallet preserved in mandatory before audit');
evidenceCheck($auditBefore['evidence']['source']==='owner_verified_logicsms_report_screenshots'&&$auditBefore['evidence']['source_is_automated_webhook']===false&&$auditBefore['reconciliation_is_not_network_delivery_time']===true&&$auditAfter['before_audit_sha256']===hash('sha256',$after['operational_logs'][0]['context_json']),'Audit distinguishes owner screenshots from automated callbacks and preserves report-date provenance');
$lockStatements=array_values(array_filter($pdo->statements,fn($sql)=>str_contains($sql,' FOR UPDATE')));
evidenceCheck(str_contains($lockStatements[0],'sms_messages')&&str_contains($lockStatements[1],'wallets'),'Message lock precedes wallet lock, matching dispatch settlement order');
$repeat=SmsEvidenceReconciliation::apply($pdo);
evidenceCheck($repeat['already_reconciled']===true&&dumpFixture($pdo)===$after,'Repeated apply does not debit, log, overwrite or resend again');
evidenceCheck(SmsEvidenceReconciliation::preview($pdo)['already_reconciled']===true&&dumpFixture($pdo)===$after,'Repeated preview detects recorded reconciliation without writes');
$pdo->exec("UPDATE sms_messages SET state='delivered',delivered_at='2026-10-24 04:00:00',last_dlr_checked_at='2026-10-24 04:00:00' WHERE id=939; UPDATE wallets SET balance=200 WHERE id=11");
$later=dumpFixture($pdo);$repeat=SmsEvidenceReconciliation::apply($pdo);
evidenceCheck($repeat['messages'][3]['state']==='delivered'&&dumpFixture($pdo)===$later,'Later genuine final status and unrelated purchases survive repeat reconciliation');

final class NeverSendEvidenceProvider implements SmsProvider
{
    public int $calls=0;
    public function name():string{return 'logicsms';}
    public function send(string $destination,string $content,?string $senderId=null,?string $clientReference=null):array{$this->calls++;throw new RuntimeException('A reconciled SMS must never be sent');}
}
$provider=new NeverSendEvidenceProvider();$rows=$pdo->query('SELECT * FROM sms_messages WHERE campaign_id=24 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $row)SmsDispatchService::process($row,$provider);
evidenceCheck($provider->calls===0&&dumpFixture($pdo)===$later,'Real dispatcher refuses to send reconciled messages even when explicitly called');

foreach([
    "UPDATE users SET email='foreign@example.test' WHERE id=2"=>'Wrong account rejected',
    "INSERT INTO users VALUES(3,' BLWNWUPOTCH@gmail.com ')"=>'Ambiguous account rejected',
    "UPDATE sms_campaigns SET user_id=9 WHERE id=24"=>'Wrong campaign owner rejected',
    "UPDATE sms_campaigns SET name='Another name' WHERE id=24"=>'Wrong campaign name rejected',
    "UPDATE sms_campaigns SET recipient_count=4 WHERE id=24"=>'Recipient count change rejected',
    "DELETE FROM sms_messages WHERE id=940"=>'Missing target message rejected',
    "INSERT INTO sms_messages(id,campaign_id,user_id,destination) VALUES(941,24,2,'+27829999999')"=>'Additional campaign message rejected',
    "UPDATE sms_messages SET destination='+27829999999' WHERE id=940"=>'Changed exact destination rejected',
    "UPDATE sms_messages SET user_id=9 WHERE id=940"=>'Foreign target message owner rejected',
    "UPDATE sms_messages SET state='delivered' WHERE id=936"=>'Pre-existing final outcome without journal rejected',
    "UPDATE sms_messages SET last_error='Different unknown result' WHERE id=936"=>'Unreviewed original error fingerprint rejected',
    "UPDATE sms_messages SET lease_token='active',leased_at=NOW() WHERE id=937"=>'Active worker lease rejected',
    "UPDATE sms_messages SET attempt_count=2 WHERE id=937"=>'Unexpected retry attempt rejected',
    "UPDATE sms_messages SET actual_charge=.35 WHERE id=940"=>'Already charged original message rejected',
    "UPDATE sms_messages SET provider_message_id='real-later-ack' WHERE id=938"=>'New provider acknowledgement requires review',
    "UPDATE sms_messages SET estimated_charge=.7 WHERE id=940"=>'Changed tariff rejected',
    "UPDATE sms_messages SET sent_at=NOW() WHERE id=940"=>'New acceptance timestamp requires review',
    "UPDATE wallets SET reserved=1.4 WHERE id=11"=>'Insufficient old reservation rejected',
    "UPDATE wallets SET balance=1 WHERE id=11"=>'Insufficient wallet funds rejected',
    "UPDATE wallets SET currency='USD' WHERE id=11"=>'Foreign-currency wallet rejected',
    "UPDATE wallet_ledger SET amount=1.4 WHERE campaign_id=24"=>'Reservation ledger drift rejected',
    "UPDATE wallet_ledger SET wallet_id=19 WHERE campaign_id=24"=>'Foreign reservation wallet rejected',
    "INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,operation,direction,amount,idempotency_key) VALUES(9,19,90,'sms_settlement','debit',.35,'sms-message:936:settle')"=>'Foreign campaign settlement key collision rejected',
] as $sql=>$label){$p=fixture();$p->exec($sql);mustReject($p,$label);}

foreach([
    "CREATE TRIGGER fail_before_audit BEFORE INSERT ON operational_logs WHEN NEW.event='owner_report_before' BEGIN SELECT RAISE(ABORT,'Audit unavailable'); END"=>'Missing mandatory before audit aborts before any mutation',
    "CREATE TRIGGER fail_settlement BEFORE INSERT ON wallet_ledger WHEN NEW.idempotency_key='sms-message:938:settle' BEGIN SELECT RAISE(ABORT,'Injected settlement error'); END"=>'Mid-batch settlement failure rolls back all messages, funds and audit',
    "CREATE TRIGGER fail_message BEFORE UPDATE ON sms_messages WHEN NEW.id=938 BEGIN SELECT RAISE(ABORT,'Injected message update failure'); END"=>'Mid-batch message failure rolls back all messages and settlements',
    "CREATE TRIGGER fail_projection BEFORE UPDATE ON sms_campaigns WHEN NEW.id=24 BEGIN SELECT RAISE(ABORT,'Injected aggregate failure'); END"=>'Projection failure rolls back the whole reconciliation',
    "CREATE TRIGGER fail_after_audit BEFORE INSERT ON operational_logs WHEN NEW.event='owner_report_after' BEGIN SELECT RAISE(ABORT,'Post audit unavailable'); END"=>'Missing mandatory after audit rolls back all mutations',
    "CREATE TRIGGER damage_wallet AFTER UPDATE ON wallets WHEN NEW.id=11 BEGIN UPDATE wallets SET balance=balance+.1 WHERE id=11; END"=>'Wallet postcondition drift rolls back all mutations',
    "CREATE TRIGGER damage_metadata AFTER UPDATE ON sms_messages WHEN NEW.id=940 BEGIN UPDATE sms_messages SET content='Incorrectly overwritten' WHERE id=940; END"=>'Unexpected original metadata changes trigger rollback',
] as $sql=>$label){$p=fixture();$p->exec($sql);mustReject($p,$label);}
$p=fixture();SmsEvidenceReconciliation::apply($p);$p->exec("DELETE FROM operational_logs WHERE event='owner_report_after'");mustReject($p,'Incomplete previously applied journal blocks silent reuse');
$p=fixture();$p->beginTransaction();$caught=false;try{SmsEvidenceReconciliation::apply($p);}catch(DomainException){$caught=true;}evidenceCheck($caught&&$p->inTransaction(),'Existing caller transaction is not hijacked');$p->rollBack();
echo 'PASS: '.$checks." scoped evidence reconciliation/accounting/idempotence/rollback checks; no SMS sent.\n";
