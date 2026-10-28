<?php

require_once __DIR__ . '/WalletService.php';
require_once __DIR__ . '/../providers/ProviderFactory.php';
require_once __DIR__.'/SmsMessageState.php';
require_once __DIR__.'/SuppressionService.php';
require_once __DIR__.'/../services/OperationalLogger.php';

final class SmsDispatchService
{
    public static function claimBatch(int $limit, string $leaseToken): array
    {
        $pdo = db();
        $leaseSeconds = max(30, (int) env('SMS_LEASE_SECONDS', 300));
        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE sms_messages SET state='retry',lease_token=NULL,leased_at=NULL,last_error=IF(next_attempt_at='9999-12-31 23:59:59',CONCAT(LEFT(COALESCE(last_error,''),180),'; submission held pending reconciliation'),'Recovered unsubmitted stale worker lease'),next_attempt_at=IF(next_attempt_at='9999-12-31 23:59:59',next_attempt_at,NOW()),updated_at=NOW() WHERE state='processing' AND leased_at<DATE_SUB(NOW(), INTERVAL {$leaseSeconds} SECOND)");
            $accepted=SmsMessageState::acceptedSql('m');
            $sql = "SELECT m.id FROM sms_messages m JOIN sms_campaigns c ON c.id=m.campaign_id JOIN users u ON u.id=c.user_id
                    WHERE c.state IN ('queued','processing') AND m.state IN ('pending','retry')
                      AND u.is_active=1
                      AND NOT $accepted
                      AND (m.next_attempt_at IS NULL OR m.next_attempt_at<=NOW())
                      AND (m.leased_at IS NULL OR m.leased_at<DATE_SUB(NOW(), INTERVAL {$leaseSeconds} SECOND))
                    ORDER BY m.id LIMIT {$limit} FOR UPDATE SKIP LOCKED";
            $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) { $pdo->commit(); return []; }
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE sms_messages SET state='processing',lease_token=?,leased_at=NOW(),attempt_count=attempt_count+1,last_error=NULL,updated_at=NOW() WHERE id IN ({$marks})");
            $stmt->execute(array_merge([$leaseToken], $ids));
            $pdo->prepare("UPDATE sms_campaigns SET state='processing',started_at=COALESCE(started_at,NOW()),updated_at=NOW() WHERE id IN (SELECT DISTINCT campaign_id FROM sms_messages WHERE lease_token=?)")->execute([$leaseToken]);
            $select = $pdo->prepare("SELECT m.*,c.sender_id,c.state AS campaign_state FROM sms_messages m JOIN sms_campaigns c ON c.id=m.campaign_id WHERE m.lease_token=? ORDER BY m.id");
            $select->execute([$leaseToken]);
            $rows = $select->fetchAll();
            $pdo->commit();
            return $rows;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function renewLease(string $leaseToken): void
    {
        $stmt=db()->prepare("UPDATE sms_messages SET leased_at=NOW(),updated_at=NOW() WHERE state='processing' AND lease_token=?");
        $stmt->execute([$leaseToken]);
    }

    public static function releaseLease(string $leaseToken, string $reason='Worker stopped before dispatch completed'): void
    {
        $stmt=db()->prepare("UPDATE sms_messages SET state='retry',lease_token=NULL,leased_at=NULL,last_error=IF(next_attempt_at='9999-12-31 23:59:59',CONCAT(LEFT(COALESCE(last_error,''),180),'; submission held pending reconciliation'),?),next_attempt_at=IF(next_attempt_at='9999-12-31 23:59:59',next_attempt_at,NOW()),attempt_count=IF(next_attempt_at='9999-12-31 23:59:59',attempt_count,GREATEST(0,attempt_count-1)),updated_at=NOW() WHERE state='processing' AND lease_token=?");
        $stmt->execute([substr($reason,0,500),$leaseToken]);
    }

    public static function releaseMessageLease(int $messageId, string $leaseToken, string $reason): void
    {
        $stmt=db()->prepare("UPDATE sms_messages SET state='retry',lease_token=NULL,leased_at=NULL,last_error=IF(next_attempt_at='9999-12-31 23:59:59',CONCAT(LEFT(COALESCE(last_error,''),180),'; automatic resend held. ',?),?),next_attempt_at=IF(next_attempt_at='9999-12-31 23:59:59' OR attempt_count>=3,'9999-12-31 23:59:59',DATE_ADD(NOW(),INTERVAL 60 SECOND)),updated_at=NOW() WHERE id=? AND state='processing' AND lease_token=?");
        $stmt->execute([substr($reason,0,270),substr($reason,0,500),$messageId,$leaseToken]);
    }

