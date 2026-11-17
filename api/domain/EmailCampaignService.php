<?php
require_once __DIR__.'/EmailCampaignPolicy.php';

/** Account-owned email outbox. Never reuses SMS IDs, provider credentials or ledger keys. */
final class EmailCampaignService
{
    public function __construct(private PDO $pdo) {}
    private function lock(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''; }
    private function now(): string { return gmdate('Y-m-d H:i:s'); }
    private function run(string $sql,array $args=[]): PDOStatement { $q=$this->pdo->prepare($sql); $q->execute($args); return $q; }
    private function one(string $sql,array $args=[]): ?array { return $this->run($sql,$args)->fetch(PDO::FETCH_ASSOC)?:null; }
    private function tx(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {$result=$operation();$this->pdo->commit();return $result;}
        catch(Throwable $e) {if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function audit(int $user,?int $actor,?string $campaign,string $event,string $evidence=''): void
    {
        $this->run('INSERT INTO email_campaign_audit(id,user_id,actor_id,campaign_id,event,evidence,created_at) VALUES(?,?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$user,$actor,$campaign,$event,$evidence,$this->now()]);
    }
    private function account(int $user,bool $lock=false): array
    {
        $u=$this->one('SELECT * FROM users WHERE id=?'.($lock?$this->lock():''),[$user]);
        if (!$u || !(bool)($u['is_active']??true) || empty($u['email_verified_at']) || !EmailCampaignPolicy::email((string)$u['email'])) throw new EmailCampaignException('A verified, active portal account is required.',403);
        return $u;
    }
    private function admin(int $actor): void
    {
        $u=$this->account($actor);
        if (($u['role']??'')!=='admin') throw new EmailCampaignException('Administrator permission is required.',403);
    }
    private function profile(int $user,bool $approved=false): array
    {
        $u=$this->account($user);
        $p=$this->one('SELECT * FROM email_sender_profiles WHERE user_id=?',[$user]);
        if (!$p || ($approved && ($p['state']!=='approved' || $p['daily_limit']<1 || $p['reply_to']!==EmailCampaignPolicy::email($u['email'])))) throw new EmailCampaignException('Your sender identity must be approved before preparing or sending campaigns.',403);
        return $p;
    }
    public function sender(int $user): ?array { $this->account($user); return $this->one('SELECT * FROM email_sender_profiles WHERE user_id=?',[$user]); }
    public function saveSender(int $user,array $input): array
    {
        $name=EmailCampaignPolicy::text($input['business_name']??'',2,100,'business name');
        $address=EmailCampaignPolicy::text($input['postal_address']??'',10,400,'business postal address',true);
        return $this->tx(function()use($user,$name,$address) {
            $u=$this->account($user,true); $p=$this->sender($user);
            if (($p['state']??'')==='suspended') throw new EmailCampaignException('Your email sender is suspended. Contact support for review.',403);
            $values=[$name,$address,EmailCampaignPolicy::email($u['email']),$this->now(),$user];
            if ($p) $this->run("UPDATE email_sender_profiles SET business_name=?,postal_address=?,reply_to=?,updated_at=?,state='pending',daily_limit=0,version=version+1,reviewed_by=NULL,approval_evidence=NULL WHERE user_id=?",$values);
            else $this->run("INSERT INTO email_sender_profiles(business_name,postal_address,reply_to,updated_at,user_id,state) VALUES(?,?,?,?,?,'pending')",$values);
            $this->audit($user,$user,null,'sender_requested');return $this->sender($user);
        });
    }
    public function reviewSender(int $actor,int $user,array $input): array
    {
        $this->admin($actor);
        if ($actor===$user) throw new EmailCampaignException('A different administrator must review your sender identity.',403);
        $decision=$input['decision']??'';
        if (!in_array($decision,['approved','rejected','suspended'],true)) throw new EmailCampaignException('Choose approve, reject or suspend.');
        $evidence=EmailCampaignPolicy::text($input['evidence']??'',10,1000,'identity and organization-authorization review reference',true);
        $limit=filter_var($input['daily_limit']??0,FILTER_VALIDATE_INT);
        if ($decision==='approved' && (!$limit || $limit<1 || $limit>(int)env('EMAIL_CAMPAIGN_DAILY_LIMIT',0))) throw new EmailCampaignException('Choose a daily limit within the configured provider allowance.');
        return $this->tx(function()use($actor,$user,$decision,$evidence,$limit,$input) {
            $u=$this->account($user,true);$p=$this->profile($user);
            if((int)($input['expected_version']??0)!==(int)$p['version'])throw new EmailCampaignException('Sender details changed. Refresh and review again.',409);
            if($decision==='approved'&&$p['reply_to']!==EmailCampaignPolicy::email($u['email']))throw new EmailCampaignException('The verified account email changed. Request a new sender review.',409);
            $this->run('UPDATE email_sender_profiles SET state=?,daily_limit=?,reviewed_by=?,approval_evidence=?,updated_at=?,version=version+1 WHERE user_id=?',[$decision,$decision==='approved'?$limit:0,$actor,$evidence,$this->now(),$user]);
            $this->audit($user,$actor,null,'sender_'.$decision,$evidence);return $this->sender($user);
        });
    }
    private function snapshot(array $p): array { return array_intersect_key($p,array_flip(['business_name','postal_address','reply_to','version'])); }
    private function campaign(string $id,?int $user=null,bool $lock=false): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new EmailCampaignException('Email campaign not found.',404);
        $row=$this->one('SELECT * FROM email_campaign_jobs WHERE id=?'.($user!==null?' AND user_id=?':'').($lock?$this->lock():''),$user!==null?[$id,$user]:[$id]);
        if (!$row) throw new EmailCampaignException('Email campaign not found.',404);
        return $row;
    }
    private function recipients(int $user,array $source): array
    {
        $raw=$source['manual']??[];
        if (!is_array($raw) || count($raw)>10000 || array_filter($raw,static fn($v)=>!is_string($v))) throw new EmailCampaignException('Supply a bounded list of email addresses.');
        foreach (['contact_ids','group_ids'] as $field) {
            $ids=$source[$field]??[];
            if (!is_array($ids) || count($ids)>1000) throw new EmailCampaignException('Too many contact selections.');
            foreach ($ids as $id) if (!preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id)) throw new EmailCampaignException('Invalid contact selection.');
            $ids=array_values(array_unique($ids)); if (!$ids) continue;
            $marks=implode(',',array_fill(0,count($ids),'?'));$table=$field==='group_ids'?'contact_groups':'contacts';
            $owned=$this->run("SELECT id FROM {$table} WHERE user_id=? AND id IN ({$marks})",[$user,...$ids])->fetchAll(PDO::FETCH_COLUMN);
            if (count($owned)!==count($ids)) throw new EmailCampaignException('Contact selection not found.',404);
            $sql=$field==='group_ids'?"SELECT c.email FROM contacts c JOIN group_contacts gc ON gc.contact_id=c.id JOIN contact_groups g ON g.id=gc.group_id WHERE c.user_id=? AND g.user_id=? AND g.id IN ({$marks}) LIMIT 10001":"SELECT email FROM contacts WHERE user_id=? AND id IN ({$marks}) LIMIT 10001";
            $rows=$this->run($sql,$field==='group_ids'?[$user,$user,...$ids]:[$user,...$ids])->fetchAll(PDO::FETCH_COLUMN);
            array_push($raw,...array_map(static fn($v)=>(string)$v,$rows));
        }
        if (count($raw)>10000) throw new EmailCampaignException('Split this audience into smaller campaigns.');
        $unique=[];$invalid=0;$duplicate=0;
        foreach ($raw as $email) { $e=EmailCampaignPolicy::email($email); if (!$e) {$invalid++;continue;} if(isset($unique[$e]))$duplicate++;else $unique[$e]=true; }
        $suppressed=[];
        foreach ($this->run('SELECT email FROM email_campaign_suppressions WHERE user_id=?',[$user])->fetchAll(PDO::FETCH_COLUMN) as $email) $suppressed[strtolower($email)]=true;
        // Respect previously recorded EMAIL opt-outs without treating SMS opt-outs as email consent.
        foreach ($this->run("SELECT recipient FROM opt_outs WHERE user_id=? AND channel='email'",[$user])->fetchAll(PDO::FETCH_COLUMN) as $email) if($e=EmailCampaignPolicy::email($email))$suppressed[$e]=true;
        $sendable=array_keys(array_diff_key($unique,$suppressed));sort($sendable,SORT_STRING);
        if (count($sendable)>min(10000,max(1,(int)env('EMAIL_CAMPAIGN_MAX_RECIPIENTS',1000)))) throw new EmailCampaignException('This campaign exceeds the configured recipient limit.');
        return ['entered'=>count($raw),'invalid'=>$invalid,'duplicates'=>$duplicate,'opted_out'=>count(array_intersect_key($unique,$suppressed)),'sendable'=>count($sendable),'recipients'=>$sendable];
    }
    public function quote(int $user,array $input): array
    {
        $this->account($user);EmailCampaignPolicy::content($input);
        $source=$input['recipients']??[];if(!is_array($source))throw new EmailCampaignException('Choose recipients.');
        $r=$this->recipients($user,$source);$unit=EmailCampaignPolicy::unitCents();
        $wallets=$this->run('SELECT * FROM wallets WHERE user_id=?',[$user])->fetchAll(PDO::FETCH_ASSOC);
        if(count($wallets)!==1)throw new EmailCampaignException('An unambiguous account wallet is required.',503);
        $wallet=$wallets[0];
        if (($wallet['currency']??'')!=='ZAR') throw new EmailCampaignException('A ZAR wallet is required.',403);
        $available=EmailCampaignPolicy::moneyUnits((string)$wallet['balance'])-EmailCampaignPolicy::moneyUnits((string)$wallet['reserved']);
        return $r+['emails_per_credit'=>5,'credits_required'=>EmailCampaignPolicy::credits($r['sendable']),'unit_amount'=>EmailCampaignPolicy::amount($unit),'amount'=>EmailCampaignPolicy::amount($unit*$r['sendable']),'available_balance'=>number_format($available/10000,4,'.',''),'sufficient_balance'=>$available>=$unit*$r['sendable']*100,'billing_basis'=>'SMTP acceptance; not inbox delivery'];
    }
    public function create(int $user,array $input): array
    {
        $key=EmailCampaignPolicy::text($input['idempotency_key']??'',16,80,'request key');
        $content=EmailCampaignPolicy::content($input);
        $evidence=EmailCampaignPolicy::text($input['consent_evidence']??'',20,1500,'recipient consent source and evidence reference',true);
        if (($input['consent_confirmed']??false)!==true) throw new EmailCampaignException('Confirm that every recipient explicitly consented to this sender and purpose.');
        // Fingerprint the request, not mutable contacts. Lost-response retries return the original snapshot.
        $fingerprint=hash('sha256',json_encode([$content,$input['recipients']??[],$evidence],JSON_THROW_ON_ERROR));
        return $this->tx(function()use($user,$input,$content,$key,$evidence,$fingerprint) {
            $this->account($user,true);
            $existing=$this->one('SELECT * FROM email_campaign_jobs WHERE user_id=? AND idempotency_key=?',[$user,$key]);
            if ($existing) {if(!hash_equals($existing['fingerprint'],$fingerprint))throw new EmailCampaignException('This request key was already used for different content.',409);return $this->detail($user,$existing['id']);}
            $sender=$this->snapshot($this->profile($user,true));
            if ((int)$this->run("SELECT COUNT(*) FROM email_campaign_jobs WHERE user_id=? AND state IN ('awaiting_review','approved','queued','held')",[$user])->fetchColumn()>=20) throw new EmailCampaignException('Finish or cancel existing email campaigns first.',429);
            if ((int)$this->run('SELECT COUNT(*) FROM email_campaign_jobs WHERE user_id=? AND created_at>=?',[$user,gmdate('Y-m-d 00:00:00')])->fetchColumn()>=50)throw new EmailCampaignException('The daily campaign review-request limit has been reached.',429);
            $quote=$this->quote($user,$input);
            if (!$quote['sendable']) throw new EmailCampaignException('No eligible email recipients remain.');
            $id=bin2hex(random_bytes(16));$now=$this->now();
            $this->run("INSERT INTO email_campaign_jobs(id,user_id,idempotency_key,fingerprint,name,subject,body,sender_json,consent_evidence,state,unit_cents,recipient_count,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,'awaiting_review',?,?,?,?)",[$id,$user,$key,$fingerprint,$content['name'],$content['subject'],$content['body'],json_encode($sender,JSON_THROW_ON_ERROR),$evidence,EmailCampaignPolicy::unitCents(),$quote['sendable'],$now,$now]);
            foreach ($quote['recipients'] as $email) { $rid=bin2hex(random_bytes(16));$this->run("INSERT INTO email_campaign_recipients(id,campaign_id,user_id,email,state,message_id,updated_at) VALUES(?,?,?,?,'pending',?,?)",[$rid,$id,$user,$email,'<'.$rid.'@email.ieosuia.com>',$now]); }
            $this->audit($user,$user,$id,'campaign_submitted','Consent evidence and immutable audience/content snapshot saved.');return $this->detail($user,$id);
        });
    }
    public function listing(int $user): array { $this->account($user); return $this->run('SELECT id,name,subject,state,recipient_count,charged_cents,reserved_cents,created_at FROM email_campaign_jobs WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 100',[$user])->fetchAll(PDO::FETCH_ASSOC); }
    public function detail(int $actor,string $id,bool $admin=false): array
    {
        if ($admin) $this->admin($actor); else $this->account($actor);
        $c=$this->campaign($id,$admin?null:$actor);unset($c['idempotency_key']);
        $c['sender']=json_decode($c['sender_json'],true);unset($c['sender_json']);
        $c['credits_required']=EmailCampaignPolicy::credits((int)$c['recipient_count']);
        $c['recipients']=$this->run('SELECT id,email,state,outcome_code,delivery_state,attempts,accepted_at,provider,provider_message_id FROM email_campaign_recipients WHERE campaign_id=? ORDER BY email LIMIT 10000',[$id])->fetchAll(PDO::FETCH_ASSOC);
        return $c;
    }
    public function adminOverview(int $actor): array
    {
        $this->admin($actor);
        return ['senders'=>$this->run("SELECT p.*,u.email AS account_email FROM email_sender_profiles p JOIN users u ON u.id=p.user_id ORDER BY CASE WHEN p.state='pending' THEN 0 WHEN p.state='suspended' THEN 1 ELSE 2 END,p.updated_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC),
            'campaigns'=>$this->run("SELECT id,user_id,name,subject,state,recipient_count,created_at FROM email_campaign_jobs ORDER BY CASE WHEN state='awaiting_review' THEN 0 WHEN state='held' THEN 1 ELSE 2 END,created_at DESC,id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function review(int $actor,string $id,array $input): array
    {
        $this->admin($actor);$decision=$input['decision']??'';
        if (!in_array($decision,['approved','rejected'],true)) throw new EmailCampaignException('Choose approve or reject.');
        $evidence=EmailCampaignPolicy::text($input['evidence']??'',20,1500,'content, links and recipient-consent review evidence',true);
        return $this->tx(function()use($actor,$id,$input,$decision,$evidence) {
            $c=$this->campaign($id,null,true);
            if ((int)$c['user_id']===$actor) throw new EmailCampaignException('A different administrator must review your campaign.',403);
            if ($c['state']!=='awaiting_review' || !hash_equals($c['fingerprint'],(string)($input['fingerprint']??''))) throw new EmailCampaignException('The campaign is no longer awaiting this review.',409);
            $p=$this->snapshot($this->profile((int)$c['user_id'],true));
            if (json_encode($p)!==$c['sender_json']) throw new EmailCampaignException('Sender details changed. Submit a new campaign.',409);
            $this->run('UPDATE email_campaign_jobs SET state=?,reviewed_by=?,review_evidence=?,updated_at=? WHERE id=?',[$decision,$actor,$evidence,$this->now(),$id]);
            $this->audit((int)$c['user_id'],$actor,$id,'campaign_'.$decision,$evidence);return $this->detail($actor,$id,true);
        });
    }
    private function wallet(array $campaign,string $key,string $operation,int $cents): void
    {
        if ($cents<=0) throw new EmailCampaignException('Invalid email accounting amount.',503);
        $wallets=$this->run('SELECT * FROM wallets WHERE user_id=?'.$this->lock(),[$campaign['user_id']])->fetchAll(PDO::FETCH_ASSOC);
        if(count($wallets)!==1)throw new EmailCampaignException('An unambiguous account wallet is required.',503);
        $w=$wallets[0];if ($w['currency']!=='ZAR') throw new EmailCampaignException('A ZAR wallet is required.',403);
        if ($this->one('SELECT id FROM wallet_ledger WHERE idempotency_key=?',[$key])) return;
        $amount=$cents*100;$balance=EmailCampaignPolicy::moneyUnits((string)$w['balance']);$reserved=EmailCampaignPolicy::moneyUnits((string)$w['reserved']);
        if ($operation==='reserve' && $balance-$reserved<$amount) throw new EmailCampaignException('Insufficient available SMS credits for these emails.',409);
        if ($operation!=='reserve' && ($reserved<$amount || (int)$campaign['reserved_cents']<$cents || ($operation==='debit' && $balance<$amount))) throw new EmailCampaignException('Email reservation needs reconciliation. No automatic resend is allowed.',503);
        $financialTime=date('Y-m-d H:i:s'); // Existing financial tables use APP_TIMEZONE, not the outbox's UTC clock.
        $this->run('INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,payment_id,operation,direction,amount,idempotency_key,metadata_json,created_at) VALUES(?,?,NULL,NULL,?,?,?,?,?,?)',[$campaign['user_id'],$w['id'],'email_'.$operation,$operation,EmailCampaignPolicy::amount($cents),$key,json_encode(['channel'=>'email','email_campaign_id'=>$campaign['id'],'emails_per_credit'=>5]),$financialTime]);
        $a=EmailCampaignPolicy::amount($cents);
        if ($operation==='reserve') $this->run('UPDATE wallets SET reserved=ROUND(reserved+?,4),updated_at=? WHERE id=?',[$a,$financialTime,$w['id']]);
        elseif ($operation==='debit') $this->run('UPDATE wallets SET reserved=ROUND(reserved-?,4),balance=ROUND(balance-?,4),updated_at=? WHERE id=?',[$a,$a,$financialTime,$w['id']]);
        else $this->run('UPDATE wallets SET reserved=ROUND(reserved-?,4),updated_at=? WHERE id=?',[$a,$financialTime,$w['id']]);
    }
    public function queue(int $user,string $id): array
    {
        EmailCampaignPolicy::requireReady();
        return $this->tx(function()use($user,$id) {
            $c=$this->campaign($id,$user,true);
            if (in_array($c['state'],['queued','held','completed'],true)) return $this->detail($user,$id);
            if ($c['state']!=='approved') throw new EmailCampaignException('Campaign approval is required before sending.',409);
            $p=$this->snapshot($this->profile($user,true));if(json_encode($p)!==$c['sender_json'])throw new EmailCampaignException('Sender details changed. Submit a new campaign.',409);
            // Price is immutable after review. A changed SMS price requires a fresh quote/review.
            if ((int)$c['unit_cents']!==EmailCampaignPolicy::unitCents()) throw new EmailCampaignException('Pricing changed. Submit a new campaign for review.',409);
            $amount=(int)$c['unit_cents']*(int)$c['recipient_count'];
            $this->wallet($c,'email-campaign:'.$id.':reserve','reserve',$amount);
            $this->run("UPDATE email_campaign_jobs SET state='queued',reserved_cents=?,updated_at=? WHERE id=?",[$amount,$this->now(),$id]);
            $this->run("UPDATE email_campaign_recipients SET state='queued',updated_at=? WHERE campaign_id=? AND state='pending'",[$this->now(),$id]);
            $this->audit($user,$user,$id,'campaign_queued');return $this->detail($user,$id);
        });
    }
    public function cancel(int $user,string $id): array
    {
        return $this->tx(function()use($user,$id) {
            $c=$this->campaign($id,$user,true);$release=0;
            if (in_array($c['state'],['completed','cancelled'],true)) return $this->detail($user,$id);
            foreach ($this->run("SELECT id,state FROM email_campaign_recipients WHERE campaign_id=? AND state IN ('queued','pending')",[$id])->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['state']==='queued') $release+=(int)$c['unit_cents'];
                $this->run("UPDATE email_campaign_recipients SET state='cancelled',outcome_code='CANCELLED',updated_at=? WHERE id=?",[$this->now(),$r['id']]);
            }
            if ($release) $this->wallet($c,'email-campaign:'.$id.':cancel','release',$release);
            $this->run('UPDATE email_campaign_jobs SET cancel_requested=1,reserved_cents=reserved_cents-?,updated_at=? WHERE id=?',[$release,$this->now(),$id]);
            $this->refresh($id);$this->audit($user,$user,$id,'campaign_cancelled');return $this->detail($user,$id);
        });
    }
    private function refresh(string $id): void
    {
        $c=$this->campaign($id);$states=$this->run('SELECT DISTINCT state FROM email_campaign_recipients WHERE campaign_id=?',[$id])->fetchAll(PDO::FETCH_COLUMN);
        if (array_intersect($states,['held'])) $state='held';
        elseif (array_intersect($states,['submitting','accepted_pending','queued'])) $state='queued';
        elseif (array_intersect($states,['pending'])) return;
        else $state=$c['cancel_requested']?'cancelled':'completed';
        $this->run('UPDATE email_campaign_jobs SET state=?,updated_at=? WHERE id=?',[$state,$this->now(),$id]);
    }
    private function suppressed(int $user,string $email): bool
    {
        return (bool)$this->one('SELECT 1 FROM email_campaign_suppressions WHERE user_id=? AND email=?',[$user,$email])
            || (bool)$this->one("SELECT 1 FROM opt_outs WHERE user_id=? AND channel='email' AND LOWER(TRIM(recipient))=?",[$user,$email]);
    }
    private function budget(array $p): bool
    {
        if(env('EMAIL_CAMPAIGN_PROVIDER','smtp')==='ses'){
            require_once __DIR__.'/EmailSesQuota.php';if(!EmailSesQuota::allowed($this->pdo))return false;
        }
        // The database advisory lock covers the counts AND the next durable started_at checkpoint.
        // Rolling windows prevent a double-sized burst across an hour or midnight boundary.
        $hour=gmdate('Y-m-d H:i:s',time()-3600);$day=gmdate('Y-m-d H:i:s',time()-86400);
        return (int)$this->run('SELECT COUNT(*) FROM email_campaign_recipients WHERE started_at>=?',[$hour])->fetchColumn()<(int)env('EMAIL_CAMPAIGN_HOURLY_LIMIT',0)
            && (int)$this->run('SELECT COUNT(*) FROM email_campaign_recipients WHERE started_at>=?',[$day])->fetchColumn()<(int)env('EMAIL_CAMPAIGN_DAILY_LIMIT',0)
            && (int)$this->run('SELECT COUNT(*) FROM email_campaign_recipients WHERE user_id=? AND started_at>=?',[$p['user_id'],$day])->fetchColumn()<(int)$p['daily_limit'];
    }
    /** Called only by the CLI worker, under its global database advisory lock. */
    public function claim(): ?array
    {
        EmailCampaignPolicy::requireReady();
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' && !(bool)$this->run("SELECT IS_USED_LOCK('ieosuia-email-campaign-worker-v1')=CONNECTION_ID()")->fetchColumn())throw new EmailCampaignException('The email worker lock is required.',503);
        return $this->tx(function() {
            // Do not let an account at its quota starve every other account.
            $ids=$this->run("SELECT id FROM email_campaign_jobs WHERE state IN ('queued','held') AND cancel_requested=0 ORDER BY created_at,id LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $c=$this->campaign($id,null,true);
                try { $p=$this->profile((int)$c['user_id'],true); } catch(EmailCampaignException) {continue;}
                if (json_encode($this->snapshot($p))!==$c['sender_json']) continue;
                $r=$this->one("SELECT * FROM email_campaign_recipients WHERE campaign_id=? AND state='queued' ORDER BY id LIMIT 1".$this->lock(),[$id]);
                if (!$r) continue;
                if ($this->suppressed((int)$c['user_id'],$r['email'])) {
                    $this->wallet($c,'email-message:'.$r['id'].':release','release',(int)$c['unit_cents']);
                    $this->run('UPDATE email_campaign_jobs SET reserved_cents=reserved_cents-? WHERE id=?',[$c['unit_cents'],$id]);
                    $this->run("UPDATE email_campaign_recipients SET state='suppressed',outcome_code='UNSUBSCRIBED',updated_at=? WHERE id=?",[$this->now(),$r['id']]);$this->refresh($id);continue;
                }
                if (!$this->budget($p)) continue;
                if(env('EMAIL_CAMPAIGN_PROVIDER','smtp')==='ses')EmailSesQuota::reserveSlot($this->pdo);
                $token=bin2hex(random_bytes(32));
                $this->run("UPDATE email_campaign_recipients SET state='submitting',attempts=1,started_at=?,updated_at=?,unsubscribe_hash=? WHERE id=? AND state='queued'",[$this->now(),$this->now(),hash('sha256',$token),$r['id']]);
                $this->audit((int)$c['user_id'],null,$id,'submission_started',$r['id']);
                return ['campaign'=>$c,'recipient'=>$r,'sender'=>$this->snapshot($p),'unsubscribe_token'=>$token];
            }
            return null;
        });
    }
    public function recordOutcome(string $recipient,string $outcome): void
    {
        if (!in_array($outcome,['accepted','rejected','uncertain'],true)) throw new InvalidArgumentException('Invalid mail outcome');
        $r=$this->one('SELECT campaign_id FROM email_campaign_recipients WHERE id=?',[$recipient]);if(!$r)return;
        $this->tx(function()use($recipient,$outcome,$r) {
            $c=$this->campaign($r['campaign_id'],null,true);
            $m=$this->one('SELECT * FROM email_campaign_recipients WHERE id=?'.$this->lock(),[$recipient]);
            if ($m['state']!=='submitting') return;
            $state=['accepted'=>'accepted_pending','rejected'=>'rejected','uncertain'=>'held'][$outcome];
            if ($outcome==='rejected') {
                $this->wallet($c,'email-message:'.$recipient.':release','release',(int)$c['unit_cents']);
                $this->run('UPDATE email_campaign_jobs SET reserved_cents=reserved_cents-? WHERE id=?',[$c['unit_cents'],$c['id']]);
            }
            $this->run('UPDATE email_campaign_recipients SET state=?,outcome_code=?,accepted_at=?,updated_at=? WHERE id=?',[$state,strtoupper($outcome),$outcome==='accepted'?$this->now():null,$this->now(),$recipient]);
            $this->audit((int)$c['user_id'],null,$c['id'],'smtp_'.$outcome,$recipient);$this->refresh($c['id']);
        });
    }
    /** Durable acceptance is settled separately, so local accounting retries never send mail. */
    public function settleAccepted(): int
    {
        $rows=$this->run("SELECT id,campaign_id FROM email_campaign_recipients WHERE state='accepted_pending' LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);$count=0;
        foreach ($rows as $r) $count+=$this->tx(function()use($r) {
            $c=$this->campaign($r['campaign_id'],null,true);$m=$this->one('SELECT state FROM email_campaign_recipients WHERE id=?'.$this->lock(),[$r['id']]);
            if ($m['state']!=='accepted_pending') return 0;
            $this->wallet($c,'email-message:'.$r['id'].':settle','debit',(int)$c['unit_cents']);
            $this->run("UPDATE email_campaign_recipients SET state='accepted',updated_at=? WHERE id=?",[$this->now(),$r['id']]);
            $this->run('UPDATE email_campaign_jobs SET reserved_cents=reserved_cents-?,charged_cents=charged_cents+? WHERE id=?',[$c['unit_cents'],$c['unit_cents'],$c['id']]);
            $this->audit((int)$c['user_id'],null,$c['id'],'credit_settled',$r['id']);$this->refresh($c['id']);return 1;
        });
        return $count;
    }
    public function holdStale(): int
    {
        $rows=$this->run("SELECT id FROM email_campaign_recipients WHERE state='submitting' AND started_at<?",[gmdate('Y-m-d H:i:s',time()-300)])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $id) $this->recordOutcome($id,'uncertain');
        return count($rows);
    }
    public function unsubscribe(string $id,string $token,bool $apply=false,bool $abuse=false): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id) || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new EmailCampaignException('Invalid email preference link.',404);
        $r=$this->one('SELECT * FROM email_campaign_recipients WHERE id=?',[$id]);
        if (!$r || !$r['unsubscribe_hash'] || !hash_equals($r['unsubscribe_hash'],hash('sha256',$token))) throw new EmailCampaignException('Invalid email preference link.',404);
        if (!$apply)return;
        $this->tx(function()use($r,$abuse) {
            // Same account-row mutex as sender changes; suppression never opts someone back in.
            $this->run('SELECT id FROM users WHERE id=?'.$this->lock(),[$r['user_id']]);
            $old=$this->one('SELECT reason FROM email_campaign_suppressions WHERE user_id=? AND email=?',[$r['user_id'],$r['email']]);
            if($old && (!$abuse || $old['reason']==='complaint'))return;
            if(!$old)$this->run('INSERT INTO email_campaign_suppressions(user_id,email,reason,created_at) VALUES(?,?,?,?)',[$r['user_id'],$r['email'],$abuse?'complaint':'unsubscribe',$this->now()]);
            if($abuse) {
                $this->run("UPDATE email_campaign_suppressions SET reason='complaint' WHERE user_id=? AND email=?",[$r['user_id'],$r['email']]);
                $this->run("UPDATE email_sender_profiles SET state='suspended',daily_limit=0,updated_at=? WHERE user_id=?",[$this->now(),$r['user_id']]);
            }
            $this->audit((int)$r['user_id'],null,$r['campaign_id'],$abuse?'recipient_complaint':'recipient_unsubscribed',$r['id']);
        });
    }
    /** Exact-message, authenticated delivery evidence. This never calls SMTP or credits a wallet. */
    public function feedback(array $event,string $payloadHash): array
    {
        $eventId=EmailCampaignPolicy::text($event['event_id']??'',8,100,'provider event reference');
        $rid=(string)($event['recipient_id']??'');$type=$event['event']??'';
        if (!preg_match('/^[a-f0-9]{32}$/D',$rid) || !in_array($type,['accepted','delivered','soft_bounce','provider_rejected','hard_bounce','complaint'],true)) throw new EmailCampaignException('Invalid provider event.');
        $r=$this->one('SELECT * FROM email_campaign_recipients WHERE id=?',[$rid]);
        if (!$r || !hash_equals($r['message_id'],(string)($event['message_id']??''))) throw new EmailCampaignException('Provider message reference not found.',404);
        return $this->tx(function()use($eventId,$type,$rid,$r,$payloadHash,$event) {
            $c=$this->campaign($r['campaign_id'],null,true);
            $m=$this->one('SELECT * FROM email_campaign_recipients WHERE id=?'.$this->lock(),[$rid]);
            $old=$this->one('SELECT * FROM email_campaign_events WHERE event_id=?',[$eventId]);
            if($old) {if(!hash_equals($old['payload_sha256'],$payloadHash))throw new EmailCampaignException('Provider event reference reused with different content.',409);return ['duplicate'=>true];}
            if((int)$m['attempts']!==1 || !$m['started_at'] || in_array($m['state'],['pending','queued','cancelled','suppressed','rejected'],true))throw new EmailCampaignException('Event does not match a submitted email. Reconciliation is required.',409);
            $this->run('INSERT INTO email_campaign_events(event_id,recipient_id,payload_sha256,event,created_at) VALUES(?,?,?,?,?)',[$eventId,$rid,$payloadHash,$type,$this->now()]);
            if(isset($event['provider_message_id'])){
                $provider=$event['provider']??'';$reference=$event['provider_message_id'];
                if(!is_string($provider)||!preg_match('/^ses:[a-z0-9-]+:[0-9]{12}$/D',$provider)||!is_string($reference)||!preg_match('/^[A-Za-z0-9._-]{1,200}$/D',$reference))throw new EmailCampaignException('Invalid provider reference.');
                if(($m['provider_message_id']!==null&&($m['provider_message_id']!==$reference||$m['provider']!==$provider))||$this->one('SELECT id FROM email_campaign_recipients WHERE provider=? AND provider_message_id=? AND id<>?',[$provider,$reference,$rid]))throw new EmailCampaignException('Provider reference requires reconciliation.',409);
                $this->run('UPDATE email_campaign_recipients SET provider=?,provider_message_id=? WHERE id=?',[$provider,$reference,$rid]);
            }
            $rank=['unknown'=>0,'accepted'=>0,'delivered'=>1,'soft_bounce'=>2,'provider_rejected'=>3,'hard_bounce'=>4,'complaint'=>5];
            if ($type!=='accepted'&&$rank[$type]>($rank[$m['delivery_state']]??0)) $this->run('UPDATE email_campaign_recipients SET delivery_state=?,updated_at=? WHERE id=?',[$type,$this->now(),$rid]);
            if($type==='provider_rejected'&&$rank[$type]>($rank[$m['delivery_state']]??0)){
                $this->run('SELECT id FROM users WHERE id=?'.$this->lock(),[$m['user_id']]);
                $this->run("UPDATE email_sender_profiles SET state='suspended',daily_limit=0,updated_at=? WHERE user_id=?",[$this->now(),$m['user_id']]);
            }
            // A verified event with the exact saved Message-ID can resolve an uncertain submission without resending.
            if(in_array($m['state'],['held','submitting'],true))$this->run("UPDATE email_campaign_recipients SET state='accepted_pending',outcome_code='SIGNED_PROVIDER_EVIDENCE',accepted_at=?,updated_at=? WHERE id=?",[$this->now(),$this->now(),$rid]);
            if(in_array($type,['hard_bounce','complaint'],true)) {
                $this->run('SELECT id FROM users WHERE id=?'.$this->lock(),[$m['user_id']]);
                if(!$this->one('SELECT 1 FROM email_campaign_suppressions WHERE user_id=? AND email=?',[$m['user_id'],$m['email']]))$this->run('INSERT INTO email_campaign_suppressions(user_id,email,reason,created_at) VALUES(?,?,?,?)',[$m['user_id'],$m['email'],$type,$this->now()]);
                if($type==='complaint')$this->run("UPDATE email_campaign_suppressions SET reason='complaint' WHERE user_id=? AND email=?",[$m['user_id'],$m['email']]);
                if($type==='complaint'&&$m['delivery_state']!=='complaint')$this->run("UPDATE email_sender_profiles SET state='suspended',daily_limit=0,updated_at=? WHERE user_id=?",[$this->now(),$m['user_id']]);
            }
            $this->audit((int)$c['user_id'],null,$c['id'],'provider_'.$type,$rid.':'.$eventId);$this->refresh($c['id']);return ['duplicate'=>false];
        });
    }
}
