<?php
require_once __DIR__.'/../domain/SmsLoginSecurity.php';
require_once __DIR__.'/../domain/CentralSecurityEvents.php';
$config=['SMS_LOGIN_CODE_SECRET'=>str_repeat('fixture-key-',4),'SMS_LOGIN_ENROLLMENT_ENABLED'=>'true','CENTRAL_SECURITY_EVENTS_ENABLED'=>'true','CENTRAL_SECURITY_EVENTS_SECRET'=>str_repeat('fixture-central-',3)];
function env($k,$default=''){return $GLOBALS['config'][$k]??$default;}
$p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,email_verified_at TEXT,is_active INTEGER,auth_version INTEGER,name TEXT,role TEXT,identity_uuid TEXT);INSERT INTO users VALUES(1,'fixture@example.invalid','2026-10-01',1,1,'Fixture','user','aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),(2,'other@example.invalid','2026-10-01',1,1,'Other','user','bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')");
$migration=require __DIR__.'/../migrations/023_portal_login_security.php';$migration($p);$migration($p);
$time=time();$security=new SmsLoginSecurity($p,fn()=>$GLOBALS['time']);$n=0;$delivered=[];
function check($ok,$label){global$n;$n++;if(!$ok)throw new RuntimeException($label);}
function denied(callable $fn,int $status,string $label){try{$fn();check(false,$label);}catch(SmsLoginSecurityException $e){check($e->status===$status,$label.' status '.$e->status);}}
function user():array{return $GLOBALS['p']->query('SELECT * FROM users WHERE id=1')->fetch();}
$send=function($email,$code,$purpose)use(&$delivered){$delivered[]=[$email,$code,$purpose];return true;};
$binding=str_repeat('a',64);$browser=str_repeat('b',64);
check($security->status(1)['enabled']===false,'Default opt-out');check($security->available(),'Isolated additive schema');
$config['SMS_LOGIN_ENROLLMENT_PILOT_USERS']='';check($security->status(1)['enrollment_enabled']&&$security->status(2)['enrollment_enabled'],'Open enrollment is available to each account');check(!$security->status(1)['enabled']&&!$security->status(2)['enabled'],'Opening enrollment never auto-enrolls users');
$config['SMS_LOGIN_ENROLLMENT_PILOT_USERS']='2';check(!$security->status(1)['enrollment_enabled'],'Pilot not advertised to other accounts');denied(fn()=>$security->request(user(),'enable',$binding,$send),503,'Pilot enforced server-side');$config['SMS_LOGIN_ENROLLMENT_PILOT_USERS']='1';check($security->status(1)['enrollment_enabled'],'Pilot owner can enroll');
$q=$security->request(user(),'enable',$binding,$send);$code=$delivered[0][1];
check(preg_match('/^[0-9]{6}$/D',$code)===1,'Six-digit generated code');check(!isset($q['code']),'No code in API response');
$stored=$p->query('SELECT * FROM sms_login_challenges')->fetch();check($stored['code_hash']!==$code&&strlen($stored['code_hash'])===64,'Only peppered code digest stored');check($stored['token_hash']!==$q['challenge'],'Challenge capability hashed');
denied(fn()=>$security->verify($q['challenge'],$code,'enable','wrong-binding',1),422,'Session binding');denied(fn()=>$security->verify($q['challenge'],$code,'enable',$binding,2),422,'Account binding');denied(fn()=>$security->verify($q['challenge'],$code,'login',$binding),422,'Purpose binding');
$config['SMS_LOGIN_ENROLLMENT_PILOT_USERS']='2';denied(fn()=>$security->verify($q['challenge'],$code,'enable',$binding,1),503,'Pilot revocation blocks pending enrollment confirmation');$config['SMS_LOGIN_ENROLLMENT_PILOT_USERS']='1';
$result=$security->verify($q['challenge'],$code,'enable',$binding,1);check(count($result['recovery_codes'])===8,'Eight recovery codes shown once');$recovery=$result['recovery_codes'][0];
check((int)user()['auth_version']===2,'Enrollment revokes old app sessions');check($security->status(1)['enabled']===true,'Enabled only after proof');
denied(fn()=>$security->assertSession(user(),[]),401,'Old and unverified tokens rejected');$security->assertSession(user(),['sms_two_step_version'=>1]);check(true,'Verified claim accepted');
denied(fn()=>$security->verify($q['challenge'],$code,'enable',$binding,1),422,'Enrollment replay');
check(!str_contains($p->query('SELECT recovery_hashes FROM sms_login_security WHERE user_id=1')->fetchColumn(),$recovery),'No plaintext recovery code in DB');
$config['SMS_LOGIN_ENROLLMENT_ENABLED']='false';denied(fn()=>$security->assertSession(user(),[]),401,'Enrollment switch cannot bypass enforcement');
$time+=61;$login=$security->request(user(),'login',$browser,$send);$loginCode=end($delivered)[1];
denied(fn()=>$security->request(user(),'login',$browser,$send),429,'Resend cooldown per account');
for($i=0;$i<5;$i++)denied(fn()=>$security->verify($login['challenge'],'000000','login',$browser),$i===4?429:422,'Persisted code failure limit');
denied(fn()=>$security->verify($login['challenge'],$loginCode,'login',$browser),429,'Correct code cannot bypass active lock');
$time+=901;$login=$security->request(user(),'login',$browser,$send);$loginCode=end($delivered)[1];
$verified=$security->verify($login['challenge'],$loginCode,'login',$browser);check($verified['user']['id']===1,'Login proof after lock expiry');
denied(fn()=>$security->verify($login['challenge'],$loginCode,'login',$browser),422,'Login consumed once');
$time+=61;$login=$security->request(user(),'login',$browser,$send);$security->verify($login['challenge'],$recovery,'login',$browser);check($security->status(1)['recovery_codes_remaining']===7,'Recovery consumed atomically');
$time+=61;$login=$security->request(user(),'login',$browser,$send);denied(fn()=>$security->verify($login['challenge'],$recovery,'login',$browser),422,'Recovery replay denied');
$p->exec("UPDATE users SET email='changed@example.invalid' WHERE id=1");denied(fn()=>$security->verify($login['challenge'],end($delivered)[1],'login',$browser),422,'Changed email invalidates challenge');$p->exec("UPDATE users SET email='fixture@example.invalid' WHERE id=1");
$time+=61;$disable=$security->request(user(),'disable',$binding,$send);$security->verify($disable['challenge'],end($delivered)[1],'disable',$binding,1);check(!$security->status(1)['enabled']&&(int)user()['auth_version']===3,'Disable requires proof, revokes sessions');check($security->status(1)['recovery_codes_remaining']===0,'Disable clears recovery hashes');
denied(fn()=>$security->request(user(),'login',$browser,$send),409,'Disabled setting cannot produce login challenge');
$config['SMS_LOGIN_ENROLLMENT_ENABLED']='true';$time+=901;
denied(fn()=>$security->request(user(),'enable',$binding,fn()=>false),503,'Failed mail is not a usable code');
$failed=$p->query('SELECT active FROM sms_login_challenges ORDER BY created_at DESC LIMIT 1')->fetchColumn();check((int)$failed===0,'SMTP failure leaves challenge inactive');
$time+=61;$expired=$security->request(user(),'enable',$binding,$send);$time+=600;denied(fn()=>$security->verify($expired['challenge'],end($delivered)[1],'enable',$binding,1),422,'Exact expiry boundary');
$event=['id'=>'fixture-event-password-0001','type'=>'password_changed','subject'=>user()['identity_uuid'],'application'=>'sms','occurred_at'=>gmdate('Y-m-d\TH:i:s\Z',$time)];
$body=json_encode($event);$stamp=(string)$time;$sign=hash_hmac('sha256','sms-security-v1|'.$stamp.'|'.$body,$config['CENTRAL_SECURITY_EVENTS_SECRET']);
$before=(int)user()['auth_version'];$r=CentralSecurityEvents::receive($p,$body,$stamp,$sign,$time);check(!$r['duplicate']&&(int)user()['auth_version']===$before+1,'Verified central event invalidates app sessions');
$r=CentralSecurityEvents::receive($p,$body,$stamp,$sign,$time);check($r['duplicate']&&(int)user()['auth_version']===$before+1,'Central event replay idempotent');
denied(fn()=>CentralSecurityEvents::receive($p,$body,$stamp,str_repeat('0',64),$time),401,'Forged central event');
denied(fn()=>CentralSecurityEvents::receive($p,$body,$stamp,$sign,$time+301),401,'Stale signed request');
$changed=json_encode([...$event,'subject'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']);$changedSign=hash_hmac('sha256','sms-security-v1|'.$stamp.'|'.$changed,$config['CENTRAL_SECURITY_EVENTS_SECRET']);
denied(fn()=>CentralSecurityEvents::receive($p,$changed,$stamp,$changedSign,$time),409,'Event ID cannot change owner');
$config['CENTRAL_SECURITY_EVENTS_ENABLED']='false';denied(fn()=>CentralSecurityEvents::receive($p,$body,$stamp,$sign,$time),503,'Central feed disabled by default');
echo "PASS: $n local login-security, recovery, revocation and signed central-event checks. No mail sent.\n";
