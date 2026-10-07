<?php
// Isolated SQLite + capturing PHPMailer. Never opens SMTP or a production database.
function env($key,$fallback=null){return $fallback;}
require_once __DIR__.'/../services/EmailService.php';
require_once __DIR__.'/../services/CampaignReportMailer.php';
final class ReportFixturePDO extends PDO {
    private function translate(string $q):string{return str_replace(['INSERT IGNORE','DATE_SUB(NOW(),INTERVAL 10 MINUTE)','IF(?=1,NOW(),NULL)','NOW()'],['INSERT OR IGNORE',"datetime('now','-10 minutes')","CASE WHEN ?=1 THEN datetime('now') ELSE NULL END","datetime('now')"],$q);}
    public function exec(string $statement):int|false{return parent::exec($this->translate($statement));}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return parent::prepare($this->translate($query),$options);}
}
final class CapturingReportMailer extends PHPMailer\PHPMailer\PHPMailer {
    public array $attempts=[];
    public function send():bool{
        $this->attempts[]=['to'=>$this->getToAddresses(),'attachments'=>$this->getAttachments(),'body'=>$this->Body,'subject'=>$this->Subject];
        if(str_contains($this->Subject,'Ambiguous'))throw new RuntimeException('Simulated timeout after SMTP acceptance');return true;
    }
}
$fixture=new ReportFixturePDO('sqlite::memory:');$fixture->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$fixture->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function db():PDO{global $fixture;return $fixture;}
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$fixture->exec('CREATE TABLE campaign_report_settings(id INTEGER,enabled_at TEXT);CREATE TABLE users(id INTEGER,email TEXT);CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,created_at TEXT,completed_at TEXT,state TEXT);CREATE TABLE sms_messages(id INTEGER,campaign_id INTEGER,user_id INTEGER,destination TEXT,state TEXT,segment_count INTEGER,sent_at TEXT,last_error TEXT,created_at TEXT);CREATE TABLE contacts(user_id INTEGER,phone_normalized TEXT,name TEXT,surname TEXT);CREATE TABLE campaign_report_outbox(campaign_id INTEGER PRIMARY KEY,user_id INTEGER,status TEXT DEFAULT \'pending\',lock_token TEXT,locked_at TEXT,sent_at TEXT,last_error TEXT,created_at TEXT)');
$fixture->exec("INSERT INTO campaign_report_settings VALUES(1,datetime('now','-1 hour'));INSERT INTO users VALUES(1,'account@example.invalid'),(2,'other@example.invalid');INSERT INTO sms_campaigns VALUES(1,1,'Normal',datetime('now','-1 day'),datetime('now'),'completed'),(2,1,'Ambiguous',datetime('now','-1 day'),datetime('now'),'completed'),(3,1,'Historic',datetime('now','-2 days'),datetime('now','-1 day'),'completed'),(4,1,'Not complete',datetime('now'),NULL,'queued');INSERT INTO sms_messages VALUES(1,1,1,'+27638082493','delivered',1,datetime('now'),NULL,datetime('now')),(2,1,1,'+27638082494','sent',1,datetime('now'),'DLR lookup: unavailable',datetime('now'))");
$mailer=new CapturingReportMailer(true);$property=new ReflectionProperty(EmailService::class,'mailer');$property->setValue(null,$mailer);
putenv('CAMPAIGN_REPORT_EMAIL_ENABLED=0');check(CampaignReportMailer::dispatchDue()['enabled']===false,'Disabled worker sends');
putenv('CAMPAIGN_REPORT_EMAIL_ENABLED=1');$result=CampaignReportMailer::dispatchDue();check($result['sent']===1&&$result['review_required']===1,'Wrong worker results');check(count($mailer->attempts)===2,'Wrong attempt count');
CampaignReportMailer::dispatchDue();check(count($mailer->attempts)===2,'Duplicate or ambiguous email resent');
foreach($mailer->attempts as $attempt){check($attempt['to'][0][0]==='account@example.invalid','Wrong registered account');check(count($attempt['attachments'])===2,'Attachment bleed across messages');check(str_contains($attempt['attachments'][0][2],'.pdf')&&str_contains($attempt['attachments'][1][2],'.csv'),'Attachment names');}
check($fixture->query('SELECT COUNT(*) FROM campaign_report_outbox')->fetchColumn()==2,'Historic or incomplete campaign queued');
try{SmsReportDocument::load(2,1);throw new LogicException('Tenant leaked');}catch(RuntimeException $e){check($e->getMessage()==='Campaign not found','Tenant check failed');}
$filtered=SmsReportDocument::load(1,1,'7d','dlr_unavailable');check($filtered['total']===1&&$filtered['counts']['Not Delivered']===0,'Export filter not applied');
$searched=SmsReportDocument::load(1,1,'7d','','2493');check($searched['total']===1,'Number search mismatch');
echo "PASS: disabled gate, registered recipient, two attachments, one send per campaign, ambiguous outcomes not resent, historical cutoff, ownership and filtered dataset\n";
