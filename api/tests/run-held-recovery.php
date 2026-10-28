<?php
// In-memory adapter and real dispatcher/wallet; never uses a network provider.
require __DIR__.'/run-dispatch-no-resend.php';
require __DIR__.'/../domain/SmsHeldRecovery.php';
$pdo->exec("ALTER TABLE sms_messages ADD COLUMN created_at TEXT;CREATE TABLE operational_logs(id INTEGER PRIMARY KEY,level TEXT,service TEXT,event TEXT,message TEXT,campaign_id INTEGER,sms_message_id INTEGER,user_id INTEGER,context_json TEXT,created_at TEXT)");
// SQLite ignores CHAR widths. Enforce the production MySQL CHAR(36) contract
// explicitly so a synthetic fixture cannot hide an oversized recovery lease.
$pdo->exec("CREATE TRIGGER recovery_lease_width BEFORE UPDATE OF lease_token ON sms_messages WHEN length(NEW.lease_token)>36 BEGIN SELECT RAISE(ABORT,'sms_messages.lease_token exceeds production CHAR(36)'); END");
class RecoveryProvider implements SmsProvider {
 public int $calls=0;public array $references=[];public array $leaseLengths=[];public array $result=['success'=>true,'message_id'=>'recovery-id'];
 public function name():string{return 'logicsms';}
 public function send(string $destination,string $content,?string $senderId=null,?string $clientReference=null):array{$this->calls++;$this->references[]=$clientReference;if(preg_match('/^portal-message-([0-9]+)$/D',(string)$clientReference,$match)){$s=db()->prepare('SELECT lease_token FROM sms_messages WHERE id=?');$s->execute([(int)$match[1]]);$this->leaseLengths[]=strlen((string)$s->fetchColumn());}return $this->result;}
}
$p=new RecoveryProvider();$before=$checks;
$base=['state'=>'retry','attempt_count'=>1,'retry_generation'=>0,'next_attempt_at'=>'9999-12-31 23:59:59','lease_token'=>null,'sent_at'=>null,'provider_message_id'=>null,'actual_charge'=>0,'last_error'=>'Submission started; awaiting provider acknowledgement; automatic resend held'];
assertDispatch(SmsHeldRecovery::eligible($base,0)&&SmsHeldRecovery::eligible($base,86399),'Only inside the conservative 24-hour window');
foreach([-1,86400,172799] as $age)assertDispatch(!SmsHeldRecovery::eligible($base,$age),'Clock skew and expired duplicate window fail closed');
foreach(['state'=>'sent','attempt_count'=>2,'retry_generation'=>1,'lease_token'=>'active','sent_at'=>'2026-10-23','provider_message_id'=>'123','actual_charge'=>.35,'last_error'=>'Provider accepted reference 123'] as $field=>$value)assertDispatch(!SmsHeldRecovery::eligible(array_replace($base,[$field=>$value]),100),'Existing acceptance, retries and foreign holds are excluded');
$pdo->exec("INSERT INTO sms_campaigns(id,state,user_id) VALUES(24,'processing',1);UPDATE wallets SET balance=10,reserved=.7;INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,operation,direction,amount,idempotency_key,created_at) VALUES(1,1,24,'reservation','reserve',.7,'campaign:24:reserve',NOW());INSERT INTO sms_messages(id,campaign_id,user_id,state,estimated_charge,segment_count,attempt_count,next_attempt_at,last_error,created_at,destination,content) VALUES(900,24,1,'retry',.35,1,1,'9999-12-31 23:59:59','Submission started; awaiting provider acknowledgement; original error','2026-10-23 11:00:00','fixture','fixture'),(901,24,1,'retry',.35,1,1,'9999-12-31 23:59:59','Submission started; awaiting provider acknowledgement; original error','2026-10-23 11:00:00','fixture','fixture')");
foreach([[2,24,900],[1,23,900],[1,24,999]] as [$uid,$cid,$mid]){try{SmsHeldRecovery::retryOne($pdo,$uid,$cid,$mid,$p);throw new RuntimeException('Scope escaped');}catch(DomainException){assertDispatch($p->calls===0,'Wrong tenant/campaign/message causes no send');}}
$pdo->exec("CREATE TRIGGER recovery_injected_failure BEFORE UPDATE OF lease_token ON sms_messages WHEN NEW.id=900 AND NEW.lease_token IS NOT NULL BEGIN SELECT RAISE(ABORT,'Injected claim failure'); END");
try{SmsHeldRecovery::retryOne($pdo,1,24,900,$p);throw new RuntimeException('Claim failure did not abort');}catch(PDOException){
 $beforeClaim=$pdo->query('SELECT state,attempt_count,lease_token FROM sms_messages WHERE id=900')->fetch();
 assertDispatch($p->calls===0&&$beforeClaim['state']==='retry'&&(int)$beforeClaim['attempt_count']===1&&$beforeClaim['lease_token']===null,'Claim error rolls back before any provider call or attempt increment');
 assertDispatch((int)$pdo->query('SELECT COUNT(*) FROM operational_logs')->fetchColumn()===0&&(float)$pdo->query('SELECT balance FROM wallets')->fetchColumn()===10.0,'Claim failure rolls back the journal and never changes the wallet');
}
$pdo->exec('DROP TRIGGER recovery_injected_failure');
$result=SmsHeldRecovery::retryOne($pdo,1,24,900,$p);
assertDispatch($result['state']==='sent'&&$p->references===['portal-message-900'],'Recovered submission reuses the exact original Unique');
assertDispatch($p->leaseLengths===[32],'Recovery lease fits production CHAR(36) with 128 bits of randomness');
$w=$pdo->query('SELECT balance,reserved FROM wallets')->fetch();assertDispatch((float)$w['balance']===9.65&&(float)$w['reserved']===.35,'Exactly one settlement consumes existing reservation, no extra credit reservation');
assertDispatch(str_contains($pdo->query('SELECT context_json FROM operational_logs')->fetchColumn(),'original error'),'Original error is durably journaled before transport');
try{SmsHeldRecovery::retryOne($pdo,1,24,900,$p);throw new RuntimeException('Repeated recovery sent');}catch(DomainException){assertDispatch($p->calls===1,'Reload/double-click cannot resend a completed recovery');}
$p->result=['success'=>false,'uncertain'=>true,'error_code'=>'TRANSPORT_TIMEOUT'];$result=SmsHeldRecovery::retryOne($pdo,1,24,901,$p);
assertDispatch($result['state']==='retry'&&$result['attempt_count']===2&&str_contains($result['error'],'TRANSPORT_TIMEOUT'),'Uncertain recovery is held with actionable safe diagnostics');
try{SmsHeldRecovery::retryOne($pdo,1,24,901,$p);throw new RuntimeException('Uncertain recovery repeated');}catch(DomainException){assertDispatch($p->calls===2,'One uncertain recovery cannot be tried again');}
assertDispatch($w===$pdo->query('SELECT balance,reserved FROM wallets')->fetch(),'Uncertain result never debits or frees its reservation');
$p->result=['success'=>false,'uncertain'=>false,'retryable'=>false,'error_code'=>'PROVIDER_AUTHENTICATION'];
$wrapped=(new SmsRecoveryProvider($p))->send('fixture','fixture',null,'portal-message-fixture');
assertDispatch($wrapped['uncertain']===true,'Even an explicit rejection of the recovery cannot disprove original acceptance or release original reserved funds');
echo 'PASS: '.($checks-$before)." held-recovery scope/window/accounting checks; no SMS sent.\n";
