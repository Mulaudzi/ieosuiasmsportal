<?php

require_once __DIR__ . '/WalletService.php';
require_once __DIR__ . '/../providers/ProviderFactory.php';

final class SmsDispatchService
{
    public static function claimBatch(int $limit, string $leaseToken): array
    {
        $pdo = db();
        $leaseSeconds = max(30, (int) env('SMS_LEASE_SECONDS', 300));
        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE sms_messages SET state='retry',lease_token=NULL,leased_at=NULL,next_attempt_at=NOW(),last_error='Recovered stale worker lease',updated_at=NOW() WHERE state='processing' AND leased_at<DATE_SUB(NOW(), INTERVAL {$leaseSeconds} SECOND)");
            $sql = "SELECT m.id FROM sms_messages m JOIN sms_campaigns c ON c.id=m.campaign_id
                    WHERE c.state IN ('queued','processing') AND m.state IN ('pending','retry')
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
        $stmt=db()->prepare("UPDATE sms_messages SET state='retry',lease_token=NULL,leased_at=NULL,next_attempt_at=NOW(),last_error=?,updated_at=NOW() WHERE state='processing' AND lease_token=?");
        $stmt->execute([substr($reason,0,500),$leaseToken]);
    }

    public static function releaseMessageLease(int $messageId, string $leaseToken, string $reason): void
    {
        $stmt=db()->prepare("UPDATE sms_messages SET state='retry',lease_token=NULL,leased_at=NULL,next_attempt_at=NOW(),last_error=?,updated_at=NOW() WHERE id=? AND state='processing' AND lease_token=?");
        $stmt->execute([substr($reason,0,500),$messageId,$leaseToken]);
    }

    public static function process(array $message, SmsProvider $provider): void
    {
        $pdo = db();
        $current = table('sms_campaigns')->where('id',$message['campaign_id'])->first();
        if (!$current || $current['state'] === 'cancelled') {
            self::markSkipped($message, 'Campaign cancelled'); return;
        }
        $optedOut = table('opt_outs')->where('user_id',$message['user_id'])->where('channel','sms')->where('recipient',$message['destination'])->first();
        if ($optedOut) { self::markSkipped($message, 'Recipient opted out'); return; }

        try { $result = $provider->send($message['destination'],$message['content'],$message['sender_id'] ?? null,'portal-message-'.$message['id']); }
        catch (Throwable $e) { $result = ['success'=>false,'error'=>$e->getMessage()]; }

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
                $retry=(int)$row['attempt_count']<$max;
                $state=$retry?'retry':'failed';
                $next=$retry?date('Y-m-d H:i:s',time()+min(900,30*(2**max(0,(int)$row['attempt_count']-1)))):null;
                $stmt=$pdo->prepare('UPDATE sms_messages SET state=?,next_attempt_at=?,failed_at=IF(?=\'failed\',NOW(),failed_at),last_error=?,lease_token=NULL,leased_at=NULL,updated_at=NOW() WHERE id=?');
                $stmt->execute([$state,$next,$state,substr((string)($result['error']??'Provider rejected message'),0,500),$row['id']]);
                if (!$retry) WalletService::release($pdo,(int)$row['user_id'],(int)$row['campaign_id'],(float)$row['estimated_charge'],"message-{$row['id']}-generation-".(int)($row['retry_generation']??0).'-rejected');
            }
            self::refreshCampaign($pdo,(int)$row['campaign_id']);
            $pdo->commit();
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private static function markSkipped(array $message,string $reason):void
    {
        $pdo=db();$pdo->beginTransaction();
        try{$stmt=$pdo->prepare("UPDATE sms_messages SET state='skipped',last_error=?,lease_token=NULL,leased_at=NULL,updated_at=NOW() WHERE id=? AND state='processing'");$stmt->execute([$reason,$message['id']]);WalletService::release($pdo,(int)$message['user_id'],(int)$message['campaign_id'],(float)$message['estimated_charge'],"message-{$message['id']}-skipped");self::refreshCampaign($pdo,(int)$message['campaign_id']);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function refreshCampaign(PDO $pdo,int $campaignId):void
    {
        $stmt=$pdo->prepare("SELECT COUNT(*) total,SUM(state='pending' OR state='retry') queued,SUM(state='processing') processing,SUM(state IN ('sent','delivered')) sent,SUM(state='delivered') delivered,SUM(state='failed') failed,SUM(state='skipped') skipped,COALESCE(SUM(actual_charge),0) actual FROM sms_messages WHERE campaign_id=?");$stmt->execute([$campaignId]);$x=$stmt->fetch();
        $terminal=(int)$x['sent']+(int)$x['failed']+(int)$x['skipped'];$done=$terminal===(int)$x['total'];$state=$done?((int)$x['failed']>0?'partially_failed':'completed'):'processing';
        $u=$pdo->prepare('UPDATE sms_campaigns SET state=?,queued_count=?,processing_count=?,sent_count=?,delivered_count=?,failed_count=?,skipped_count=?,actual_cost=?,completed_at=IF(?,NOW(),completed_at),updated_at=NOW() WHERE id=?');
        $u->execute([$state,$x['queued'],$x['processing'],$x['sent'],$x['delivered'],$x['failed'],$x['skipped'],$x['actual'],$done?1:0,$campaignId]);
    }
}
