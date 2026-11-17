<?php
// Hermetic SQLite fixtures and MIME generation only. Never loads .env or opens SMTP.
declare(strict_types=1);
function env(string $key,mixed $default=null): mixed {return $_ENV[$key]??$default;}
require_once __DIR__.'/../domain/EmailCampaignService.php';
require_once __DIR__.'/../domain/EmailCampaignWebhook.php';
require_once __DIR__.'/../services/EmailCampaignTransport.php';
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function ok(bool $condition,string $message): void {global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
function fails(callable $f,int $status,string $label): void {try{$f();throw new RuntimeException('Expected failure: '.$label);}catch(EmailCampaignException $e){ok($e->status===$status,$label.' status '.$e->status);}}
function fixture(): array
{
    $p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,email_verified_at TEXT,is_active INTEGER,role TEXT);
        INSERT INTO users VALUES(1,'owner@example.org','2026-01-01',1,'user'),(2,'other@example.org','2026-01-01',1,'user'),(90,'reviewer@example.org','2026-01-01',1,'admin'),(91,'reviewer2@example.org','2026-01-01',1,'admin');
        CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER,balance NUMERIC,reserved NUMERIC,currency TEXT,updated_at TEXT);
        INSERT INTO wallets VALUES(1,1,100,10,'ZAR','before'),(2,2,100,0,'ZAR','before'),(90,90,100,0,'ZAR','before');
        CREATE TABLE wallet_ledger(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,wallet_id INTEGER,campaign_id INTEGER,payment_id INTEGER,operation TEXT,direction TEXT,amount NUMERIC,idempotency_key TEXT UNIQUE,metadata_json TEXT,created_at TEXT);
        CREATE TABLE contacts(id INTEGER PRIMARY KEY,user_id INTEGER,email TEXT);
        INSERT INTO contacts VALUES(1,1,'one@example.org'),(2,1,'two@example.org'),(3,1,NULL),(4,2,'private@example.org');
        CREATE TABLE contact_groups(id INTEGER PRIMARY KEY,user_id INTEGER);
        INSERT INTO contact_groups VALUES(1,1),(2,2);
        CREATE TABLE group_contacts(group_id INTEGER,contact_id INTEGER);
        INSERT INTO group_contacts VALUES(1,1),(1,2),(1,3),(2,4);
        CREATE TABLE opt_outs(user_id INTEGER,channel TEXT,recipient TEXT);
        INSERT INTO opt_outs VALUES(1,'sms','+27821111111'),(1,'email','old@example.org'),(2,'email','one@example.org');
        CREATE TABLE sms_messages(id INTEGER,state TEXT,actual_charge TEXT);INSERT INTO sms_messages VALUES(100,'sent','0.3500');");
    $migration=require __DIR__.'/../migrations/019_email_campaigns.php';$migration($p);$migration($p);
    return [$p,new EmailCampaignService($p)];
}
function input(array $emails=['one@example.org','two@example.org']): array {return ['name'=>'Consent-based update','subject'=>'Community update','body'=>"Hello,\nHere is your requested update.",'recipients'=>['manual'=>$emails,'contact_ids'=>[],'group_ids'=>[]],'consent_confirmed'=>true,'consent_evidence'=>'Signed signup form, reference signup-2026-10-01; recipients agreed to community updates.','idempotency_key'=>bin2hex(random_bytes(16))];}
function sender(EmailCampaignService $s,int $user=1,int $reviewer=90): void {$profile=$s->saveSender($user,['business_name'=>'Example Community','postal_address'=>'100 Example Street, Example City']);$s->reviewSender($reviewer,$user,['decision'=>'approved','daily_limit'=>100,'expected_version'=>$profile['version'],'evidence'=>'Organization authorization checked with independent signed evidence.']);}
function approved(EmailCampaignService $s,array $i,int $user=1): array {$c=$s->create($user,$i);return $s->review(90,$c['id'],['decision'=>'approved','fingerprint'=>$c['fingerprint'],'evidence'=>'Consent list verified against signed records; message and all destination links reviewed.']);}
function wallet(PDO $p): array {$w=$p->query('SELECT balance,reserved FROM wallets WHERE user_id=1')->fetch(PDO::FETCH_ASSOC);return array_map(static fn($v)=>round((float)$v,4),$w);}
$_ENV=['SMS_PRICE_PER_SEGMENT'=>'0.3500','EMAIL_CAMPAIGNS_ENABLED'=>'true','EMAIL_CAMPAIGNS_SEND_ENABLED'=>'false','EMAIL_PROVIDER_APPROVED'=>'true','EMAIL_PROVIDER_APPROVAL_REFERENCE'=>'fixture-approved-infrastructure-only','EMAIL_DOMAIN_AUTH_VERIFIED'=>'true','EMAIL_DOMAIN_AUTH_REFERENCE'=>'fixture-DNS-check-only','EMAIL_CAMPAIGN_FEEDBACK_VERIFIED'=>'true','EMAIL_CAMPAIGN_WEBHOOK_SECRET'=>str_repeat('fixture-',8),'EMAIL_CAMPAIGN_SMTP_HOST'=>'smtp.example.org','EMAIL_CAMPAIGN_SMTP_PORT'=>'465','EMAIL_CAMPAIGN_SMTP_ENCRYPTION'=>'ssl','EMAIL_CAMPAIGN_SMTP_USER'=>'fixture-account','EMAIL_CAMPAIGN_SMTP_PASS'=>'fixture-only-not-a-real-password','EMAIL_CAMPAIGN_FROM'=>'campaigns@example.org','EMAIL_CAMPAIGN_PUBLIC_URL'=>'https://portal.example.org/api','EMAIL_CAMPAIGN_HOURLY_LIMIT'=>100,'EMAIL_CAMPAIGN_DAILY_LIMIT'=>1000];
[$p,$s]=fixture();$sms=$p->query('SELECT * FROM sms_messages')->fetchAll(PDO::FETCH_ASSOC);
ok(EmailCampaignPolicy::unitCents()===7,'Exactly .07 ZAR per accepted email');
ok(EmailCampaignPolicy::credits(5)==='1.0'&&EmailCampaignPolicy::credits(1)==='0.2','Five emails per existing credit, no rounding up');
foreach(['0','0.3333','-1','n/a','0.0001'] as $invalid){$_ENV['SMS_PRICE_PER_SEGMENT']=$invalid;fails(fn()=>EmailCampaignPolicy::unitCents(),503,'Nonrepresentable price blocks safely');}$_ENV['SMS_PRICE_PER_SEGMENT']='0.3500';
fails(fn()=>$s->create(1,input()),403,'Unapproved sender cannot create campaign');sender($s);
ok($s->sender(1)['reply_to']==='owner@example.org','Reply-To forced to verified account, never caller-supplied');
fails(fn()=>$s->reviewSender(1,2,['decision'=>'approved']),403,'Customer cannot approve another sender');
$q=$s->quote(1,input(['ONE@example.org','one@example.org','bad','old@example.org','two@example.org']));
ok($q['sendable']===2&&$q['duplicates']===1&&$q['invalid']===1&&$q['opted_out']===1,'Dedupe, syntax, prior email opt-out and account isolation');
$i=input([]);$i['recipients']['group_ids']=['1'];$q=$s->quote(1,$i);ok($q['sendable']===2&&$q['invalid']===1,'Owned group email resolution');
foreach(['group_ids','contact_ids']as$field){$i=input([]);$i['recipients'][$field]=[$field==='group_ids'?'2':'4'];fails(fn()=>$s->quote(1,$i),404,'Cross-account contact selection denied');}
foreach(['from','html','attachments','headers']as$field){$i=input();$i[$field]='forged';fails(fn()=>$s->quote(1,$i),422,'Untrusted mail features denied');}
$i=input();$i['subject']="Hello\nBcc: victim@example.org";fails(fn()=>$s->create(1,$i),422,'Header injection');
foreach(['http://example.org','https://127.0.0.1/admin','https://user:pass@example.org/','https://local.internal/']as$url){$i=input();$i['body']='Click '.$url;fails(fn()=>$s->create(1,$i),422,'Unsafe/non-TLS link denied');}
$i=input();$i['body']='See https://example.org/page?q=update';ok($s->quote(1,$i)['sendable']===2,'Normal public HTTPS query-string links work');
$i=input();$i['consent_confirmed']=false;fails(fn()=>$s->create(1,$i),422,'SMS contacts are not automatically email consent');
$i=input();$c=$s->create(1,$i);ok($c['state']==='awaiting_review','No automatic approval');ok(wallet($p)===['balance'=>100.0,'reserved'=>10.0],'Review cannot change wallet');
ok($s->create(1,$i)['id']===$c['id'],'Lost-response retry idempotent');
$changed=$i;$changed['body']='Changed';fails(fn()=>$s->create(1,$changed),409,'Idempotency payload mismatch');
fails(fn()=>$s->detail(2,$c['id']),404,'Campaign isolation');fails(fn()=>$s->detail(2,$c['id'],true),403,'Admin-detail flag cannot bypass role');fails(fn()=>$s->adminOverview(1),403,'Admin list role guard');
fails(fn()=>$s->review(90,$c['id'],['decision'=>'approved','fingerprint'=>'stale','evidence'=>str_repeat('Reviewed ',4)]),409,'Stale approval blocked');
$c=$s->review(90,$c['id'],['decision'=>'approved','fingerprint'=>$c['fingerprint'],'evidence'=>'Exact consent roster and message content reviewed independently.']);
fails(fn()=>$s->queue(1,$c['id']),503,'Launch switch cannot be bypassed');ok(wallet($p)['reserved']===10.0,'No reservation while disabled');
$_ENV['EMAIL_CAMPAIGNS_SEND_ENABLED']='true';ok(EmailCampaignPolicy::readiness()['ready'],'Explicit fixture readiness');
$c=$s->queue(1,$c['id']);ok(wallet($p)===['balance'=>100.0,'reserved'=>10.14],'Reserve correct cost without touching SMS reservation');$s->queue(1,$c['id']);ok(wallet($p)['reserved']===10.14,'Queue idempotent');
$job=$s->claim();ok($job!==null&&$job['recipient']['attempts']===0,'Durable pre-submission claim');
$id=$job['recipient']['id'];$token=$job['unsubscribe_token'];$hash=$p->query("SELECT unsubscribe_hash FROM email_campaign_recipients WHERE id='{$id}'")->fetchColumn();ok($hash===hash('sha256',$token)&&$hash!==$token,'Unsubscribe bearer token only stored hashed');
$mail=EmailCampaignTransport::build($job);ok($mail->preSend(),'Generate MIME without SMTP');
ok($mail->From==='campaigns@example.org'&&$mail->FromName==='Example Community via IEOSUIA','Authenticated platform From and recognizable brand');
ok(count($mail->getToAddresses())===1&&!$mail->getBccAddresses(),'No recipient-list disclosure');
ok(in_array('owner@example.org',array_column($mail->getReplyToAddresses(),0),true),'Verified reply address');
ok($mail->SMTPDebug===0&&$mail->SMTPOptions['ssl']['verify_peer']===true&&$mail->SMTPSecure==='ssl','TLS and no debug leakage');
ok(str_contains($mail->getSentMIMEMessage(),'List-Unsubscribe-Post: List-Unsubscribe=One-Click'),'One-click unsubscribe headers present');
ok(str_contains($mail->AltBody,'Report abuse:')&&str_contains($mail->AltBody,'100 Example Street'),'Mandatory identity and abuse footer');
ok($mail->MessageID===$job['recipient']['message_id'],'Stable saved Message-ID');
$x=EmailCampaignPolicy::render(['body'=>'<script>alert(1)</script>'],['business_name'=>'<b>Company</b>','postal_address'=>'Address','reply_to'=>'owner@example.org'],'https://example.org/unsubscribe','https://example.org/report');ok(!str_contains($x['html'],'<script>')&&str_contains($x['html'],'&lt;script&gt;'),'Customer HTML cannot execute in delivered content');
$smtp=new EmailCampaignSmtpEvidence();ok($smtp->outcome()==='rejected','Failure before SMTP DATA is definite failure');$smtp->enteredData=true;ok($smtp->outcome()==='uncertain','Lost acknowledgement after DATA held');$smtp->accepted=true;ok($smtp->outcome()==='accepted','Accepted response survives later cleanup failure');$smtp->accepted=false;$smtp->explicitRejection=true;ok($smtp->outcome()==='rejected','Explicit DATA rejection is not charged');
$s->unsubscribe($id,$token);ok((int)$p->query('SELECT COUNT(*) FROM email_campaign_suppressions')->fetchColumn()===0,'GET preference inspection makes no changes');
fails(fn()=>$s->unsubscribe($id,str_repeat('a',64),true),404,'Invalid capability cannot suppress anyone');
$s->recordOutcome($id,'accepted');ok(wallet($p)['balance']===100.0,'Accepted evidence persisted before wallet settlement');$s->recordOutcome($id,'accepted');ok($s->settleAccepted()===1,'One acceptance settles once');ok($s->settleAccepted()===0,'Settlement replay no duplicate debit');
ok(wallet($p)===['balance'=>99.93,'reserved'=>10.07],'Accepted recipient billed exactly .2 credit');
$job2=$s->claim();$s->recordOutcome($job2['recipient']['id'],'rejected');ok(wallet($p)===['balance'=>99.93,'reserved'=>10.0],'Definite failure releases only owned reserve, no charge');ok($s->detail(1,$c['id'])['state']==='completed','Completion independent of inbox delivery');
$s->cancel(1,$c['id']);ok($s->detail(1,$c['id'])['state']==='completed','Cancelling completed history does not relabel it');
$s->unsubscribe($id,$token,true);$s->unsubscribe($id,$token,true);ok((int)$p->query('SELECT COUNT(*) FROM email_campaign_suppressions')->fetchColumn()===1,'Unsubscribe idempotent and channel/account scoped');
ok($s->quote(1,input([$job['recipient']['email']]))['sendable']===0,'Unsubscribe cannot be overwritten by a new import/claim');
// Uncertain submissions, cancellation and exact-message signed reconciliation.
$c2=approved($s,input(['three@example.org','four@example.org']));$s->queue(1,$c2['id']);$held=$s->claim();$s->recordOutcome($held['recipient']['id'],'uncertain');
ok($s->detail(1,$c2['id'])['state']==='held','Uncertain outcome visibly held');$s->cancel(1,$c2['id']);ok(round(wallet($p)['reserved']-10,4)===0.07,'Cancel releases queued only, not uncertain funds');ok($s->claim()===null,'Held/cancelled cannot be resent');
$event=['event_id'=>'provider-event-0001','recipient_id'=>$held['recipient']['id'],'message_id'=>$held['recipient']['message_id'],'event'=>'delivered'];
$raw=json_encode($event);$timestamp=(string)time();$signature=hash_hmac('sha256',$timestamp.'.'.$raw,$_ENV['EMAIL_CAMPAIGN_WEBHOOK_SECRET']);
ok(EmailCampaignWebhook::verify($raw,$timestamp,$signature)===$event,'Webhook HMAC verifies exact bytes');fails(fn()=>EmailCampaignWebhook::verify($raw.' ',$timestamp,$signature),401,'Webhook payload tampering');fails(fn()=>EmailCampaignWebhook::verify($raw,(string)(time()-1000),$signature),401,'Webhook timestamp expiry');fails(fn()=>EmailCampaignWebhook::verify($raw,$timestamp,str_repeat('0',64)),401,'Forged feedback blocked');
$hash=hash('sha256',$raw);ok(!$s->feedback($event,$hash)['duplicate'],'Signed evidence recorded');ok($s->feedback($event,$hash)['duplicate'],'Callback duplicate is idempotent');fails(fn()=>$s->feedback($event,hash('sha256','changed')),409,'Reused event ID with changed body rejected');
$wrong=$event;$wrong['event_id']='provider-event-wrong';$wrong['message_id']='<wrong@example.org>';fails(fn()=>$s->feedback($wrong,hash('sha256',json_encode($wrong))),404,'Do not reconcile a different provider message');
ok($s->settleAccepted()===1,'Signed exact-message report resolves hold without submitting mail');ok(wallet($p)['reserved']===10.0,'Reconciliation preserves SMS reservation');
$complaint=$event;$complaint['event_id']='provider-complaint-0002';$complaint['event']='complaint';$s->feedback($complaint,hash('sha256',json_encode($complaint)));ok($s->sender(1)['state']==='suspended','Complaint pauses sender, not just campaign');fails(fn()=>$s->saveSender(1,['business_name'=>'Another name','postal_address'=>'Another address, Example City']),403,'Suspension cannot be evaded by editing identity');
// Changed identity, quota fairness and stale crashes in fresh fixtures.
[$p2,$s2]=fixture();sender($s2);$new=approved($s2,input());$s2->saveSender(1,['business_name'=>'New business','postal_address'=>'Different Example Street, City']);fails(fn()=>$s2->queue(1,$new['id']),403,'Changed sender invalidates pending sends');
sender($s2);$new=approved($s2,input());$s2->queue(1,$new['id']);$_ENV['EMAIL_CAMPAIGN_HOURLY_LIMIT']=1;$one=$s2->claim();ok($one!==null&&$s2->claim()===null,'Global quota counts all attempts across claims');$s2->recordOutcome($one['recipient']['id'],'rejected');ok($s2->claim()===null,'Failed sends do not refund abuse quota');$_ENV['EMAIL_CAMPAIGN_HOURLY_LIMIT']=100;
$stale=$s2->claim();$p2->prepare('UPDATE email_campaign_recipients SET started_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',time()-600),$stale['recipient']['id']]);ok($s2->holdStale()===1&&$s2->claim()===null,'Worker crash becomes held, never automatically resent');
[$pr,$sr]=fixture();sender($sr);$rolling=approved($sr,input(['rolling-one@example.org','rolling-two@example.org']));$sr->queue(1,$rolling['id']);$prior=$sr->claim();$sr->recordOutcome($prior['recipient']['id'],'rejected');$pr->prepare('UPDATE email_campaign_recipients SET started_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',strtotime(gmdate('Y-m-d H:00:00').' UTC')-1),$prior['recipient']['id']]);$_ENV['EMAIL_CAMPAIGN_HOURLY_LIMIT']=1;ok($sr->claim()===null,'Rolling-hour limit survives crossing a UTC hour boundary');$_ENV['EMAIL_CAMPAIGN_HOURLY_LIMIT']=100;$pr->prepare('UPDATE email_campaign_recipients SET started_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',strtotime(gmdate('Y-m-d 00:00:00').' UTC')-1),$prior['recipient']['id']]);$_ENV['EMAIL_CAMPAIGN_DAILY_LIMIT']=1;ok($sr->claim()===null,'Rolling-day limit survives midnight boundary');$_ENV['EMAIL_CAMPAIGN_DAILY_LIMIT']=1000;
ok($sms===$p->query('SELECT * FROM sms_messages')->fetchAll(PDO::FETCH_ASSOC),'SMS rows untouched');ok((int)$p->query('SELECT COUNT(*) FROM wallet_ledger WHERE campaign_id IS NOT NULL')->fetchColumn()===0,'No email ledger links to SMS campaign IDs');
// Every launch prerequisite fails closed independently.
foreach (['EMAIL_CAMPAIGNS_ENABLED'=>'false','EMAIL_CAMPAIGNS_SEND_ENABLED'=>'false','EMAIL_PROVIDER_APPROVED'=>'false','EMAIL_PROVIDER_APPROVAL_REFERENCE'=>'','EMAIL_DOMAIN_AUTH_VERIFIED'=>'false','EMAIL_DOMAIN_AUTH_REFERENCE'=>'','EMAIL_CAMPAIGN_FEEDBACK_VERIFIED'=>'false','EMAIL_CAMPAIGN_WEBHOOK_SECRET'=>'short','EMAIL_CAMPAIGN_SMTP_HOST'=>'','EMAIL_CAMPAIGN_SMTP_PASS'=>'','EMAIL_CAMPAIGN_SMTP_ENCRYPTION'=>'none','EMAIL_CAMPAIGN_SMTP_PORT'=>25,'EMAIL_CAMPAIGN_FROM'=>'forged','EMAIL_CAMPAIGN_PUBLIC_URL'=>'http://portal.example.org/api','EMAIL_CAMPAIGN_HOURLY_LIMIT'=>0,'EMAIL_CAMPAIGN_DAILY_LIMIT'=>0] as $setting=>$bad) {$original=$_ENV[$setting];$_ENV[$setting]=$bad;ok(!EmailCampaignPolicy::readiness()['ready'],'Fail-closed gate: '.$setting);$_ENV[$setting]=$original;}
[$p3,$s3]=fixture();sender($s3);$version=$s3->sender(1)['version'];$s3->saveSender(1,['business_name'=>'Changed while reviewed','postal_address'=>'100 Example Street, Example City']);fails(fn()=>$s3->reviewSender(90,1,['decision'=>'approved','daily_limit'=>100,'expected_version'=>$version,'evidence'=>'Old identity was checked before a concurrent customer update.']),409,'Stale sender review cannot approve unreviewed identity');sender($s3);
$noMoney=approved($s3,input());$p3->exec('UPDATE wallets SET balance=10 WHERE user_id=1');fails(fn()=>$s3->queue(1,$noMoney['id']),409,'No spending another SMS reservation');ok((int)$p3->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn()===0,'Insufficient-funds transaction leaves no ledger residue');$p3->exec('UPDATE wallets SET balance=100 WHERE user_id=1');
$s3->queue(1,$noMoney['id']);$pending=$s3->claim();$s3->recordOutcome($pending['recipient']['id'],'accepted');$before=wallet($p3);
$p3->exec("CREATE TRIGGER fail_email_ledger BEFORE INSERT ON wallet_ledger WHEN NEW.operation='email_debit' BEGIN SELECT RAISE(ABORT,'fixture accounting interruption'); END");
try{$s3->settleAccepted();throw new RuntimeException('Expected ledger interruption');}catch(PDOException){ok(wallet($p3)===$before,'Accounting interruption rolls back debit and reservation changes');}
ok($s3->detail(1,$noMoney['id'])['recipients'][array_search($pending['recipient']['id'],array_column($s3->detail(1,$noMoney['id'])['recipients'],'id'))]['state']==='accepted_pending','Durable acceptance survives accounting interruption');
$p3->exec('DROP TRIGGER fail_email_ledger');ok($s3->settleAccepted()===1&&$s3->settleAccepted()===0,'Accounting-only recovery, exactly one debit without SMTP');
$next=$s3->claim();$s3->recordOutcome($next['recipient']['id'],'uncertain');$token=$next['unsubscribe_token'];$rid=$next['recipient']['id'];
$s3->unsubscribe($rid,$token,true,true);ok($s3->sender(1)['state']==='suspended','Recipient abuse report pauses future sending');
$v=$s3->sender(1)['version'];$s3->reviewSender(90,1,['decision'=>'approved','daily_limit'=>10,'expected_version'=>$v,'evidence'=>'Complaint independently reviewed, corrective action confirmed.']);$s3->unsubscribe($rid,$token,true,true);ok($s3->sender(1)['state']==='approved','Replayed old complaint cannot repeatedly suspend an investigated sender');
$bounce=['event_id'=>'hard-bounce-0001','recipient_id'=>$rid,'message_id'=>$next['recipient']['message_id'],'event'=>'hard_bounce'];$s3->feedback($bounce,hash('sha256',json_encode($bounce)));ok($s3->settleAccepted()===1,'Authenticated bounce proves original submission; no resend');ok(wallet($p3)['balance']===99.86,'Post-acceptance bounce retains the documented acceptance charge');
$delayed=$bounce;$delayed['event_id']='delayed-delivery-0001';$delayed['event']='delivered';$s3->feedback($delayed,hash('sha256',json_encode($delayed)));ok($p3->query("SELECT delivery_state FROM email_campaign_recipients WHERE id='{$rid}'")->fetchColumn()==='hard_bounce','Out-of-order delivery cannot erase a hard bounce');
sender($s3,90,91);$adminCampaign=$s3->create(90,input(['admin-recipient@example.org']));fails(fn()=>$s3->review(90,$adminCampaign['id'],['decision'=>'approved','fingerprint'=>$adminCampaign['fingerprint'],'evidence'=>'Review attempt of my own campaign is not allowed.']),403,'No administrator self-approval');
ok($p3->query('SELECT balance FROM wallets WHERE user_id=2')->fetchColumn()==100,'Other account wallet preserved');
if(in_array('--write-preview',$argv,true)) {
    $dir=__DIR__.'/../../.sms-audit.local/email-campaigns-20261029/dist';
    if(!is_dir($dir))mkdir($dir,0700,true);
    file_put_contents($dir.'/email-fixture-preview.html',$mail->Body);
    file_put_contents($dir.'/email-fixture-preview.eml',$mail->getSentMIMEMessage());
}
echo "Email campaigns: {$checks} checks passed. SQLite/MIME only; no network, live data or email submissions.\n";
