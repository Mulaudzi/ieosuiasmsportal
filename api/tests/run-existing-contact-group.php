<?php
require __DIR__.'/../core/QueryBuilder.php';
require __DIR__.'/../controllers/ContactController.php';
class MembershipFixture extends PDO {
 public function prepare(string $sql,array $options=[]):PDOStatement|false{return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options);}
}
class TestResponse extends Error {public function __construct(public array $body,public int $status){parent::__construct('fixture response');}}
class Response {
 public static function success($data=null,int $status=200):void{throw new TestResponse(['success'=>true]+($data??[]),$status);}
 public static function created(array $data):void{self::success($data,201);}
 public static function error(string $message,int $status=400):void{throw new TestResponse(['success'=>false,'message'=>$message],$status);}
}
class Auth {public static function id():int{return 1;}}
class Request {public static array $data=[];public static function validate(array $rules):array{return self::$data;}}
function db():PDO{global $pdo;return $pdo;}
function attempt(array $data):TestResponse{Request::$data=$data;try{(new ContactController)->store();}catch(TestResponse $r){return $r;}throw new RuntimeException('No response');}
function fixture():PDO{
 $p=new MembershipFixture('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$p->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
 $p->exec("CREATE TABLE contacts(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,name TEXT,surname TEXT,phone TEXT,phone_normalized TEXT,phone_original TEXT,email TEXT,country_code TEXT,subscription_status TEXT,subscribed_at TEXT,created_at TEXT,updated_at TEXT,UNIQUE(user_id,phone_normalized));CREATE TABLE contact_groups(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT);CREATE TABLE group_contacts(group_id INTEGER,contact_id INTEGER,created_at TEXT,PRIMARY KEY(group_id,contact_id));CREATE TABLE opt_outs(user_id INTEGER,channel TEXT,recipient TEXT);CREATE TABLE wallets(balance TEXT);CREATE TABLE sms_messages(state TEXT);INSERT INTO contact_groups VALUES(1,1,'Original'),(2,1,'test'),(3,2,'Foreign');INSERT INTO contacts(id,user_id,name,email,phone,phone_normalized,subscription_status) VALUES(10,1,'Original name','original@example.com','+27638082493','+27638082493','unsubscribed'),(20,2,'Private',NULL,'+27799292775','+27799292775','subscribed');INSERT INTO group_contacts VALUES(1,10,'original');INSERT INTO opt_outs VALUES(1,'sms','+27638082493');INSERT INTO wallets VALUES('100.00');INSERT INTO sms_messages VALUES('held')");return $p;
}
$checks=0;function checkContact(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function dataSnapshot(PDO $p):array{$x=[];foreach(['contacts','contact_groups','group_contacts','opt_outs','wallets','sms_messages'] as $t)$x[$t]=$p->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll();return $x;}
$pdo=fixture();$before=dataSnapshot($pdo);$r=attempt(['name'=>'Do not overwrite','email'=>'changed@example.com','phone'=>'063 808 2493','group_id'=>'2']);
checkContact($r->status===200&&$r->body['existing_contact_reused'],'Existing account phone reused');checkContact($r->body['group_membership_added']&&!$r->body['already_in_group'],'Membership added');
$after=dataSnapshot($pdo);foreach(['contacts','contact_groups','opt_outs','wallets','sms_messages'] as $t)checkContact($after[$t]===$before[$t],$t.' unchanged');checkContact(count($after['group_contacts'])===2&&$after['group_contacts'][0]===$before['group_contacts'][0],'Original group preserved');
$r=attempt(['phone'=>'+27638082493','group_id'=>'2']);checkContact($r->body['already_in_group']&&!$r->body['group_membership_added'],'Repeated add is a success without duplicates');checkContact(dataSnapshot($pdo)===$after,'Repeat writes nothing');
$r=attempt(['phone'=>'0638082493']);checkContact($r->status===409,'Duplicate without a destination still rejected');
$r=attempt(['phone'=>'0638082493','group_id'=>'3']);checkContact($r->status===404,'Foreign group rejected');checkContact(dataSnapshot($pdo)===$after,'Rejected request changes nothing');
$r=attempt(['phone'=>'0799292775','group_id'=>'2']);checkContact($r->status===201,'Same number in another account does not block new contact');checkContact((int)$r->body['contact']['user_id']===1,'New contact belongs to caller');
$pdo=fixture();$pdo->exec("CREATE TRIGGER fail_add BEFORE INSERT ON group_contacts WHEN NEW.group_id=2 BEGIN SELECT RAISE(ABORT,'fixture'); END");$before=dataSnapshot($pdo);$r=attempt(['phone'=>'0638082493','group_id'=>'2']);checkContact($r->status===500&&dataSnapshot($pdo)===$before&&!$pdo->inTransaction(),'Membership failure rolls back');
echo "PASS $checks existing-contact controller checks; no production access or SMS\n";
