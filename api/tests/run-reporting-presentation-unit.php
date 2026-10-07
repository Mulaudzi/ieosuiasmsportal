<?php
// Isolated SQL fixtures. Never connects to the production database.
require_once __DIR__.'/../domain/SmsReportingService.php';
final class ReportingFixturePDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query=str_replace('TIMESTAMPDIFF(SECOND,sent_at,delivered_at)',"((julianday(delivered_at)-julianday(sent_at))*86400)",$query);
        $query=str_replace('CONCAT(UPPER(LEFT(state,1)),SUBSTRING(state,2))','(UPPER(SUBSTR(state,1,1)) || SUBSTR(state,2))',$query);
        return parent::prepare($query,$options);
    }
}
$fixture=new ReportingFixturePDO('sqlite::memory:');
$fixture->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$fixture->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function db(): PDO {global $fixture;return $fixture;}
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$fixture->exec('CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT)');
$fixture->exec('CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,campaign_id INTEGER,user_id INTEGER,state TEXT,last_error TEXT,segment_count INTEGER,actual_charge REAL,sent_at TEXT,delivered_at TEXT,failed_at TEXT,created_at TEXT,destination TEXT)');
$fixture->exec("INSERT INTO sms_campaigns VALUES(1,1,'Fixture'),(2,2,'Other tenant')");
$insert=$fixture->prepare('INSERT INTO sms_messages(campaign_id,user_id,state,last_error,segment_count,actual_charge,sent_at,delivered_at,created_at,destination) VALUES(?,?,?,?,?,?,?,?,?,?)');
foreach([1,2] as $user){foreach([0,2,10,45,100] as $days){
    $created=(new DateTimeImmutable())->modify("-$days days -1 hour")->format('Y-m-d H:i:s');
    foreach([['delivered',null,1,.35],['delivered',null,2,.70],['sent',null,1,.35],['sent','DLR lookup: Cannot Find Message',1,.35],['failed','Rejected',1,.35],['pending',null,1,0],['skipped','Opted out',1,0]] as [$state,$error,$segments,$charge]){
        $insert->execute([$user,$user,$state,$error,$segments,$charge,$charge>0?$created:null,$state==='delivered'?$created:null,$created,'+27638082493']);
    }
}}
$before=$fixture->query('SELECT * FROM sms_messages ORDER BY id')->fetchAll();
foreach(['24h'=>1,'7d'=>2,'30d'=>3,'90d'=>4] as $range=>$packs){
    $summary=SmsReportingService::summary(1,$range);
    foreach(['total_messages'=>6,'delivered'=>2,'failed'=>1,'pending'=>3,'awaiting_delivery'=>1,'dlr_unavailable'=>1,'skipped'=>1,'credits_used'=>6] as $field=>$expected)check($summary[$field]===$expected*$packs,"$range $field mismatch");
    check($summary['delivery_rate']===33.3,'Skipped changed delivery rate');
    $breakdown=array_column(SmsReportingService::breakdown(1,$range),'count','status');
    foreach(['Delivered'=>2,'Delivery Confirmation Pending'=>1,'Delivery Status Unavailable'=>1,'Not Delivered'=>1,'Skipped'=>1,'Waiting to send'=>1] as $label=>$count)check((int)($breakdown[$label]??-1)===$count*$packs,"$range $label mismatch");
    $rows=SmsReportingService::rows(1,$range);
    check(count($rows)===7*$packs,'Export must include separately labelled skipped recipients');
    $exportCounts=array_count_values(array_column($rows,2));
    foreach(['Delivered'=>2,'Delivery Confirmation Pending'=>1,'Delivery Status Unavailable'=>1,'Not Delivered'=>1,'Skipped'=>1,'Waiting to send'=>1] as $label=>$count)check(($exportCounts[$label]??0)===$count*$packs,"$range export $label mismatch");
    check(array_sum(array_column(SmsReportingService::daily(1,$range),'total_messages'))===6*$packs,'Daily message totals changed');
}
check(SmsReportingService::summary(99,'7d')['skipped']===0,'Empty tenant skipped count');
check(SmsReportingService::rows(99,'7d')===[],'Tenant isolation');
check($before===$fixture->query('SELECT * FROM sms_messages ORDER BY id')->fetchAll(),'Reporting must not mutate messages');
echo "PASS: four periods, five delivery outcomes, waiting-to-send separated, tenant isolation, export parity, unchanged sent totals/credits/rate, no data writes\n";
