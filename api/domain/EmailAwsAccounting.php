<?php
declare(strict_types=1);
require_once __DIR__.'/EmailAwsPolicy.php';
/** Portal-only accounting authority. No recipient content, transport, SMTP or campaign processing. */
final class EmailAwsAccounting {
    public function __construct(private PDO $pdo){}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->pdo->prepare($sql);$q->execute($args);return$q;}
    private function lock():string{return$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    private function tx(callable $f):mixed{$this->pdo->beginTransaction();try{$v=$f();$this->pdo->commit();return$v;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}}
    private static function id(mixed $v,int $n=32):string{if(!is_string($v)||!preg_match('/^[a-f0-9]{'.$n.'}$/D',$v))throw new EmailAwsException('Invalid accounting reference');return$v;}
    private function wallet(int $user,bool $requireActive=true):array {
        $u=$this->query('SELECT id,email,is_active,email_verified_at FROM users WHERE id=?'.$this->lock(),[$user])->fetch(PDO::FETCH_ASSOC);
        if(!$u||($requireActive&&(!(bool)$u['is_active']||empty($u['email_verified_at']))))throw new EmailAwsException('Verified active account required',403);
        $rows=$this->query('SELECT * FROM wallets WHERE user_id=?'.$this->lock(),[$user])->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1||$rows[0]['currency']!=='ZAR')throw new EmailAwsException('An unambiguous ZAR wallet is required',503);
        return$rows[0]+['account_email'=>strtolower(trim($u['email']))];
    }
    public function price(int $user):array {
        $w=$this->wallet($user);$available=EmailAwsPolicy::moneyUnits((string)$w['balance'])-EmailAwsPolicy::moneyUnits((string)$w['reserved']);
        return['unit_cents'=>EmailAwsPolicy::unitCents(),'available_cents'=>max(0,intdiv($available,100))];
    }
    private function campaign(int $user,string $id):array {
        $r=$this->query('SELECT * FROM email_aws_reservations WHERE id=? AND user_id=?'.$this->lock(),[$id,$user])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new EmailAwsException('Reservation not found',404);return$r;
    }
    public function proof(int $user,string $id):array {
        $c=$this->campaign($user,self::id($id));
        if((bool)$c['cancelled'])throw new EmailAwsException('Reservation is cancelled',409);
        return['id'=>$id,'user_id'=>$user,'fingerprint'=>$c['fingerprint'],'unit_cents'=>(int)$c['unit_cents'],'recipient_count'=>(int)$c['recipient_count']];
    }
    private function ledger(array $w,array $c,string $operation,int $cents,string $key):void {
        if($cents<1)throw new EmailAwsException('Invalid accounting amount',503);
        $balance=EmailAwsPolicy::moneyUnits((string)$w['balance']);$reserved=EmailAwsPolicy::moneyUnits((string)$w['reserved']);$units=$cents*100;
        if($operation==='reserve'&&$balance-$reserved<$units)throw new EmailAwsException('Insufficient available credits',409);
        if($operation!=='reserve'&&($reserved<$units||(int)$c['reserved_cents']<$cents||($operation==='debit'&&$balance<$units)))throw new EmailAwsException('Reservation requires reconciliation',503);
        $amount=EmailAwsPolicy::amount($cents);$time=date('Y-m-d H:i:s');
        $this->query('INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,payment_id,operation,direction,amount,idempotency_key,metadata_json,created_at)VALUES(?,?,NULL,NULL,?,?,?,?,?,?)',[$c['user_id'],$w['id'],'email_'.$operation,$operation,$amount,$key,json_encode(['channel'=>'email','email_campaign_id'=>$c['id'],'emails_per_credit'=>5,'backend'=>'aws'],JSON_THROW_ON_ERROR),$time]);
        if($operation==='reserve')$this->query('UPDATE wallets SET reserved=reserved+?,updated_at=? WHERE id=?',[$amount,$time,$w['id']]);
        elseif($operation==='debit')$this->query('UPDATE wallets SET reserved=reserved-?,balance=balance-?,updated_at=? WHERE id=?',[$amount,$amount,$time,$w['id']]);
        else $this->query('UPDATE wallets SET reserved=reserved-?,updated_at=? WHERE id=?',[$amount,$time,$w['id']]);
    }
    public function reserve(int $user,array $campaign):array {
        $id=self::id($campaign['id']??null);$hash=self::id($campaign['fingerprint']??null,64);$recipients=$campaign['recipients']??null;
        if(($campaign['state']??'')!=='approved'||(int)($campaign['user_id']??0)!==$user||!is_array($recipients)||count($recipients)<1||count($recipients)>1000||count($recipients)!==(int)($campaign['recipient_count']??0))throw new EmailAwsException('Approved bounded campaign required',409);
        $unit=EmailAwsPolicy::unitCents();if(($campaign['unit_cents']??null)!==$unit)throw new EmailAwsException('Pricing changed; review again',409);
        $replyTo=EmailAwsPolicy::email((string)($campaign['sender']['reply_to']??''));if(!$replyTo)throw new EmailAwsException('Verified Reply-To required');
        $accountEmail=EmailAwsPolicy::email((string)($campaign['sender']['account_email']??$replyTo));if(!$accountEmail)throw new EmailAwsException('Verified account binding required');
        if($replyTo!==$accountEmail&&((int)($campaign['sender']['user_id']??0)!==$user||!preg_match('/^[a-f0-9]{32}$/D',(string)($campaign['sender']['reply_to_verification']??''))||!is_int($campaign['sender']['reply_to_verified_at']??null)||$campaign['sender']['reply_to_verified_at']<=0))throw new EmailAwsException('Verified alternate reply address required',409);
        $attempts=[];foreach($recipients as$r){$rid=self::id($r['id']??null);$address=EmailAwsPolicy::email((string)($r['email']??''));if(!$address||isset($attempts[$rid]))throw new EmailAwsException('Invalid campaign audience');$attempts[$rid]=hash('sha256',$address);}
        return$this->tx(function()use($user,$id,$hash,$unit,$attempts,$replyTo,$accountEmail){
            $w=$this->wallet($user);if($w['account_email']!==$accountEmail)throw new EmailAwsException('Verified account email changed; review again',409);$existing=$this->query('SELECT * FROM email_aws_reservations WHERE id=?'.$this->lock(),[$id])->fetch(PDO::FETCH_ASSOC);
            if($existing){if((int)$existing['user_id']!==$user||!hash_equals($existing['fingerprint'],$hash)||(int)$existing['unit_cents']!==$unit||(int)$existing['recipient_count']!==count($attempts)||!hash_equals($existing['reply_to_sha256'],hash('sha256',$replyTo))||!hash_equals($existing['account_email_sha256']??$existing['reply_to_sha256'],hash('sha256',$accountEmail)))throw new EmailAwsException('Reservation payload conflict',409);
                $saved=$this->query('SELECT id,email_sha256 FROM email_aws_attempts WHERE campaign_id=?'.$this->lock(),[$id])->fetchAll(PDO::FETCH_KEY_PAIR);ksort($saved);$check=$attempts;ksort($check);if($saved!==$check)throw new EmailAwsException('Reservation audience conflict',409);
            }else{
                $c=['id'=>$id,'user_id'=>$user,'reserved_cents'=>$unit*count($attempts)];
                $this->query('INSERT INTO email_aws_reservations(id,user_id,fingerprint,reply_to_sha256,account_email_sha256,unit_cents,recipient_count,reserved_cents,created_at)VALUES(?,?,?,?,?,?,?,?,?)',[$id,$user,$hash,hash('sha256',$replyTo),hash('sha256',$accountEmail),$unit,count($attempts),$c['reserved_cents'],gmdate('Y-m-d H:i:s')]);
                foreach($attempts as$rid=>$addressHash)$this->query("INSERT INTO email_aws_attempts(id,campaign_id,email_sha256,state)VALUES(?,?,?,'pending')",[$rid,$id,$addressHash]);
                $this->ledger($w,$c,'reserve',$c['reserved_cents'],'email-aws:'.$id.':reserve');
            }
            return['id'=>$id,'user_id'=>$user,'fingerprint'=>$hash,'unit_cents'=>$unit,'recipient_count'=>count($attempts)];
        });
    }
    public function cancel(int $user,string $id):array {
        self::id($id);return$this->tx(function()use($user,$id){$w=$this->wallet($user,false);$c=$this->campaign($user,$id);
            $count=(int)$this->query("SELECT COUNT(*) FROM email_aws_attempts WHERE campaign_id=? AND state='pending'",[$id])->fetchColumn();$release=$count*(int)$c['unit_cents'];
            if($release){$this->ledger($w,$c,'release',$release,'email-aws:'.$id.':cancel');$this->query("UPDATE email_aws_attempts SET state='released' WHERE campaign_id=? AND state='pending'",[$id]);}
            $this->query('UPDATE email_aws_reservations SET cancelled=1,reserved_cents=reserved_cents-? WHERE id=?',[$release,$id]);
            return['cancelled'=>true,'released_cents'=>$release,'reserved_cents'=>(int)$c['reserved_cents']-$release];
        });
    }
    public function command(string $action,array $input):array {
        if(!in_array($action,['attempt','settle','status','release'],true))throw new EmailAwsException('Unknown accounting command',404);
        $id=self::id($input['id']??null);$hash=self::id($input['fingerprint']??null,64);$user=$input['user_id']??null;
        if(!is_int($user)||$user<1)throw new EmailAwsException('Invalid account reference');
        return$this->tx(function()use($action,$input,$id,$hash,$user){$w=$this->wallet($user,$action==='attempt');$c=$this->campaign($user,$id);
            if(!hash_equals($c['fingerprint'],$hash))throw new EmailAwsException('Reservation binding mismatch',409);
            if($action==='status')return['reserved_cents'=>(int)$c['reserved_cents'],'charged_cents'=>(int)$c['charged_cents'],'cancelled'=>(bool)$c['cancelled']];
            $rid=self::id($input['recipient_id']??null);$r=$this->query('SELECT * FROM email_aws_attempts WHERE id=? AND campaign_id=?'.$this->lock(),[$rid,$id])->fetch(PDO::FETCH_ASSOC);if(!$r)throw new EmailAwsException('Attempt not found',404);
            if($action==='release'){
                if($r['state']==='released')return['released'=>true,'duplicate'=>true];
                if($r['state']!=='pending')return['released'=>false];
                $this->ledger($w,$c,'release',(int)$c['unit_cents'],'email-aws:'.$rid.':unattempted');
                $this->query("UPDATE email_aws_attempts SET state='released' WHERE id=?",[$rid]);
                $this->query('UPDATE email_aws_reservations SET reserved_cents=reserved_cents-? WHERE id=?',[$c['unit_cents'],$id]);
                return['released'=>true,'duplicate'=>false];
            }
            if($action==='attempt'){
                if(!hash_equals($c['account_email_sha256']??$c['reply_to_sha256'],hash('sha256',$w['account_email'])))return['allowed'=>false];
                $address=EmailAwsPolicy::email((string)($input['email']??''));if(!$address||!hash_equals($r['email_sha256'],hash('sha256',$address)))throw new EmailAwsException('Recipient binding mismatch',409);
                if($r['state']==='issued')return['allowed'=>true,'existing'=>true];
                if($r['state']!=='pending'||(bool)$c['cancelled'])return['allowed'=>false];
                $suppressed=$this->query("SELECT 1 FROM opt_outs WHERE user_id=? AND channel='email' AND LOWER(TRIM(recipient))=? LIMIT 1",[$user,$address])->fetchColumn();
                if($suppressed){$this->ledger($w,$c,'release',(int)$c['unit_cents'],'email-aws:'.$rid.':suppressed');$this->query("UPDATE email_aws_attempts SET state='released' WHERE id=?",[$rid]);$this->query('UPDATE email_aws_reservations SET reserved_cents=reserved_cents-? WHERE id=?',[$c['unit_cents'],$id]);return['allowed'=>false];}
                $this->query("UPDATE email_aws_attempts SET state='issued',issued_at=? WHERE id=?",[gmdate('Y-m-d H:i:s'),$rid]);return['allowed'=>true,'existing'=>false];
            }
            $provider=$input['provider_id']??null;if(!is_string($provider)||!preg_match('/^[A-Za-z0-9_-]{8,200}$/D',$provider))throw new EmailAwsException('Invalid provider evidence');
            if($r['state']==='settled'){if(!hash_equals((string)$r['provider_id'],$provider))throw new EmailAwsException('Provider evidence conflict',409);return['settled'=>true,'duplicate'=>true];}
            if($r['state']!=='issued')throw new EmailAwsException('No issued attempt permits settlement',409);
            $this->ledger($w,$c,'debit',(int)$c['unit_cents'],'email-aws:'.$rid.':settle');
            $this->query("UPDATE email_aws_attempts SET state='settled',provider_id=?,settled_at=? WHERE id=?",[$provider,gmdate('Y-m-d H:i:s'),$rid]);
            $this->query('UPDATE email_aws_reservations SET reserved_cents=reserved_cents-?,charged_cents=charged_cents+? WHERE id=?',[$c['unit_cents'],$c['unit_cents'],$id]);
            return['settled'=>true,'duplicate'=>false];
        });
    }
}