    public static function process(array $message, SmsProvider $provider): void
    {
        // Preserve the existing audited operator recovery path. Normal workers
        // (including the pool) can never prepare an already-held intent.
        $message=self::prepare($message,$provider instanceof SmsRecoveryProvider);
        if($message===null)return;
        try { $result = $provider->send($message['destination'],$message['content'],$message['sender_id'] ?? null,'portal-message-'.$message['id']); }
        catch (Throwable $e) { $result = ['success'=>false,'uncertain'=>true,'error'=>'Provider request ended without a confirmed outcome']; }
        self::completeSubmission($message,$provider,$result);
    }

    /** Persist intent before starting a request, with no transaction held over HTTP. */
    public static function prepareSubmission(array $message): ?array
    {
        return self::prepare($message,false);
    }

    private static function prepare(array $message,bool $operatorRecovery): ?array
    {
        $pdo = db();
        $row=table('sms_messages')->where('id',$message['id'])->where('user_id',$message['user_id'])->where('campaign_id',$message['campaign_id'])->where('state','processing')->where('lease_token',$message['lease_token'])->first();
        if(!$row||SmsMessageState::accepted($row))return null;
        $recovery=$operatorRecovery&&(int)$row['attempt_count']===2&&($row['next_attempt_at']??null)==='9999-12-31 23:59:59'&&str_starts_with((string)($row['last_error']??''),'Submission started; awaiting provider acknowledgement;');
        if(($row['next_attempt_at']??null)==='9999-12-31 23:59:59'&&!$recovery)return null;
        $message=array_merge($message,$row);
        $current = table('sms_campaigns')->where('id',$message['campaign_id'])->first();
        if (!$current || $current['state'] === 'cancelled') {
            self::markSkipped($message, 'Campaign cancelled'); return null;
        }
        $owner=table('users')->where('id',$message['user_id'])->first();
        if(!$owner||empty($owner['is_active'])){self::markSkipped($message,'Campaign owner is not authorized to send');return null;}
        if (SuppressionService::contains((int)$message['user_id'],(string)$message['destination'])) { self::markSkipped($message, 'Recipient opted out'); return null; }

        // Commit the no-resend marker BEFORE any network side effect. A killed process,
        // timeout or failed local commit must not convert an unknown submission to retry.
        $accepted=SmsMessageState::acceptedSql();
        $freshIntent=$recovery?"next_attempt_at='9999-12-31 23:59:59' AND attempt_count=2 AND last_error LIKE 'Submission started; awaiting provider acknowledgement;%'":"(next_attempt_at IS NULL OR next_attempt_at<>'9999-12-31 23:59:59')";
        $intent=$pdo->prepare("UPDATE sms_messages SET next_attempt_at='9999-12-31 23:59:59',last_error='Submission started; awaiting provider acknowledgement',updated_at=NOW() WHERE id=? AND state='processing' AND lease_token=? AND $freshIntent AND NOT $accepted");
        $intent->execute([$message['id'],$message['lease_token']]);
        if ($intent->rowCount()!==1) return null;
        $message['sender_id']=$current['sender_id']??null;
        return $message;
    }

