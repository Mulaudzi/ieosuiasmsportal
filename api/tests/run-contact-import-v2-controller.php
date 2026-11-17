<?php
// In-memory SQLite only. No .env, production database, network or SMS calls.
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../core/QueryBuilder.php';
require __DIR__.'/../controllers/ContactController.php';
class ImportFixturePDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}
}
class ImportFixtureResponse extends Error {public function __construct(public array $body,public int $status){parent::__construct('fixture response');}}
class Response {
    public static array $last=[];
    public static function success(array $data):void{self::$last=$data;}
    public static function error(string $message,int $status=400):void{throw new ImportFixtureResponse(['error'=>$message],$status);}
}
class Request {
    public static array $data=[],$files=[];
    public static function input(string $name,mixed $default=null):mixed{return self::$data[$name]??$default;}
    public static function file(string $name):?array{return self::$files[$name]??null;}
}
class Auth {public static function id():int{return 1;}}
function env(string $key,mixed $default=null):mixed{return $default;}
function db():PDO{global $pdo;return $pdo;}
function fixture():PDO{
    $pdo=new ImportFixturePDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
    $pdo->exec("CREATE TABLE contacts(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,name TEXT,surname TEXT,phone TEXT,phone_normalized TEXT,phone_original TEXT,email TEXT,country_code TEXT,subscription_status TEXT,subscribed_at TEXT,created_at TEXT,updated_at TEXT,UNIQUE(user_id,phone_normalized));CREATE TABLE contact_groups(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT);CREATE TABLE group_contacts(group_id INTEGER,contact_id INTEGER,created_at TEXT,PRIMARY KEY(group_id,contact_id));CREATE TABLE opt_outs(user_id INTEGER,channel TEXT,recipient TEXT);CREATE TABLE wallets(balance TEXT);CREATE TABLE sms_messages(state TEXT);INSERT INTO contact_groups VALUES(1,1,'Original'),(2,1,'Destination'),(3,2,'Private');INSERT INTO contacts(id,user_id,name,email,phone,phone_normalized,subscription_status) VALUES(10,1,'Original name','original@example.com','+27821234567','+27821234567','unsubscribed'),(20,2,'Private','emailonly@example.com',NULL,NULL,'unsubscribed');INSERT INTO group_contacts VALUES(1,10,'original'),(3,20,'private');INSERT INTO opt_outs VALUES(1,'sms','+27831234567');INSERT INTO wallets VALUES('100.00');INSERT INTO sms_messages VALUES('held')");return $pdo;
}
function snapshot():array{$data=[];foreach(['contacts','contact_groups','group_contacts','opt_outs','wallets','sms_messages'] as $table)$data[$table]=db()->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();return $data;}
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function runImport(array $rows,array $decisions=[],array $options=[],array $headers=['Name','Phone','Email']):array{
    $stream=tmpfile();fputcsv($stream,$headers,',','"','');foreach($rows as $row)fputcsv($stream,$row,',','"','');fflush($stream);$meta=stream_get_meta_data($stream);
    Request::$files=['file'=>['name'=>'contacts.csv','error'=>UPLOAD_ERR_OK,'tmp_name'=>$meta['uri'],'size'=>fstat($stream)['size']]];
    Request::$data=$options+['group_id'=>'2','phone_review_version'=>'2','phone_review_decisions'=>json_encode($decisions),'column_mapping'=>json_encode(['name'=>'Name','phone'=>'Phone','email'=>'Email'])];Response::$last=[];
    try{(new ContactController)->import();return ['status'=>200,'body'=>Response::$last];}catch(ImportFixtureResponse $response){return ['status'=>$response->status,'body'=>$response->body];}finally{fclose($stream);}
}
$rows=[['Footer','',''],['Reused','821234567','changed@example.com'],['New','0027821234568','new@example.com'],['Email','','EMAILONLY@example.com'],['Duplicate email','','emailonly@example.com'],['Uncertain','2771234567','kept@example.com'],['Bad email','0821234569','no-at'],['Opt out','0831234567',''],['Legend','N/A','—']];
$decisions=[['row'=>7,'action'=>'email_only','original'=>'2771234567','originalEmail'=>'kept@example.com'],['row'=>8,'action'=>'accept','original'=>'0821234569','originalEmail'=>'no-at','value'=>'0821234569','email'=>'']];
$pdo=fixture();$before=snapshot();$result=runImport($rows,[]);check($result['status']===400,'Unresolved final rows reject entire import');check(snapshot()===$before,'Preflight failure creates no partial contacts or memberships');
$result=runImport($rows,$decisions);check($result['status']===200,'Reviewed mixed import succeeds');$body=$result['body'];
foreach(['imported'=>5,'duplicates'=>2,'skipped_empty'=>2,'automatically_corrected'=>2,'email_only'=>3,'rejected'=>0,'failed'=>0] as $key=>$value)check($body[$key]===$value,'Correct server aggregate: '.$key);
$after=snapshot();check($after['contacts'][0]===$before['contacts'][0],'Existing details and unsubscribe status preserved');check($after['contacts'][1]===$before['contacts'][1],'Other account contact preserved');
foreach(['contact_groups','opt_outs','wallets','sms_messages'] as $table)check($after[$table]===$before[$table],$table.' preserved');
check(count($after['group_contacts'])===8,'New and reused contacts linked to destination while original memberships remain');
$optOut=$pdo->query("SELECT subscription_status FROM contacts WHERE user_id=1 AND phone_normalized='+27831234567'")->fetchColumn();check($optOut==='unsubscribed','New contact honours existing SMS opt-out');
$emailContact=$pdo->query("SELECT phone,phone_normalized FROM contacts WHERE user_id=1 AND email='kept@example.com'")->fetch();check($emailContact['phone']===null&&$emailContact['phone_normalized']===null,'Explicit email-only choice cannot become an SMS recipient');
$result=runImport($rows,$decisions);check($result['body']['imported']===0&&$result['body']['duplicates']===7,'Repeated file does not create duplicate phone or email contacts');check(snapshot()===$after,'Repeated file preserves all saved records and memberships');
$result=runImport($rows,$decisions,['group_id'=>'3']);check($result['status']===404&&snapshot()===$after,'Cross-account destination rejected without writes');
$bad=$decisions;$bad[0]['originalEmail']='changed@example.com';$result=runImport($rows,$bad);check($result['status']===400&&snapshot()===$after,'Email changes invalidate row-bound decisions atomically');
$result=runImport([['Only email','only@example.com']],[],['column_mapping'=>json_encode(['name'=>'Name','email'=>'Email'])],['Name','Email']);check($result['body']['imported']===1&&$result['body']['email_only']===1,'File with no phone column can import email contacts');
// Old clients retain version 1 consent rules instead of silently gaining repairs.
$before=snapshot();$result=runImport([['Legacy','821234567','']],[],['phone_review_version'=>'1']);check($result['status']===400&&snapshot()===$before,'Legacy v1 still requires missing-zero approval');
echo json_encode(['passed'=>true,'checks'=>$checks,'database'=>'sqlite::memory:','production_access'=>false,'sms_submitted'=>false],JSON_PRETTY_PRINT)."\n";
