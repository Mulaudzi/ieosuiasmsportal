<?php
// Exercise the real JWT and both real Auth entry paths using only an in-memory fixture.
require_once __DIR__.'/../core/JWT.php';require_once __DIR__.'/../core/QueryBuilder.php';require_once __DIR__.'/../core/Auth.php';require_once __DIR__.'/../domain/SmsLoginSecurity.php';
function env($name,$default=''){return match($name){'JWT_SECRET','SMS_LOGIN_CODE_SECRET'=>str_repeat('local-auth-fixture-',3),'SMS_LOGIN_ENROLLMENT_ENABLED'=>'true',default=>$default};}
final class GuardPDO extends PDO{
 public function exec(string $statement):int|false{if(str_starts_with($statement,'CREATE TABLE IF NOT EXISTS revoked_auth_tokens'))$statement='CREATE TABLE IF NOT EXISTS revoked_auth_tokens(token_hash TEXT PRIMARY KEY,user_id INTEGER,token_type TEXT,expires_at TEXT)';return parent::exec($statement);}
 public function prepare(string $query,array $options=[]):PDOStatement|false{return parent::prepare(str_replace('NOW()',"datetime('now')",$query),$options);}
}
$p=new GuardPDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function db():PDO{return $GLOBALS['p'];}
final class Request{static string $token='';static function bearerToken():string{return self::$token;}}
final class GuardResponse extends RuntimeException{}
final class Response{static function error($message,$status):never{throw new GuardResponse('denied',(int)$status);}}
$p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,email_verified_at TEXT,is_active INTEGER,auth_version INTEGER,role TEXT);INSERT INTO users VALUES(1,'Fixture','fixture@example.invalid','2026-10-01',1,1,'user')");
$migration=require __DIR__.'/../migrations/023_portal_login_security.php';$migration($p);$s=new SmsLoginSecurity($p);$user=$p->query('SELECT * FROM users')->fetch();
$n=0;function check($ok,$why){global$n;$n++;if(!$ok)throw new RuntimeException($why);}
function resetAuth(){foreach(['user'=>null,'tokenChecked'=>false]as$key=>$value)(new ReflectionProperty(Auth::class,$key))->setValue(null,$value);}
Request::$token=Auth::generateToken($user);Auth::check();check(Auth::id()===1,'Legacy authentication still works before opt-in');
$code='';$challenge=$s->request($user,'enable',str_repeat('a',64),function($email,$value)use(&$code){$code=$value;return true;});$result=$s->verify($challenge['challenge'],$code,'enable',str_repeat('a',64),1);
resetAuth();try{Auth::check();check(false,'Old token allowed');}catch(GuardResponse $e){check($e->getCode()===401,'Old auth version revoked');}
$fresh=$p->query('SELECT * FROM users')->fetch();Request::$token=Auth::generateToken($fresh);resetAuth();try{Auth::check();check(false,'Missing proof allowed');}catch(GuardResponse $e){check($e->getCode()===401,'Full token without step-up cannot access protected APIs');}
resetAuth();check(Auth::user()===null,'Lazy Auth::user cannot bypass MFA');
Request::$token=Auth::generateToken($result['user']);resetAuth();Auth::check();check(Auth::user()['id']===1,'Verified code yields usable token');
$refreshed=Auth::generateToken(Auth::user());check((JWT::decode($refreshed)['sms_two_step_version']??null)===1,'Refresh retains verified proof');
Request::$token=$refreshed;resetAuth();Auth::check();check(Auth::id()===1,'Refreshed session accepted');
$p->exec('UPDATE sms_login_security SET version=version+1 WHERE user_id=1');resetAuth();try{Auth::check();check(false,'Obsolete proof accepted');}catch(GuardResponse $e){check($e->getCode()===401,'Security epoch revocation enforced');}
echo "PASS: $n real JWT/Auth middleware, lazy auth and refresh checks. No remote authentication.\n";
