<?php
final class SmsLoginSecurityException extends RuntimeException {
 public function __construct(string $message,public readonly int $status=422){parent::__construct($message,$status);}
}
/** Optional SMS-Portal-only email step-up. No Central Auth credentials or settings. */
final class SmsLoginSecurity
{
 public function __construct(private PDO $pdo,private ?Closure $clock=null){}
 private function now():int{return $this->clock?($this->clock)():time();}
 private function run(string $sql,array $args=[]):PDOStatement{$q=$this->pdo->prepare($sql);$q->execute($args);return $q;}
 private function lock():string{return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
 private function secret():string{$key=(string)env('SMS_LOGIN_CODE_SECRET','');if(strlen($key)<32)throw new SmsLoginSecurityException('Extra sign-in verification is not configured yet.',503);return $key;}
 public function available():bool
 {
  $sql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('sms_login_security','sms_login_challenges','portal_security_events')":"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('sms_login_security','sms_login_challenges','portal_security_events')";
  return (int)$this->pdo->query($sql)->fetchColumn()===3;
 }
 public function state(int $user):?array
 {
  try{return $this->run('SELECT * FROM sms_login_security WHERE user_id=?',[$user])->fetch(PDO::FETCH_ASSOC)?:null;}
  catch(PDOException $e){
   $missing=($e->errorInfo[0]??'')==='42S02'||($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'&&str_contains($e->getMessage(),'no such table: sms_login_security'));
   if($missing&&env('SMS_LOGIN_SECURITY_REQUIRED','false')!=='true')return null;
   throw $e; // Database errors are never an MFA bypass.
  }
 }
 public function status(int $user):array
 {
  $ready=$this->available()&&strlen((string)env('SMS_LOGIN_CODE_SECRET',''))>=32;$s=$this->state($user);
  $hashes=json_decode($s['recovery_hashes']??'[]',true)?:[];
  return ['available'=>$ready,'enrollment_enabled'=>$ready&&$this->enrollmentAllowed($user),'enabled'=>(bool)($s['enabled']??false),'recovery_codes_remaining'=>count($hashes),'scope'=>'sms','method'=>'email_code'];
 }
 private function enrollmentAllowed(int $user):bool
 {
  if(env('SMS_LOGIN_ENROLLMENT_ENABLED','false')!=='true')return false;
  $pilot=trim((string)env('SMS_LOGIN_ENROLLMENT_PILOT_USERS',''));
  return $pilot===''||in_array((string)$user,array_map('trim',explode(',',$pilot)),true);
 }
 public function assertSession(array $user,array $claims):void
 {
  $state=$this->state((int)$user['id']);
  if(!empty($state['enabled'])&&((int)($claims['sms_two_step_version']??-1)!==(int)$state['version']))throw new SmsLoginSecurityException('Complete the SMS Portal sign-in check.',401);
 }
 private function validUser(array $u):void
 {
  if(empty($u['is_active'])||empty($u['email_verified_at'])||!filter_var($u['email']??'',FILTER_VALIDATE_EMAIL))throw new SmsLoginSecurityException('A verified active account is required.',403);
 }
 private function event(int $user,string $type):void
 {
  $this->run('INSERT INTO portal_security_events(event_key,user_id,event_type,created_at) VALUES(?,?,?,?)',[bin2hex(random_bytes(24)),$user,$type,date('Y-m-d H:i:s',$this->now())]);
 }
 public function request(array $user,string $purpose,string $binding,callable $sender,array $centralClaims=[]):array
 {
  if(!$this->available())throw new SmsLoginSecurityException('Extra sign-in verification is not installed yet.',503);
  $key=$this->secret();$this->validUser($user);$id=(int)$user['id'];$now=$this->now();
  if(!in_array($purpose,['enable','disable','login'],true)||strlen($binding)<32)throw new SmsLoginSecurityException('Invalid verification request.');
  if($purpose==='enable'&&!$this->enrollmentAllowed($id))throw new SmsLoginSecurityException('Enrollment is not available yet.',503);
  $this->pdo->beginTransaction();
  try{
   // Serialize challenges, settings and failures per user, not per random challenge ID.
   $this->run('SELECT id FROM users WHERE id=?'.$this->lock(),[$id]);
   $s=$this->run('SELECT * FROM sms_login_security WHERE user_id=?'.$this->lock(),[$id])->fetch(PDO::FETCH_ASSOC);
   if(!$s){$this->run('INSERT INTO sms_login_security(user_id,updated_at) VALUES(?,?)',[$id,date('Y-m-d H:i:s',$now)]);$s=$this->state($id);}
   if(($purpose==='enable'&&!empty($s['enabled']))||($purpose!=='enable'&&empty($s['enabled'])))throw new SmsLoginSecurityException('Security settings changed. Refresh and try again.',409);
   if((int)$s['blocked_until']>$now)throw new SmsLoginSecurityException('Too many attempts. Try again in 15 minutes.',429);
   $window=(int)$s['window_started'];$count=$now-$window<900?(int)$s['send_count']:0;
   if($now-(int)$s['requested_at']<60||$count>=5)throw new SmsLoginSecurityException('Please wait before requesting another code.',429);
   $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$code=(string)random_int(100000,999999);
   $claims=array_intersect_key($centralClaims,array_flip(['central_admin_version','central_admin_managed']));
   $this->run('UPDATE sms_login_security SET requested_at=?,window_started=?,send_count=? WHERE user_id=?',[$now,$count?$window:$now,$count+1,$id]);
   $this->run('UPDATE sms_login_challenges SET consumed=1 WHERE user_id=? AND purpose=? AND consumed=0',[$id,$purpose]);
   $this->run('INSERT INTO sms_login_challenges(token_hash,user_id,purpose,binding_hash,email_hash,auth_version,security_version,code_hash,claims_json,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$hash,$id,$purpose,hash('sha256',$binding),hash('sha256',strtolower($user['email'])),(int)($user['auth_version']??1),(int)$s['version'],hash_hmac('sha256','sms|'.$hash.'|'.$code,$key),json_encode($claims,JSON_THROW_ON_ERROR),$now+600,date('Y-m-d H:i:s',$now)]);
   $this->pdo->commit();
  }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
  // Never hold a database lock while contacting SMTP, and never return the code.
  try{$sent=$sender($user['email'],$code,$purpose)===true;}catch(Throwable){$sent=false;}
  if(!$sent)throw new SmsLoginSecurityException('The verification email could not be confirmed. Wait a minute, then request a new code.',503);
  $this->run('UPDATE sms_login_challenges SET active=1 WHERE token_hash=? AND consumed=0',[$hash]);
  return ['challenge'=>$token,'expires_in'=>600,'resend_after'=>60,'scope'=>'sms'];
 }
 public function verify(string $token,#[SensitiveParameter]string $code,string $purpose,string $binding,?int $actor=null):array
 {
  $key=$this->secret();$now=$this->now();
  if(!preg_match('/^[a-f0-9]{64}$/D',$token)||strlen($code)>80||!in_array($purpose,['login','enable','disable'],true))throw new SmsLoginSecurityException('The code or verification request is invalid.');
  $hash=hash('sha256',$token);$hint=$this->run('SELECT user_id FROM sms_login_challenges WHERE token_hash=?',[$hash])->fetchColumn();
  if(!$hint)throw new SmsLoginSecurityException('The code or verification request is invalid.');
  if($purpose==='enable'&&!$this->enrollmentAllowed((int)$hint))throw new SmsLoginSecurityException('Enrollment is not available yet.',503);
  $this->pdo->beginTransaction();$error=null;$recovery=[];
  try{
   $u=$this->run('SELECT * FROM users WHERE id=?'.$this->lock(),[$hint])->fetch(PDO::FETCH_ASSOC);$this->validUser($u?:[]);
   $s=$this->run('SELECT * FROM sms_login_security WHERE user_id=?'.$this->lock(),[$hint])->fetch(PDO::FETCH_ASSOC);
   $c=$this->run('SELECT * FROM sms_login_challenges WHERE token_hash=?'.$this->lock(),[$hash])->fetch(PDO::FETCH_ASSOC);
   if(!$s||!$c||!$c['active']||$c['consumed']||$c['purpose']!==$purpose||($actor!==null&&(int)$hint!==$actor)||!hash_equals($c['binding_hash'],hash('sha256',$binding))||(int)$c['expires_at']<=$now||(int)$c['auth_version']!==(int)($u['auth_version']??1)||(int)$c['security_version']!==(int)$s['version']||!hash_equals($c['email_hash'],hash('sha256',strtolower($u['email']))))throw new SmsLoginSecurityException('The code or verification request expired. Start again.');
   if((int)$s['blocked_until']>$now)throw new SmsLoginSecurityException('Too many attempts. Try again in 15 minutes.',429);
   $valid=preg_match('/^[0-9]{6}$/D',$code)&&hash_equals($c['code_hash'],hash_hmac('sha256','sms|'.$hash.'|'.$code,$key));
   $hashes=json_decode($s['recovery_hashes']??'[]',true)?:[];$recoveryIndex=false;
   if(!$valid&&$purpose==='login'&&preg_match('/^[a-f0-9]{20}$/D',str_replace('-','',strtolower($code)))){
    $candidate=hash_hmac('sha256','sms-recovery|'.str_replace('-','',strtolower($code)),$key);
    foreach($hashes as$i=>$stored)if(hash_equals($stored,$candidate)){$recoveryIndex=$i;$valid=true;break;}
   }
   if(!$valid){
    $failures=(int)$s['failures']+1;$blocked=$failures>=5?$now+900:0;
    $this->run('UPDATE sms_login_security SET failures=?,blocked_until=? WHERE user_id=?',[$failures,$blocked,$hint]);
    $error=new SmsLoginSecurityException($blocked?'Too many attempts. Try again in 15 minutes.':'The verification code is incorrect.',$blocked?429:422);
   }else{
    $this->run('UPDATE sms_login_challenges SET consumed=1 WHERE token_hash=?',[$hash]);
    if($purpose!=='login'){
     $enabled=$purpose==='enable';$stored=[];
     if($enabled)for($i=0;$i<8;$i++){$raw=bin2hex(random_bytes(10));$recovery[]=implode('-',str_split($raw,4));$stored[]=hash_hmac('sha256','sms-recovery|'.$raw,$key);}
     $this->run('UPDATE sms_login_security SET enabled=?,version=version+1,recovery_hashes=?,failures=0,blocked_until=0,updated_at=? WHERE user_id=?',[$enabled?1:0,json_encode($stored),date('Y-m-d H:i:s',$now),$hint]);
     $this->run('UPDATE users SET auth_version=COALESCE(auth_version,1)+1 WHERE id=?',[$hint]);
     $this->run('UPDATE sms_login_challenges SET consumed=1 WHERE user_id=?',[$hint]);
     $this->event((int)$hint,$enabled?'two_step_enabled':'two_step_disabled');
    }else{
     if($recoveryIndex!==false){unset($hashes[$recoveryIndex]);$this->run('UPDATE sms_login_security SET recovery_hashes=? WHERE user_id=?',[json_encode(array_values($hashes)),$hint]);$this->event((int)$hint,'recovery_code_used');}
     $this->run('UPDATE sms_login_security SET failures=0,blocked_until=0 WHERE user_id=?',[$hint]);
    }
   }
   if(!$error){$resultUser=$this->run('SELECT * FROM users WHERE id=?',[$hint])->fetch(PDO::FETCH_ASSOC);$resultState=$this->state((int)$hint);}
   $this->pdo->commit();
  }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
  if($error)throw $error;
  $resultUser['_sms_two_step_version']=(int)$resultState['version'];
  if(($resultUser['role']??'')==='admin')foreach(json_decode($c['claims_json'],true)as$k=>$v)$resultUser[$k]=$v;
  return ['user'=>$resultUser,'recovery_codes'=>$recovery];
 }
}
