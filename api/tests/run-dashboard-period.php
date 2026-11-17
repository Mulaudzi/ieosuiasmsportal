<?php
// Read-only application queries against an isolated SQLite fixture, never production.
require_once __DIR__.'/../domain/SmsReportingService.php';
require_once __DIR__.'/../controllers/DashboardController.php';
require_once __DIR__.'/../controllers/SmsController.php';
require_once __DIR__.'/../core/QueryBuilder.php';
final class PeriodFixture extends PDO {
 public function prepare(string $sql,array $options=[]):PDOStatement|false{return parent::prepare(str_replace('TIMESTAMPDIFF(SECOND,sent_at,delivered_at)','0',$sql),$options);}
}
$fixture=new PeriodFixture('sqlite::memory:');$fixture->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$fixture->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function db():PDO {global $fixture;return $fixture;}
final class Auth {static function id(){return 1;}}
final class Request {static array $params=[];static function query($key,$default=null){return self::$params[$key]??$default;}}
final class Response {static array $result=[];static int $status=200;static function success($data){self::$result=$data;self::$status=200;}static function error($message,$status){self::$result=['error'=>$message];self::$status=$status;}}
$checks=0;function check($condition,$message){global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
date_default_timezone_set('Africa/Johannesburg');
$period=SmsReportingService::period('custom','2026-10-01','2026-10-01');
check($period['start']==='2026-10-01 00:00:00'&&$period['end']==='2026-10-01 23:59:59','Both date boundaries inclusive');
foreach([['2026-02-29','2026-03-01'],['2026-10-02','2026-10-01'],['',''],['2024-01-01','2025-01-01']] as [$from,$to]){
 try{SmsReportingService::period('custom',$from,$to);check(false,'Invalid period accepted');}catch(InvalidArgumentException){check(true,'Rejected invalid period');}
}
check(SmsReportingService::period('custom','2024-01-01','2024-12-31')['days']===366,'Leap year range');
$fixture->exec('CREATE TABLE sms_campaigns(id INTEGER,user_id INTEGER,name TEXT,state TEXT,created_at TEXT);CREATE TABLE contacts(id INTEGER,user_id INTEGER);CREATE TABLE wallets(user_id INTEGER,balance REAL);CREATE TABLE sms_messages(id INTEGER,user_id INTEGER,campaign_id INTEGER,state TEXT,last_error TEXT,segment_count INTEGER,actual_charge REAL,sent_at TEXT,delivered_at TEXT,created_at TEXT)');
$fixture->exec("INSERT INTO contacts VALUES(1,1),(2,2);INSERT INTO wallets VALUES(1,15);INSERT INTO sms_campaigns VALUES(1,1,'Legacy network outcome','partially_failed','2026-10-01 00:00:00'),(2,1,'Submission issue','partially_failed','2026-10-01 23:59:59'),(3,1,'Outside period','completed','2026-10-02 00:00:00'),(4,2,'Foreign','completed','2026-10-01 12:00:00')");
$insert=$fixture->prepare('INSERT INTO sms_messages VALUES(?,?,?,?,?,?,?,?,?,?)');
foreach([[1,1,1,'delivered',null,1,.35,'2026-10-01 00:00:00','2026-10-01 00:00:01','2026-10-01 00:00:00'],[2,1,1,'failed','Network non-delivery',1,.35,'2026-10-01 23:59:59',null,'2026-10-01 23:59:59'],[3,1,2,'failed','Rejected before acceptance',1,0,null,null,'2026-10-01 12:00:00'],[4,1,3,'delivered',null,1,.35,'2026-10-02 00:00:00',null,'2026-10-02 00:00:00'],[5,2,4,'sent',null,1,.35,'2026-10-01 12:00:00',null,'2026-10-01 12:00:00']] as $row)$insert->execute($row);
$fixture->exec('ALTER TABLE sms_messages ADD next_attempt_at TEXT');
$before=$fixture->query('SELECT * FROM sms_messages')->fetchAll();
Request::$params=['range'=>'custom','from'=>'2026-10-01','to'=>'2026-10-01'];
(new DashboardController)->stats();check(Response::$status===200&&Response::$result['total_sent']===2,'Scoped dashboard stats and tenant isolation');check(Response::$result['delivery_rate']===50.0,'Accepted denominator');
(new DashboardController)->chart();check(count(Response::$result['chart'])===1&&Response::$result['chart'][0]['sent']===2,'Chart matches stats');
(new DashboardController)->recentCampaigns();check(array_column(Response::$result['campaigns'],'id')===[2,1],'Recent campaigns same inclusive period');
Request::$params['status']='completed';(new SmsController)->index();check(Response::$result['stats']['total']===1&&Response::$result['campaigns'][0]['id']===1,'Completed filter uses actual submissions, not network failures');check(Response::$result['status_counts']['partially_failed']===1,'Genuine submission issues remain separate');
Request::$params['status']='partially_failed';(new SmsController)->index();check(Response::$result['campaigns'][0]['id']===2,'Submission issues filter');
unset(Request::$params['status']);Request::$params['sort']='oldest';(new SmsController)->index();check(array_column(Response::$result['campaigns'],'id')===[1,2],'Oldest first across matching campaigns');
Request::$params['from']='bad';foreach(['stats','chart','recentCampaigns'] as $method){(new DashboardController)->$method();check(Response::$status===422,'Invalid dates fail closed');}
(new SmsController)->index();check(Response::$status===422,'Campaign dates fail closed');
check($fixture->query('SELECT * FROM sms_messages')->fetchAll()===$before,'No message writes');
echo "PASS: $checks date boundary, dashboard parity, tenant isolation, lifecycle filtering and read-only checks\n";
