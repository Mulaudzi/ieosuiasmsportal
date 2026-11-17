<?php
// Real SELECTs against a local in-memory fixture only; no configuration or network.
require __DIR__.'/../core/QueryBuilder.php';
require __DIR__.'/../controllers/ContactController.php';
class ContactsPageFixture extends PDO {
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        return parent::prepare(str_replace('DATE_SUB(NOW(),INTERVAL 7 DAY)',"datetime(NOW(), '-7 days')",$query),$options);
    }
}
class Request {public static array $params=[];public static function query(?string $key=null,$default=null){return $key===null?self::$params:(self::$params[$key]??$default);}}
class Auth {public static function id():int{return 1;}}
class Response {public static array $result=[];public static function success(array $data):void{self::$result=$data;}}
$fixture=new ContactsPageFixture('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db():PDO{global $fixture;return $fixture;}
$fixture->sqliteCreateFunction('NOW',fn()=>'2026-10-24 12:00:00');
$fixture->exec("CREATE TABLE contacts(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,phone TEXT,phone_normalized TEXT,email TEXT,subscription_status TEXT,created_at TEXT);CREATE TABLE contact_groups(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT);CREATE TABLE group_contacts(group_id INTEGER,contact_id INTEGER);CREATE TABLE opt_outs(user_id INTEGER,channel TEXT,recipient TEXT);INSERT INTO contact_groups VALUES(1,1,'Church'),(2,1,'Test'),(3,2,'Private');INSERT INTO contacts VALUES(1,1,'Alice','+27821111111','+27821111111','alice@example.test','subscribed','2026-10-01 12:00:00'),(2,1,'Zed','+27822222222','+27822222222','zed@example.test','subscribed','2026-10-24 12:00:00'),(3,1,'Bea','+27823333333','+27823333333','bea@example.test','subscribed','2026-10-17 12:00:00'),(4,1,'No group','+27824444444','+27824444444','none@example.test','subscribed','2026-10-16 12:00:00'),(5,1,'Future','+27825555555','+27825555555','future@example.test','subscribed','2026-10-25 12:00:00'),(6,2,'Private','+27826666666','+27826666666','private@example.test','subscribed','2026-10-24 12:00:00');INSERT INTO group_contacts VALUES(1,1),(1,2),(2,2),(2,3),(3,6);INSERT INTO opt_outs VALUES(1,'sms','+27822222222'),(2,'sms','+27826666666');");
$checks=0;
function contactsCheck(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
function contactsQuery(array $input):array{Request::$params=$input;(new ContactController)->index();return Response::$result;}
$before=$fixture->query('SELECT * FROM contacts ORDER BY id')->fetchAll();
$newest=contactsQuery(['per_page'=>2]);contactsCheck(array_column($newest['data'],'id')===[5,2],'Newest sorting spans all data before pagination');contactsCheck((int)$newest['meta']['total']===5,'Account count excludes other tenants and membership duplicates');contactsCheck($newest['meta']['recently_added_total']===2,'Last seven days is account-wide, boundary-inclusive and excludes future/foreign records');contactsCheck($newest['meta']['opted_out_total']===1,'Opt-out count is canonical and tenant-scoped');
$oldest=contactsQuery(['per_page'=>2,'sort'=>'oldest']);contactsCheck(array_column($oldest['data'],'id')===[1,4],'Oldest ordering applied by database');
$names=contactsQuery(['sort'=>'name']);contactsCheck(array_column($names['data'],'name')===['Alice','Bea','Future','No group','Zed'],'Name ordering is global');
$page=contactsQuery(['sort'=>'name','per_page'=>2,'page'=>2]);contactsCheck(array_column($page['data'],'name')===['Future','No group'],'Name sorting retained on later pages');
foreach(['all'=>[], 'group'=>['group_id'=>1], 'search'=>['search'=>'Zed'], 'group-search'=>['group_id'=>2,'search'=>'Zed'], 'uncategorized'=>['group_id'=>'uncategorized'], 'uncategorized-search'=>['group_id'=>'uncategorized','search'=>'No group'], 'opted-out'=>['group_id'=>'opted-out'], 'opted-out-search'=>['group_id'=>'opted-out','search'=>'Zed']] as $view=>$params){
    $r=contactsQuery($params+['sort'=>'oldest','per_page'=>1]);contactsCheck($r['meta']['recently_added_total']===2&&$r['meta']['opted_out_total']===1,'Summary independent of filters/page: '.$view);foreach($r['data'] as $row)contactsCheck((int)$row['user_id']===1,'Only owned contacts in '.$view);
}
$r=contactsQuery(['group_id'=>3]);contactsCheck($r['data']===[],'Foreign group cannot expose foreign contacts');
$r=contactsQuery(['sort'=>'created_at; DROP TABLE contacts']);contactsCheck($r['data'][0]['id']===5,'Arbitrary sort input maps to safe allowlisted default');
$r=contactsQuery(['search'=>'does-not-exist']);contactsCheck($r['data']===[]&&$r['meta']['recently_added_total']===2,'No search matches does not zero global summary');
$r=contactsQuery(['group_id'=>'opted-out']);contactsCheck($r['data'][0]['subscription_status']==='unsubscribed','Existing opt-out suppression presentation preserved');
contactsCheck($before===$fixture->query('SELECT * FROM contacts ORDER BY id')->fetchAll(),'All new summary/sort operations are read-only');
echo "PASS $checks Contacts summary, sorting, tenant/filter/pagination and read-only checks.\n";