    /** Serialize each result through the existing wallet and message transaction. */
    public static function completeSubmission(array $message, SmsProvider $provider, array $result): void
    {
        $pdo=db();
        if(isset($result['diagnostic']))OperationalLogger::log(!empty($result['success'])?'info':'warning','sms-provider','acknowledgement',!empty($result['success'])?'Provider acceptance verified':'Provider outcome: '.($result['error_code']??'UNKNOWN_OUTCOME'),['campaign_id'=>(int)$message['campaign_id'],'sms_message_id'=>(int)$message['id'],'user_id'=>(int)$message['user_id'],'acknowledgement'=>$result['diagnostic']]);
        if (!empty($result['uncertain'])) {
            $code=preg_replace('/[^A-Z0-9_]/','',(string)($result['error_code']??'UNKNOWN_OUTCOME'));
            self::releaseMessageLease((int)$message['id'],$message['lease_token'],'Provider acknowledgement could not be verified ['.$code.']. Support will check this before any resend.');
            return;
        }
        if (!empty($result['success'])) {
            // Keep the acknowledgement outside the settlement transaction so a failed
            // unique-ID write cannot erase the evidence needed for reconciliation.
            $ack=$pdo->prepare('UPDATE sms_messages SET last_error=? WHERE id=? AND state=\'processing\' AND lease_token=?');
            $ack->execute(['Provider accepted reference '.substr((string)$result['message_id'],0,120).'; awaiting local settlement',$message['id'],$message['lease_token']]);
        }

        $pdo->beginTransaction();
        try {
            $locked = $pdo->prepare('SELECT * FROM sms_messages WHERE id=? FOR UPDATE');
            $locked->execute([$message['id']]);
            $row = $locked->fetch();
            if (!$row || $row['state'] !== 'processing' || $row['lease_token'] !== $message['lease_token']) { $pdo->rollBack(); return; }
            if (!empty($result['success'])) {
                WalletService::settleMessage($pdo,$row,(float)$row['estimated_charge']);
                $stmt=$pdo->prepare("UPDATE sms_messages SET state='sent',provider=?,provider_message_id=?,actual_charge=estimated_charge,sent_at=NOW(),lease_token=NULL,leased_at=NULL,last_error=NULL,updated_at=NOW() WHERE id=?");
                $stmt->execute([$provider->name(),$result['message_id'],$row['id']]);
            } else {
                $max=(int)env('SMS_MAX_ATTEMPTS',3);
                $retry=($result['retryable']??true) && (int)$row['attempt_count']<$max;
                $state=$retry?'retry':'failed';
                $next=$retry?date('Y-m-d H:i:s',time()+min(900,30*(2**max(0,(int)$row['attempt_count']-1)))):null;
                $stmt=$pdo->prepare('UPDATE sms_messages SET state=?,next_attempt_at=?,failed_at=IF(?=\'failed\',NOW(),failed_at),last_error=?,lease_token=NULL,leased_at=NULL,updated_at=NOW() WHERE id=?');
                $stmt->execute([$state,$next,$state,substr((string)($result['error']??'Provider rejected message'),0,500),$row['id']]);
                if (!$retry) WalletService::release($pdo,(int)$row['user_id'],(int)$row['campaign_id'],(float)$row['estimated_charge'],"message-{$row['id']}-generation-".(int)($row['retry_generation']??0).'-rejected');
            }
            self::refreshCampaign($pdo,(int)$row['campaign_id']);
            $pdo->commit();
            // Delivery reconciliation must never roll back accepted submission or
            // cause the worker to submit it again if a report cannot be applied.
            if(!empty($result['success'])){
                try{require_once __DIR__.'/LogicSmsDeliveryService.php';LogicSmsDeliveryService::reconcile($pdo,(int)$row['id']);}
                catch(Throwable $e){error_log('Deferred delivery report requires reconciliation for message '.(int)$row['id']);}
            }
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    /** Only for a freshly prepared request which has NEVER entered a transport. */
    public static function releaseUnstartedSubmission(array $message):void
    {
        $q=db()->prepare("UPDATE sms_messages SET state='retry',lease_token=NULL,leased_at=NULL,next_attempt_at=NOW(),attempt_count=GREATEST(0,attempt_count-1),last_error='Worker deadline reached before request started',updated_at=NOW() WHERE id=? AND state='processing' AND lease_token=? AND next_attempt_at='9999-12-31 23:59:59' AND last_error='Submission started; awaiting provider acknowledgement' AND sent_at IS NULL AND actual_charge=0");
        $q->execute([$message['id'],$message['lease_token']]);
    }

    private static function markSkipped(array $message,string $reason):void
    {
        $pdo=db();$pdo->beginTransaction();
        try{$stmt=$pdo->prepare("UPDATE sms_messages SET state='skipped',last_error=?,lease_token=NULL,leased_at=NULL,updated_at=NOW() WHERE id=? AND state='processing' AND lease_token=? AND (next_attempt_at IS NULL OR next_attempt_at<>'9999-12-31 23:59:59')");$stmt->execute([$reason,$message['id'],$message['lease_token']]);if($stmt->rowCount()!==1){$pdo->rollBack();return;}WalletService::release($pdo,(int)$message['user_id'],(int)$message['campaign_id'],(float)$message['estimated_charge'],"message-{$message['id']}-skipped");self::refreshCampaign($pdo,(int)$message['campaign_id']);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function refreshCampaign(PDO $pdo,int $campaignId):void
    {
        $accepted=SmsMessageState::acceptedSql();$networkFailure=SmsMessageState::networkFailureSql();$submissionFailure=SmsMessageState::submissionFailureSql();
        $stmt=$pdo->prepare("SELECT COUNT(*) total,SUM(state IN ('pending','queued','retry')) queued,SUM(state='processing') processing,SUM($accepted) sent,SUM(state='delivered') delivered,SUM($networkFailure) failed,SUM($submissionFailure) failed_submissions,SUM(state='skipped') skipped,COALESCE(SUM(actual_charge),0) actual FROM sms_messages WHERE campaign_id=?");$stmt->execute([$campaignId]);$x=$stmt->fetch();
        $terminal=(int)$x['sent']+(int)$x['failed_submissions']+(int)$x['skipped'];$done=$terminal===(int)$x['total'];$state=$done?((int)$x['failed_submissions']>0?'partially_failed':'completed'):'processing';
        $u=$pdo->prepare("UPDATE sms_campaigns SET state=CASE WHEN state IN ('cancelled','draft','scheduled') THEN state ELSE ? END,queued_count=?,processing_count=?,sent_count=?,delivered_count=?,failed_count=?,skipped_count=?,actual_cost=?,completed_at=IF(?,COALESCE(completed_at,NOW()),completed_at),updated_at=NOW() WHERE id=?");
        $u->execute([$state,$x['queued'],$x['processing'],$x['sent'],$x['delivered'],$x['failed'],$x['skipped'],$x['actual'],$done?1:0,$campaignId]);
    }
}
