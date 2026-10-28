<?php

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../core/QueryBuilder.php';
require_once __DIR__.'/../domain/LogicSmsDeliveryService.php';
require_once __DIR__.'/../domain/LogicSmsDeliveryStatus.php';
require_once __DIR__.'/../domain/SmsDispatchService.php';
require_once __DIR__.'/../services/OperationalLogger.php';
require_once __DIR__.'/../domain/LogicSmsStatusSync.php';
require_once __DIR__.'/../domain/LogicSmsApprovedReport.php';

$pdo=db();
$lockName='ieosuia_sms_dlr_poller';
$lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);
if((int)$lock->fetchColumn()!==1){echo 'poller already running'.PHP_EOL;exit(0);}
register_shutdown_function(static function()use($pdo,$lockName):void{try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable){}});
$limit=max(1,min(200,(int)(getenv('SMS_DLR_POLL_BATCH_SIZE')?:100)));
$runId=OperationalLogger::startRun('sms-dlr-poller',gethostname(),['limit'=>$limit]);
$deadline=microtime(true)+50;
$approvedReport=['enabled'=>false];
$approvedSeed=__DIR__.'/../config/logicsms-approved-report-59b298114f2f4519.php';
if(is_file($approvedSeed)){
    try{$approvedReport=['enabled'=>true]+LogicSmsApprovedReport::run($pdo,require $approvedSeed,__DIR__.'/../storage/reconciliation/report-59b298114f2f4519.php');}
    catch(Throwable $e){$approvedReport=['enabled'=>true,'ok'=>false,'code'=>'APPROVED_REPORT_UNCONFIRMED','error_fingerprint'=>substr(hash('sha256',get_class($e).':'.$e->getMessage()),0,16)];OperationalLogger::log('error','sms-report-import','approved_report_unconfirmed','Approved report import was not fully confirmed',$approvedReport);}
}
// Inspect the exact stopped test-account reservation invariant without changing
// any financial row or overwriting the original import receipt.
if(($approvedReport['code']??null)==='REPORT_PARTIALLY_APPLIED'){
    try{
        require_once __DIR__.'/../domain/LogicSmsReportAccountingSnapshot.php';
        $accountingSnapshot=LogicSmsReportAccountingSnapshot::run($pdo,__DIR__.'/../storage/reconciliation/report-accounting-59b298114f2f4519.php');
        $approvedReport['accounting_snapshot']=['code'=>$accountingSnapshot['code']??'UNCONFIRMED','database_changes'=>false,'sms_submitted'=>false];
    }catch(Throwable $e){$approvedReport['accounting_snapshot']=['code'=>'SNAPSHOT_UNAVAILABLE','error_fingerprint'=>substr(hash('sha256',get_class($e).':'.$e->getMessage()),0,16)];}
}
// Resume only the exact preserved partial report with a separate receipt.
// The corrected accounting core retains all unattributed older reservations.
if(($approvedReport['code']??null)==='REPORT_PARTIALLY_APPLIED'&&($approvedReport['accounting_snapshot']['code']??null)==='REPORT_ACCOUNTING_SNAPSHOT'){
    try{$approvedReport['resume']=LogicSmsApprovedReport::run($pdo,require $approvedSeed,__DIR__.'/../storage/reconciliation/report-resume-59b298114f2f4519.php');}
    catch(Throwable $e){$approvedReport['resume']=['code'=>'REPORT_RESUME_UNCONFIRMED','error_fingerprint'=>substr(hash('sha256',get_class($e).':'.$e->getMessage()),0,16)];}
}
// Stored authenticated callbacks remain the first source of delivery evidence.
// Bounded status-only polling below also covers older unresolved submissions.
$accepted=SmsMessageState::acceptedSql('m');
$stmt=$pdo->prepare("SELECT DISTINCT m.* FROM sms_messages m JOIN sms_provider_events e ON e.provider='logicsms' AND (e.provider_message_id=m.provider_message_id OR e.provider_message_id=CONCAT('portal-message-',m.id)) WHERE m.provider='logicsms' AND $accepted AND e.processed_at IS NULL ORDER BY m.id LIMIT {$limit}");
$stmt->execute();$messages=$stmt->fetchAll();$updated=0;$failures=0;$checked=0;$normalizedStatuses=[];
foreach($messages as $message){
    if(microtime(true)>=$deadline)break;
    $checked++;
    try{LogicSmsDeliveryService::reconcile($pdo,(int)$message['id']);$updated++;}
    catch(Throwable $e){$failures++;OperationalLogger::log('error','sms-dlr-poller','postback_reconcile_failed','Stored delivery postback could not be reconciled',['sms_message_id'=>$message['id']]);}
}
$heldReceipts=LogicSmsStatusSync::runStored($pdo,20,$deadline);
$statusQueries=['enabled'=>false];
if(strtolower((string)env('SMS_STATUS_SYNC_ENABLED','true'))==='true'){
    $queryLimit=max(1,min(10,(int)env('SMS_STATUS_SYNC_BATCH_SIZE',2)));
    $statusQueries=['enabled'=>true]+LogicSmsStatusSync::run($pdo,new SmsService(),$queryLimit,min($deadline,microtime(true)+40));
}
$heartbeat=$pdo->prepare("INSERT INTO service_heartbeats(service_name,instance_id,metadata_json,last_seen_at) VALUES('sms-dlr-poller',?,?,NOW()) ON DUPLICATE KEY UPDATE instance_id=VALUES(instance_id),metadata_json=VALUES(metadata_json),last_seen_at=NOW()");$heartbeat->execute([gethostname(),json_encode(['checked'=>$checked,'updated'=>$updated,'failures'=>$failures,'source'=>'approved-report-stored-postbacks-and-status-query','approved_report'=>$approvedReport,'held_receipts'=>$heldReceipts,'status_queries'=>$statusQueries])]);echo 'checked '.$checked.', updated '.$updated.PHP_EOL;
OperationalLogger::finishRun($runId,$failures?'failed':'completed',$checked,$failures,"updated {$updated}");
// Incoming replies use a separate provider API; run under the same cron lock.
require_once __DIR__.'/../services/LogicSmsReplyService.php';
$replyRun=OperationalLogger::startRun('sms-reply-poller',gethostname(),[]);
try{
    $replies=LogicSmsReplyService::poll($pdo);
    OperationalLogger::finishRun($replyRun,$replies['unmatched']?'failed':'completed',$replies['checked'],$replies['unmatched'],json_encode($replies));
    echo 'replies '.json_encode($replies).PHP_EOL;
}catch(Throwable $e){OperationalLogger::finishRun($replyRun,'failed',0,1,'Reply query failed; verify two-way messaging and provider access');error_log('SMS reply poll failed: '.get_class($e));}
