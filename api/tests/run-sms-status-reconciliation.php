<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Real reconciliation, wallet and campaign projection; SQLite only. No network.
function env(string $key,mixed $default=null):mixed{return $default;}
function db():PDO{return $GLOBALS['pdo'];}
final class StatusFixturePDO extends PDO
{
    public array $statements=[];
    public bool $ownsWorkerLock=true;
    public function prepare(string $sql,array $options=[]):PDOStatement|false
    {
        $this->statements[]=$sql;
        // Match MySQL DECIMAL(14,4) writes instead of SQLite binary-float drift.
        $sql=str_replace([' FOR UPDATE','balance = balance - ?','reserved - ?'],['','balance = ROUND(balance - ?,4)','ROUND(reserved - ?,4)'],$sql);
        return parent::prepare($sql,$options);
    }
}
require_once __DIR__.'/../core/QueryBuilder.php';
require_once __DIR__.'/../domain/SmsStatusReconciliation.php';
$checks=0;
function statusCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function statusFixture():StatusFixturePDO
{
    $p=new StatusFixturePDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->sqliteCreateFunction('NOW',fn()=>'2026-10-24 04:00:00');
    $p->sqliteCreateFunction('IF',fn($test,$yes,$no)=>$test?$yes:$no);
    $p->sqliteCreateFunction('GREATEST',fn(...$values)=>max($values));
    $p->sqliteCreateFunction('CONNECTION_ID',fn()=>42);
    $p->sqliteCreateFunction('IS_USED_LOCK',fn($name)=>$name===SmsStatusReconciliation::WORKER_LOCK&&$p->ownsWorkerLock?42:null);
    $p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT);
        CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER,balance NUMERIC,reserved NUMERIC,currency TEXT,updated_at TEXT);
        CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,state TEXT,recipient_count INTEGER,queued_count INTEGER,processing_count INTEGER,sent_count INTEGER,delivered_count INTEGER,failed_count INTEGER,skipped_count INTEGER,actual_cost NUMERIC,completed_at TEXT,started_at TEXT,created_at TEXT,updated_at TEXT,sender_id TEXT,content TEXT);
        CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,campaign_id INTEGER,user_id INTEGER,destination TEXT,content TEXT,state TEXT,provider TEXT,provider_message_id TEXT,sent_at TEXT,delivered_at TEXT,failed_at TEXT,last_error TEXT,actual_charge NUMERIC DEFAULT 0,estimated_charge NUMERIC,segment_count INTEGER,attempt_count INTEGER DEFAULT 1,next_attempt_at TEXT,lease_token TEXT,leased_at TEXT,retry_generation INTEGER DEFAULT 0,created_at TEXT,updated_at TEXT,last_dlr_checked_at TEXT,dlr_attempt_count INTEGER DEFAULT 0,UNIQUE(provider,provider_message_id));
        CREATE TABLE wallet_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,wallet_id INTEGER,campaign_id INTEGER,payment_id INTEGER,operation TEXT,direction TEXT,amount NUMERIC,idempotency_key TEXT UNIQUE,metadata_json TEXT,created_at TEXT);
        CREATE TABLE operational_logs(id INTEGER PRIMARY KEY,level TEXT,service TEXT,event TEXT,message TEXT,campaign_id INTEGER,sms_message_id INTEGER,user_id INTEGER,context_json TEXT,created_at TEXT);
        CREATE TABLE opt_outs(user_id INTEGER,channel TEXT,recipient TEXT);
        INSERT INTO users VALUES(2,'owner@example.test'),(9,'other@example.test');
        INSERT INTO wallets VALUES(11,2,175,1.05,'ZAR','2026-10-23 20:00:00'),(19,9,9,0,'ZAR',NULL);
        INSERT INTO sms_campaigns(id,user_id,name,state,recipient_count,queued_count,processing_count,sent_count,delivered_count,failed_count,skipped_count,actual_cost,started_at,created_at,updated_at,content) VALUES(24,2,'Campaign one','processing',2,2,0,0,0,0,0,0,'2026-10-23 21:03:00','2026-10-23 21:02:00','2026-10-23 21:50:00','Original content');
        INSERT INTO sms_campaigns(id,user_id,name,state,recipient_count,created_at) VALUES(90,9,'Other account','completed',1,'2026-10-22'),(91,2,'Other campaign same account','processing',1,'2026-10-23');
        INSERT INTO sms_messages(id,campaign_id,user_id,destination,content,state,provider,provider_message_id,sent_at,actual_charge,estimated_charge,last_error) VALUES(100,90,9,'+27820000000','Preserve other message','sent','logicsms','9999','2026-10-22 12:00:00',.35,.35,'Old diagnostics');
        INSERT INTO sms_messages(id,campaign_id,user_id,destination,content,state,actual_charge,estimated_charge,segment_count,attempt_count,next_attempt_at,last_error,created_at,updated_at) VALUES(1,24,2,'+27638082493','Original content','retry',0,.35,1,1,'9999-12-31 23:59:59','Original unknown submission response','2026-10-23 21:02:00','2026-10-23 21:50:00'),(2,24,2,'+27816545388','Original content','retry',0,.35,1,1,'9999-12-31 23:59:59','Original unknown submission response','2026-10-23 21:02:00','2026-10-23 21:50:00'),(3,91,2,'+27799292775','Different campaign','retry',0,.35,1,1,'9999-12-31 23:59:59','Other campaign hold','2026-10-23 21:02:00','2026-10-23 21:50:00');
        INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,operation,direction,amount,idempotency_key,metadata_json,created_at) VALUES(2,11,24,'reservation','reserve',.7,'campaign:24:reserve','{\"original\":true}','2026-10-23 21:02:00'),(2,11,91,'reservation','reserve',.35,'campaign:91:reserve',NULL,'2026-10-23 21:02:00');
        CREATE TRIGGER production_lease_width BEFORE UPDATE OF lease_token ON sms_messages WHEN length(NEW.lease_token)>36 BEGIN SELECT RAISE(ABORT,'Production CHAR(36) lease constraint'); END;");
    return $p;
}
function statusEvidence(int $id=1,string $source='provider_query',string $status='DELIVERED'):array
{
    $e=['source'=>$source,'user_id'=>2,'campaign_id'=>24,'status'=>$status,'original_reference'=>'portal-message-'.$id,'observed_at_utc'=>'2026-10-24T02:00:00Z'];
    if($source==='owner_report')$e['match_proof']=['kind'=>'exact_report_row','destination'=>$id===1?'+27638082493':'+27816545388','content_sha256'=>hash('sha256','Original content'),'campaign_created_at'=>'2026-10-23 21:02:00'];
    return $e;
}
function statusDump(PDO $p):array{$out=[];foreach(['users','wallets','sms_campaigns','sms_messages','wallet_ledger','operational_logs','opt_outs'] as $t)$out[$t]=$p->query('SELECT * FROM '.$t.' ORDER BY '.($t==='opt_outs'?'user_id':'id'))->fetchAll(PDO::FETCH_ASSOC);return $out;}
function statusRow(PDO $p,int $id=1):array{$q=$p->prepare('SELECT * FROM sms_messages WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);}
function statusReject(StatusFixturePDO $p,array $e,string $label):void
{
    $before=statusDump($p);$rejected=false;try{SmsStatusReconciliation::apply($p,1,$e);}catch(DomainException|PDOException){$rejected=true;}
    statusCheck($rejected&&!$p->inTransaction()&&statusDump($p)===$before,$label);
}

$pdo=statusFixture();$original=statusDump($pdo);$result=SmsStatusReconciliation::apply($pdo,1,statusEvidence());
statusCheck($result['ok']&&$result['applied']&&$result['accepted']&&$result['charged_now']&&$result['charge_amount']==='0.3500'&&!$result['sms_submitted'],'Verified exact-reference query settles an eligible held row without sending');
$row=statusRow($pdo);$wallet=$pdo->query('SELECT * FROM wallets WHERE id=11')->fetch(PDO::FETCH_ASSOC);
statusCheck($row['state']==='delivered'&&$row['provider']==='logicsms'&&$row['provider_message_id']===null&&$row['sent_at']===null&&$row['delivered_at']==='2026-10-24 04:00:00'&&$row['last_error']===null&&$row['next_attempt_at']===null,'No provider ID or sent time invented; query confirmation time is explicitly an observation');
statusCheck((float)$wallet['balance']===174.65&&(float)$wallet['reserved']===.7,'Exactly one 35c settlement preserves the other 70c reservations');
$after=statusDump($pdo);
statusCheck($after['sms_messages'][1]===$original['sms_messages'][1]&&$after['sms_messages'][2]===$original['sms_messages'][2]&&$after['sms_messages'][3]===$original['sms_messages'][3]&&$after['wallets'][1]===$original['wallets'][1],'Other held messages, campaigns, users and the 9999 collision remain unchanged');
$auditBefore=json_decode($after['operational_logs'][0]['context_json'],true,512,JSON_THROW_ON_ERROR);$auditAfter=json_decode($after['operational_logs'][1]['context_json'],true,512,JSON_THROW_ON_ERROR);
statusCheck($auditBefore['before']['message']===$original['sms_messages'][0]&&$auditBefore['before']['campaign']===$original['sms_campaigns'][0]&&$auditBefore['before']['financial']['wallet']===$original['wallets'][0],'Mandatory audit preserves full original message errors and financial metadata');
statusCheck($auditBefore['timestamp_semantics']==='portal_observation_time_not_network_delivery_time'&&$auditAfter['before_audit_sha256']===hash('sha256',$after['operational_logs'][0]['context_json']),'Audit labels timestamp semantics and links before/after bytes');
$locks=array_values(array_filter($pdo->statements,fn($sql)=>str_contains($sql,' FOR UPDATE')));
statusCheck(str_contains($locks[0],'sms_messages')&&str_contains($locks[1],'sms_campaigns')&&str_contains($locks[2],'wallets'),'Exact message locks precede campaign and wallet locks');
$e=statusEvidence();$e['observed_at_utc']='2026-10-24T03:00:00Z';$e['query']=['lookup_attempts'=>[['method'=>'POST']]];
$repeat=SmsStatusReconciliation::apply($pdo,1,$e);
statusCheck($repeat['duplicate']&&!$repeat['applied']&&!$repeat['charged_now']&&statusDump($pdo)===$after,'Repeated identical status ignores volatile observation/lookup data and cannot debit or audit again');
$e=statusEvidence(1,'provider_query','SENT');$conflict=SmsStatusReconciliation::apply($pdo,1,$e);
statusCheck(!$conflict['applied']&&$conflict['code']==='FINAL_STATE_PRESERVED'&&statusRow($pdo)===$row&&count(statusDump($pdo)['operational_logs'])===4,'Out-of-order sent status cannot reverse delivered and its new receipt is audited');
statusCheck($after['wallets']===statusDump($pdo)['wallets']&&$after['wallet_ledger']===statusDump($pdo)['wallet_ledger'],'Accepted-state status receipts never change money');

$p=statusFixture();$e=statusEvidence(1,'owner_report');$e['report_row']=['date'=>'2026/10/23 21:04:08','date_semantics'=>'unverified','row_number'=>6];$r=SmsStatusReconciliation::apply($p,1,$e);
statusCheck($r['state']==='delivered'&&$r['charged_now']&&statusRow($p)['sent_at']===null&&statusRow($p)['delivered_at']===null,'Exactly matched report settles acceptance without converting report row date to sent or delivery time');
$p=statusFixture();$e=statusEvidence(1,'owner_report','INVALID');$e['provider_report_credits']=1;$e['acceptance_confirmed_by_report']=true;$r=SmsStatusReconciliation::apply($p,1,$e);
statusCheck($r['state']==='failed'&&$r['accepted']&&$r['charged_now']&&statusRow($p)['failed_at']===null&&statusRow($p)['last_error']==='LogicSMS delivery status: INVALID','Charged outbound INVALID report is an accepted delivery failure, not a failed submission');
foreach(['INVALID','ERROR','FAILED','REJECTED','CREATE','CREATED','QUEUED','UNKNOWN','UNAVAILABLE','DLR_UNAVAILABLE'] as $status){
    $p=statusFixture();$before=statusDump($p);$r=SmsStatusReconciliation::apply($p,1,statusEvidence(1,'provider_query',$status));$afterUnproven=statusDump($p);
    statusCheck(!$r['accepted']&&!$r['charged_now']&&!$r['applied']&&$r['code']==='ACCEPTANCE_NOT_PROVEN'&&$afterUnproven['sms_messages']===$before['sms_messages']&&$afterUnproven['wallets']===$before['wallets']&&$afterUnproven['wallet_ledger']===$before['wallet_ledger'],'A held '.$status.' lookup does not prove billable acceptance or overwrite its hold');
}
foreach([[],['provider_report_credits'=>1],['acceptance_confirmed_by_report'=>true],['provider_report_credits'=>0,'acceptance_confirmed_by_report'=>true]] as $proof){
    $p=statusFixture();$e=array_replace(statusEvidence(1,'owner_report','INVALID'),$proof);$r=SmsStatusReconciliation::apply($p,1,$e);
    statusCheck(!$r['charged_now']&&!$r['accepted'],'Owner INVALID report needs both confirmed acceptance and positive actual report credits');
}
foreach(['SENT','DELIVERED','DELIVRD','UNDELIVERED','UNDELIV','BLOCKED','EXPIRED'] as $status){
    $p=statusFixture();$r=SmsStatusReconciliation::apply($p,1,statusEvidence(1,'authenticated_callback',$status));$money=statusDump($p);
    statusCheck($r['accepted']&&$r['charged_now'],'Authenticated callback '.$status.' safely settles a no-lease held submission');
    $e=statusEvidence(1,'provider_query',$status);$again=SmsStatusReconciliation::apply($p,1,$e);
    statusCheck(!$again['charged_now']&&statusDump($p)['wallets']===$money['wallets']&&statusDump($p)['wallet_ledger']===$money['wallet_ledger'],'Query after callback '.$status.' never charges a second time');
}
$p=statusFixture();SmsStatusReconciliation::apply($p,1,statusEvidence(1,'authenticated_callback','BLOCKED'));$blocked=statusRow($p);$r=SmsStatusReconciliation::apply($p,1,statusEvidence(1,'provider_query','DELIVERED'));
statusCheck(!$r['applied']&&statusRow($p)===$blocked,'Final provider block remains final despite conflicting later delivery');
$p=statusFixture();SmsStatusReconciliation::apply($p,1,statusEvidence(1,'authenticated_callback','UNDELIVERED'));$money=statusDump($p);$r=SmsStatusReconciliation::apply($p,1,statusEvidence(1,'provider_query','DELIVERED'));
statusCheck($r['state']==='delivered'&&statusRow($p)['failed_at']===null&&!$r['charged_now']&&statusDump($p)['wallet_ledger']===$money['wallet_ledger'],'Genuine delivery can correct a generic failed outcome without rebilling');
$p=statusFixture();SmsStatusReconciliation::apply($p,1,statusEvidence(1,'provider_query','UNDELIVERED'));$r=SmsStatusReconciliation::apply($p,1,statusEvidence(1,'authenticated_callback','BLOCKED'));
statusCheck($r['applied']&&statusRow($p)['last_error']==='LogicSMS delivery status: BLOCKED'&&!$r['charged_now'],'Specific block refines generic failure diagnostics without another charge');
$p=statusFixture();SmsStatusReconciliation::apply($p,1,statusEvidence(1,'provider_query','SENT'));$p->ownsWorkerLock=false;$r=SmsStatusReconciliation::apply($p,1,statusEvidence(1,'authenticated_callback','DELIVERED'));
statusCheck($r['state']==='delivered'&&!$r['charged_now'],'Already accepted updates do not require the dispatch lock or financial mutation');
$p=statusFixture();$p->exec("INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,operation,direction,amount,idempotency_key,created_at) VALUES(2,11,NULL,'manual_adjustment','debit',3,'admin:standalone:debit',NOW());UPDATE wallets SET balance=172 WHERE id=11");
$adjustment=$p->query("SELECT * FROM wallet_ledger WHERE idempotency_key='admin:standalone:debit'")->fetch(PDO::FETCH_ASSOC);
$r=SmsStatusReconciliation::apply($p,1,statusEvidence());$w=$p->query('SELECT balance,reserved FROM wallets WHERE id=11')->fetch(PDO::FETCH_ASSOC);
statusCheck($r['charged_now']&&(float)$w['balance']===171.65&&(float)$w['reserved']===.7,'Standalone adjustment debit affects balance only and cannot manufacture a reservation shortfall');
statusCheck($p->query("SELECT * FROM wallet_ledger WHERE idempotency_key='admin:standalone:debit'")->fetch(PDO::FETCH_ASSOC)===$adjustment,'Existing standalone adjustment debit metadata remains unchanged');
$p=statusFixture();$p->exec('UPDATE wallets SET reserved=7.56 WHERE id=11');$openingBefore=statusDump($p);$r=SmsStatusReconciliation::apply($p,1,statusEvidence());$openingAfter=statusDump($p);
$openingWallet=array_column($openingAfter['wallets'],null,'id')[11];
statusCheck($r['charged_now']&&(float)$openingWallet['balance']===174.65&&(float)$openingWallet['reserved']===7.21,'A 6.51 unattributed reservation is retained alongside other canonical message reservations');
statusCheck(count($openingAfter['wallet_ledger'])===count($openingBefore['wallet_ledger'])+1&&$openingAfter['wallet_ledger'][0]===$openingBefore['wallet_ledger'][0]&&$openingAfter['wallet_ledger'][1]===$openingBefore['wallet_ledger'][1],'Unattributed reservation is not repaired, backfilled, released or written to the ledger');
$openingPreAudit=json_decode($openingAfter['operational_logs'][0]['context_json'],true,512,JSON_THROW_ON_ERROR);$openingPostAudit=json_decode($openingAfter['operational_logs'][1]['context_json'],true,512,JSON_THROW_ON_ERROR);
$preComponents=$openingPreAudit['before']['financial']['reservation_components'];$postComponents=$openingPostAudit['after']['financial']['reservation_components'];
statusCheck($preComponents['unattributed_reserved_component']==='6.5100'&&$postComponents['unattributed_reserved_component']==='6.5100'&&$preComponents['canonical_reserved_units']-$postComponents['canonical_reserved_units']===3500,'Before/after audits explicitly preserve the unattributed amount while reducing canonical reservation exactly 35c');
statusCheck($preComponents['unattributed_component_semantics']==='retained_unattributed_not_backfilled_released_or_spent','Audit does not claim unknown reservation is proven legacy history');
statusCheck((int)round(((float)$openingWallet['balance']-(float)$openingWallet['reserved'])*10000)===(int)round((175-7.56)*10000),'Settlement leaves customer available funds unchanged and never spends the unattributed component');
$r=SmsStatusReconciliation::apply($p,1,statusEvidence());statusCheck($r['duplicate']&&statusDump($p)===$openingAfter,'Repeated evidence preserves the unattributed component without further debit or audit');

foreach([
    ['source'=>'anonymous_report'],['user_id'=>9],['campaign_id'=>90],['original_reference'=>'portal-message-2'],['original_reference'=>'9999'],['status'=>'working'],['status'=>'Cannot Find Message'],['provider_message_id'=>'9999'],['account_email'=>'other@example.test'],['user_id'=>'2.0'],
] as $overrides){$p=statusFixture();statusReject($p,array_replace(statusEvidence(),$overrides),'Untrusted source, wrong identity/ref/account/status or colliding provider ID is rejected');}
foreach(['destination'=>'+27820000000','content_sha256'=>hash('sha256','Only a truncated prefix'),'campaign_created_at'=>'2026-10-24 00:00:00','kind'=>'closest_timestamp'] as $field=>$value){$p=statusFixture();$e=statusEvidence(1,'owner_report');$e['match_proof'][$field]=$value;statusReject($p,$e,'Report mismatch '.$field.' is never guessed');}
$p=statusFixture();$e=statusEvidence(1,'owner_report');unset($e['match_proof']);statusReject($p,$e,'Report without exact full-content/destination/time proof rejected');
$p=statusFixture();$p->ownsWorkerLock=false;statusReject($p,statusEvidence(),'Held settlement cannot bypass real dispatcher lock ownership');
$eligible=statusRow(statusFixture());
foreach(['provider_message_id','lease_token','leased_at','sent_at'] as $field)statusCheck(!SmsStatusReconciliation::eligible(array_replace($eligible,[$field=>'0'])),'Literal string zero in '.$field.' is not a missing identifier/lease/time');
foreach([
    "UPDATE sms_messages SET lease_token='active',leased_at=NOW(),state='processing' WHERE id=1"=>'A callback outrunning the active sender is deferred',
    "UPDATE sms_messages SET state='pending',attempt_count=0,next_attempt_at=NULL WHERE id=1"=>'Never-attempted pending message cannot be accepted by forged timing',
    "UPDATE sms_messages SET state='skipped' WHERE id=1"=>'Skipped messages are not silently resurrected',
    "UPDATE sms_messages SET lease_token='0' WHERE id=1"=>'A literal zero lease token is not treated as an absent lease',
    "UPDATE sms_messages SET provider_message_id='0' WHERE id=1"=>'A literal zero provider ID is not treated as absent acceptance metadata',
] as $sql=>$label){$p=statusFixture();$p->exec($sql);$before=statusDump($p);$r=SmsStatusReconciliation::apply($p,1,statusEvidence(1,'authenticated_callback'));statusCheck(!$r['charged_now']&&!$r['applied']&&statusDump($p)['sms_messages']===$before['sms_messages']&&statusDump($p)['wallet_ledger']===$before['wallet_ledger'],$label);}
$p=statusFixture();$p->exec("UPDATE sms_messages SET state='processing',lease_token='active',leased_at=NOW() WHERE id=1");$early=statusEvidence(1,'authenticated_callback');
$r=SmsStatusReconciliation::apply($p,1,$early);statusCheck($r['code']==='SUBMISSION_NOT_ELIGIBLE'&&!$r['charged_now'],'Immediate callback is first retained without bypassing the live sender');
$p->exec("UPDATE sms_messages SET state='retry',lease_token=NULL,leased_at=NULL WHERE id=1");$r=SmsStatusReconciliation::apply($p,1,$early);$completed=statusDump($p);
statusCheck($r['charged_now']&&$r['state']==='delivered'&&count($completed['operational_logs'])===4,'Exact deferred callback settles once when the sender releases its uncertain hold');
$r=SmsStatusReconciliation::apply($p,1,$early);statusCheck($r['duplicate']&&!$r['charged_now']&&statusDump($p)===$completed,'Replayed deferred-then-applied callback has no further writes');
foreach([
    "UPDATE sms_campaigns SET user_id=9 WHERE id=24"=>'Campaign ownership drift rejected',
    "UPDATE sms_messages SET provider='other' WHERE id=1"=>'Foreign provider rejected',
    "UPDATE wallets SET currency='USD' WHERE id=11"=>'Currency mismatch rejected',
    "UPDATE wallets SET balance=.1 WHERE id=11"=>'Insufficient balance rejected',
    "UPDATE wallets SET reserved=.7 WHERE id=11"=>'Cannot spend another campaign reservation through wallet-ledger drift',
    "UPDATE wallet_ledger SET amount=.35 WHERE campaign_id=24;UPDATE wallets SET reserved=.7 WHERE id=11"=>'Partial current reservation cannot steal the remaining other-message 35c',
    "UPDATE wallet_ledger SET wallet_id=19 WHERE campaign_id=24"=>'Wrong-wallet reservation rejected',
    "UPDATE wallet_ledger SET idempotency_key='not-the-original-reservation' WHERE campaign_id=24"=>'Original reservation identity required',
    "INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,operation,direction,amount,idempotency_key) VALUES(9,19,90,'sms_settlement','debit',.35,'sms-message:1:settle')"=>'Cross-account settlement idempotency-key collision rejected',
    "INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,operation,direction,amount,idempotency_key) VALUES(2,11,24,'unknown_reservation','reserve',.35,'unknown:campaign:reserve');UPDATE wallets SET reserved=1.4 WHERE id=11"=>'Unknown operation cannot create spendable campaign reservations even if totals reconcile',
    "INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,operation,direction,amount,idempotency_key) VALUES(2,11,NULL,'unknown_reservation','reserve',.35,'unknown:unscoped:reserve');UPDATE wallets SET reserved=1.4 WHERE id=11"=>'Unknown unscoped reservation operations fail closed',
    "UPDATE wallet_ledger SET direction='credit' WHERE campaign_id=24"=>'Known reservation operation with mismatched direction fails closed',
    "INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,operation,direction,amount,idempotency_key) VALUES(2,11,24,'unknown_campaign_debit','debit',.35,'unknown:campaign:debit')"=>'Unknown campaign debit cannot be mistaken for a canonical SMS settlement',
    "UPDATE wallets SET reserved=7.56,balance=7 WHERE id=11"=>'Positive unattributed amount cannot excuse wallet reservations exceeding cash balance',
    "UPDATE wallets SET reserved=7.21 WHERE id=11;UPDATE wallet_ledger SET amount=.35 WHERE campaign_id=24"=>'Large unattributed component cannot fund under-reserved current campaign liabilities',
    "UPDATE wallets SET reserved=7.56 WHERE id=11;DELETE FROM wallet_ledger WHERE campaign_id=24"=>'Large unattributed component cannot replace a missing original campaign reservation',
    "UPDATE wallets SET reserved=7.56 WHERE id=11;UPDATE wallet_ledger SET idempotency_key='wrong-reserve-key' WHERE campaign_id=24"=>'Positive unattributed component never bypasses original reservation key guards',
    "UPDATE wallets SET reserved=6.51 WHERE id=11;INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,operation,direction,amount,idempotency_key) VALUES(2,11,24,'reservation_release','release',1.05,'campaign:24:release:corrupt')"=>'Negative current campaign outstanding is rejected even when global canonical sum is zero and unattributed amount positive',
    "UPDATE wallets SET reserved=6.86 WHERE id=11;INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,operation,direction,amount,idempotency_key) VALUES(2,11,91,'reservation_release','release',.7,'campaign:91:release:corrupt')"=>'Negative other campaign outstanding cannot hide behind a positive wallet total and unattributed component',
] as $sql=>$label){$p=statusFixture();$p->exec($sql);statusReject($p,statusEvidence(),$label);}
foreach([
    "CREATE TRIGGER fail_before BEFORE INSERT ON operational_logs WHEN NEW.event='status_evidence_before' BEGIN SELECT RAISE(ABORT,'Before audit unavailable'); END"=>'Mandatory pre-audit failure rolls back everything',
    "CREATE TRIGGER fail_settlement BEFORE INSERT ON wallet_ledger WHEN NEW.operation='sms_settlement' BEGIN SELECT RAISE(ABORT,'Settlement failed'); END"=>'Settlement failure leaves original hold, reservation and audits intact',
    "CREATE TRIGGER fail_message BEFORE UPDATE ON sms_messages WHEN NEW.id=1 BEGIN SELECT RAISE(ABORT,'Message failed'); END"=>'Message-write failure rolls back the debit',
    "CREATE TRIGGER fail_campaign BEFORE UPDATE ON sms_campaigns WHEN NEW.id=24 BEGIN SELECT RAISE(ABORT,'Projection failed'); END"=>'Campaign projection failure rolls back the debit and row',
    "CREATE TRIGGER fail_after BEFORE INSERT ON operational_logs WHEN NEW.event='status_evidence_after' BEGIN SELECT RAISE(ABORT,'After audit unavailable'); END"=>'Mandatory post-audit failure rolls back all mutation',
    "CREATE TRIGGER corrupt_content AFTER UPDATE ON sms_messages WHEN NEW.id=1 BEGIN UPDATE sms_messages SET content='Corrupt' WHERE id=1;END"=>'Unexpected original content change is detected and rolled back',
    "CREATE TRIGGER corrupt_wallet AFTER UPDATE ON wallets WHEN NEW.id=11 BEGIN UPDATE wallets SET balance=balance+.1 WHERE id=11;END"=>'Unexpected wallet delta is detected and rolled back',
    "CREATE TRIGGER corrupt_campaign_name AFTER UPDATE ON sms_campaigns WHEN NEW.id=24 BEGIN UPDATE sms_campaigns SET name='Overwritten campaign' WHERE id=24;END"=>'Unexpected immutable campaign metadata change rolls back all mutations',
    "CREATE TRIGGER corrupt_campaign_counter AFTER UPDATE ON sms_campaigns WHEN NEW.id=24 BEGIN UPDATE sms_campaigns SET sent_count=sent_count+1 WHERE id=24;END"=>'Allowed counter field must still equal source-message projection or roll back',
    "CREATE TRIGGER corrupt_campaign_cost AFTER UPDATE ON sms_campaigns WHEN NEW.id=24 BEGIN UPDATE sms_campaigns SET actual_cost=actual_cost+.1 WHERE id=24;END"=>'Allowed cost field must still equal canonical message charges or roll back',
    "CREATE TRIGGER corrupt_campaign_state AFTER UPDATE ON sms_campaigns WHEN NEW.id=24 BEGIN UPDATE sms_campaigns SET state='completed' WHERE id=24;END"=>'Allowed state field cannot falsely complete a campaign with held messages',
] as $sql=>$label){$p=statusFixture();$p->exec($sql);statusReject($p,statusEvidence(),$label);}
$p=statusFixture();$p->exec("UPDATE wallets SET reserved=7.56 WHERE id=11;CREATE TRIGGER change_unattributed AFTER UPDATE ON wallets WHEN NEW.id=11 BEGIN UPDATE wallets SET reserved=reserved+.01 WHERE id=11;END");
statusReject($p,statusEvidence(),'Post-settlement one-cent unattributed component increase triggers complete rollback');
$p=statusFixture();$p->exec("UPDATE wallets SET reserved=7.56 WHERE id=11;CREATE TRIGGER spend_unattributed AFTER UPDATE ON wallets WHEN NEW.id=11 BEGIN UPDATE wallets SET reserved=reserved-.01 WHERE id=11;END");
statusReject($p,statusEvidence(),'Post-settlement one-cent unattributed component spend triggers complete rollback');
$p=statusFixture();$p->exec("UPDATE wallets SET reserved=7.56 WHERE id=11;CREATE TRIGGER fail_opening_audit BEFORE INSERT ON operational_logs WHEN NEW.event='status_evidence_after' BEGIN SELECT RAISE(ABORT,'Mandatory audit unavailable');END");
statusReject($p,statusEvidence(),'Mandatory audit failure rolls back debit, canonical reservation and unattributed component together');
$p=statusFixture();SmsStatusReconciliation::apply($p,1,statusEvidence());$p->exec("DELETE FROM operational_logs WHERE event='status_evidence_after'");statusReject($p,statusEvidence(),'Incomplete prior receipt blocks partial-journal reuse');
$p=statusFixture();$p->beginTransaction();$caught=false;try{SmsStatusReconciliation::apply($p,1,statusEvidence());}catch(DomainException){$caught=true;}statusCheck($caught&&$p->inTransaction(),'Caller transaction remains owned by caller');$p->rollBack();

final class NeverSendStatusProvider implements SmsProvider
{
    public int $calls=0;public function name():string{return 'logicsms';}
    public function send(string $destination,string $content,?string $senderId=null,?string $clientReference=null):array{$this->calls++;throw new RuntimeException('No resend permitted');}
}
$pdo=statusFixture();SmsStatusReconciliation::apply($pdo,1,statusEvidence());$before=statusDump($pdo);$provider=new NeverSendStatusProvider();SmsDispatchService::process(statusRow($pdo),$provider);
statusCheck($provider->calls===0&&statusDump($pdo)===$before,'Real sender refuses to retransmit a reconciled message');
statusCheck(!str_contains(file_get_contents(__DIR__.'/../domain/SmsStatusReconciliation.php'),'->send('),'Core has no transport call');
echo 'PASS: '.$checks." generic evidence/status/held-accounting/idempotence/rollback checks; SQLite only, no SMS or network.\n";
