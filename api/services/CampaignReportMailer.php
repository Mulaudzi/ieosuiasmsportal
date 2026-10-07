<?php
require_once __DIR__.'/SmsReportDocument.php';

/** Separate from SMS dispatch: one durable email claim per campaign. */
final class CampaignReportMailer
{
    public static function dispatchDue(int $limit=5):array
    {
        if(getenv('CAMPAIGN_REPORT_EMAIL_ENABLED')!=='1')return ['enabled'=>false,'sent'=>0,'review_required'=>0];
        $pdo=db();$result=['enabled'=>true,'sent'=>0,'review_required'=>0];
        $pdo->exec("INSERT IGNORE INTO campaign_report_outbox(campaign_id,user_id,created_at) SELECT c.id,c.user_id,NOW() FROM sms_campaigns c JOIN campaign_report_settings s ON s.id=1 WHERE c.completed_at>=s.enabled_at AND c.state IN ('completed','partially_failed','failed')");
        // A crash before SMTP is safe to retry. A crash during SMTP is ambiguous and is not retried automatically.
        $pdo->exec("UPDATE campaign_report_outbox SET status='pending',lock_token=NULL WHERE status='preparing' AND locked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
        $pdo->exec("UPDATE campaign_report_outbox SET status='review',last_error='SMTP outcome unknown; verify mail logs before manual recovery' WHERE status='sending' AND locked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
        $limit=max(1,min(25,$limit));$jobs=$pdo->query("SELECT campaign_id,user_id FROM campaign_report_outbox WHERE status='pending' ORDER BY created_at LIMIT $limit")->fetchAll();
        foreach($jobs as $job){
            $token=bin2hex(random_bytes(20));$claim=$pdo->prepare("UPDATE campaign_report_outbox SET status='preparing',lock_token=?,locked_at=NOW() WHERE campaign_id=? AND status='pending'");$claim->execute([$token,$job['campaign_id']]);if(!$claim->rowCount())continue;
            try{
                $user=$pdo->prepare('SELECT email FROM users WHERE id=?');$user->execute([$job['user_id']]);$email=(string)$user->fetchColumn();if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Registered email unavailable');
                $r=SmsReportDocument::load((int)$job['user_id'],(int)$job['campaign_id']);
                // Render before the irreversible delivery boundary. Use the same snapshot for both attachments.
                $html=SmsReportDocument::email($r);$pdf=SmsReportDocument::pdf($r);$csv=SmsReportDocument::csv($r);
                $sending=$pdo->prepare("UPDATE campaign_report_outbox SET status='sending',locked_at=NOW() WHERE campaign_id=? AND lock_token=? AND status='preparing'");$sending->execute([$job['campaign_id'],$token]);if(!$sending->rowCount())continue;
                require_once __DIR__.'/EmailService.php';
                $sent=EmailService::send($email,'IEOSUIA SMS report – '.$r['name'],$html,null,null,[['name'=>SmsReportDocument::filename($r).'.pdf','type'=>'application/pdf','data'=>$pdf],['name'=>SmsReportDocument::filename($r).'.csv','type'=>'text/csv','data'=>$csv]]);
                $ok=!empty($sent['success']);$update=$pdo->prepare("UPDATE campaign_report_outbox SET status=?,sent_at=IF(?=1,NOW(),NULL),last_error=? WHERE campaign_id=? AND lock_token=? AND status='sending'");$update->execute([$ok?'sent':'review',$ok?1:0,$ok?null:'Email delivery not confirmed; verify SMTP logs before recovery',$job['campaign_id'],$token]);$result[$ok?'sent':'review_required']++;
            }catch(Throwable $e){
                // Do not expose recipient data or automatically repeat an uncertain send.
                $pdo->prepare("UPDATE campaign_report_outbox SET status='review',last_error='Report preparation or delivery failed; operator review required' WHERE campaign_id=? AND lock_token=? AND status IN ('preparing','sending')")->execute([$job['campaign_id'],$token]);$result['review_required']++;
            }
        }
        return $result;
    }
}
