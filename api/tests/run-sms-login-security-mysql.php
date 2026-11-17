<?php
require_once __DIR__.'/../domain/SmsLoginSecurity.php';
function env($k,$default=''){return match($k){'SMS_LOGIN_CODE_SECRET'=>str_repeat('mysql-security-fixture-',3),'SMS_LOGIN_ENROLLMENT_ENABLED'=>'true',default=>$default};}
function connection(string $database=''):PDO{$p=new PDO('mysql:host=127.0.0.1;port=33163;'.($database?'dbname='.$database.';':''),'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return $p;}
if(($argv[1]??'')==='--child'){
 if(!preg_match('/^sms_login_fixture_[a-f0-9]{16}$/D',$argv[2]??''))exit(64);
 try{(new SmsLoginSecurity(connection($argv[2])))->verify($argv[3],$argv[4],'enable',str_repeat('a',64),1);echo 'verified';}
 catch(SmsLoginSecurityException){echo 'denied';}exit;
}
$db='sms_login_fixture_'.bin2hex(random_bytes(8));$root=connection();$root->exec("CREATE DATABASE {$db}");$p=connection($db);$n=0;
function check($ok,$why){global$n;$n++;if(!$ok)throw new RuntimeException($why);}
try{
 $p->exec("CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(255),email_verified_at DATETIME,is_active INTEGER,auth_version INTEGER,name VARCHAR(100),role VARCHAR(20)) ENGINE=InnoDB;INSERT INTO users VALUES(1,'fixture@example.invalid',NOW(),1,1,'Fixture','user')");
 $migration=require __DIR__.'/../migrations/023_portal_login_security.php';$migration($p);$migration($p);$s=new SmsLoginSecurity($p);$u=$p->query('SELECT * FROM users')->fetch();$code='';
 $r=$s->request($u,'enable',str_repeat('a',64),function($email,$value)use(&$code){$code=$value;return true;});
 $children=[];
 for($i=0;$i<2;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--child',$db,$r['challenge'],$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Child unavailable');fclose($pipes[0]);$children[]=[$proc,$pipes];}
 $out=[];foreach($children as[$proc,$pipes]){$out[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0&&$err==='','Concurrent child clean exit');}
 sort($out);check($out===['denied','verified'],'One concurrent consumption only');
 check((int)$p->query('SELECT auth_version FROM users WHERE id=1')->fetchColumn()===2,'Exactly one session-version increment');
 check((int)$p->query("SELECT COUNT(*) FROM portal_security_events WHERE event_type='two_step_enabled'")->fetchColumn()===1,'One security event');
 check(count(json_decode($p->query('SELECT recovery_hashes FROM sms_login_security WHERE user_id=1')->fetchColumn(),true))===8,'One recovery-code set');
 check((int)$p->query('SELECT consumed FROM sms_login_challenges')->fetchColumn()===1,'Challenge durably consumed');
 echo "PASS: $n isolated MySQL migration, concurrent consumption, epoch and audit checks. No mail sent.\n";
}finally{$p=null;if(!preg_match('/^sms_login_fixture_[a-f0-9]{16}$/D',$db))throw new RuntimeException('Unexpected fixture cleanup target');$root->exec("DROP DATABASE {$db}");}
