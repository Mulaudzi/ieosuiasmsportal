<?php
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../domain/EmailCampaignService.php';
require_once __DIR__.'/../services/EmailCampaignTransport.php';

$pdo=null;$locked=false;$exit=0;$result=['submitted'=>0,'accepted'=>0,'rejected'=>0,'held'=>0,'settled'=>0];
try {
    $pdo=db();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL advisory locks are required');
    $locked=(int)$pdo->query("SELECT GET_LOCK('ieosuia-email-campaign-worker-v1',0)")->fetchColumn()===1;
    if (!$locked) {$result['state']='another_worker_active';}
    else {
        $service=new EmailCampaignService($pdo);
        // Accounting/recovery only: these operations cannot submit email, even when the send switch is off.
        $result['settled']=$service->settleAccepted();$result['held']=$service->holdStale();
        $ready=EmailCampaignPolicy::readiness();
        if (!$ready['ready']) {$result['state']='sending_disabled';}
        else {
            $start=microtime(true);$maximum=min(25,max(1,(int)env('EMAIL_CAMPAIGN_BATCH_SIZE',10)));
            while ($result['submitted']<$maximum && microtime(true)-$start<40) {
                $job=$service->claim();if(!$job){
                    if(env('EMAIL_CAMPAIGN_PROVIDER','smtp')==='ses'){
                        require_once __DIR__.'/../domain/EmailSesQuota.php';$delay=EmailSesQuota::delay($pdo);
                        if($delay>0&&$delay<=2&&microtime(true)-$start+$delay<39){usleep((int)ceil($delay*1000000));continue;}
                    }
                    break;
                }
                // The durable 'submitting' checkpoint is committed before any network I/O.
                $outcome=EmailCampaignTransport::submit($job);
                $service->recordOutcome($job['recipient']['id'],$outcome);
                $result['submitted']++;$result[$outcome==='uncertain'?'held':$outcome]++;
                $result['settled']+=$service->settleAccepted();
                // Do not turn one unknown result or SES transport rejection into a batch of held/failed recipients.
                if($outcome==='uncertain'||($outcome==='rejected'&&env('EMAIL_CAMPAIGN_PROVIDER','smtp')==='ses')){
                    $result['state']=$outcome==='uncertain'?'uncertain_submission_stop':'provider_rejected_stop';$exit=1;break;
                }
            }
            $result['state']??='completed';
        }
    }
} catch(Throwable $e) {
    // If persistence fails after SMTP, submitting stays held, never automatically requeued.
    $result['state']='needs_attention';$result['error']='Email worker stopped safely. Inspect audit and reservation records before recovery.';$exit=1;
    error_log('email_campaign_worker_failure class='.get_class($e));
} finally {
    if ($pdo && $locked) try {$pdo->query("SELECT RELEASE_LOCK('ieosuia-email-campaign-worker-v1')");}catch(Throwable){}
}
echo json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($exit);
