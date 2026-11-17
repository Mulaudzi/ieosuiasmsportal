<?php
declare(strict_types=1);

/** Admin-only read model. No provider, sending, settlement or notification calls. */
final class SmsDeliveryHealth
{
    public static function report(PDO $pdo, bool $webhookAuthConfigured, ?int $now = null): array
    {
        $now ??= time(); $zone = new DateTimeZone('Africa/Johannesburg');
        $at = static fn(int $stamp):string => (new DateTimeImmutable('@'.$stamp))->setTimezone($zone)->format('Y-m-d H:i:s');
        $q=$pdo->prepare("SELECT COUNT(*) waiting,COALESCE(SUM(last_dlr_checked_at IS NULL),0) unqueried,COALESCE(SUM(COALESCE(sent_at,created_at)<?),0) older_than_day FROM sms_messages WHERE provider='logicsms' AND state IN ('sent','dlr_unavailable')");
        $q->execute([$at($now-86400)]); $messages=$q->fetch(PDO::FETCH_ASSOC);
        $callbacks=$pdo->query("SELECT MAX(created_at) last_received_at,COALESCE(SUM(processed_at IS NULL),0) unprocessed FROM sms_provider_events WHERE provider='logicsms' AND event_type IN ('delivery.sent','delivery.delivered','delivery.failed','delivery.dlr_unavailable')")->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare("SELECT COALESCE(SUM(message='Read-only provider status query: MESSAGE_NOT_FOUND'),0) missing,COALESCE(SUM(message IN ('Read-only provider status query: AUTHENTICATION_ERROR','Read-only provider status query: HTTP_ERROR','Read-only provider status query: TRANSPORT_ERROR','Read-only provider status query: INVALID_RESPONSE','Read-only provider status query: MISSING_REFERENCE') AND created_at>=?),0) recent_gateway_errors FROM operational_logs WHERE service='sms-status-sync' AND event='status_lookup' AND level='warning' AND created_at>=?");
        $q->execute([$at($now-300),$at($now-86400)]); $queries=$q->fetch(PDO::FETCH_ASSOC);
        $last=$callbacks['last_received_at'];
        $callbackRecent=is_string($last)&&(new DateTimeImmutable($last,$zone))->getTimestamp()>=$now-86400;
        $problems=[];
        if(!$webhookAuthConfigured)$problems[]='CALLBACK_AUTH_NOT_CONFIGURED';
        if((int)$messages['waiting']>0&&!$callbackRecent)$problems[]='NO_RECENT_DELIVERY_CALLBACKS';
        if((int)$messages['older_than_day']>0)$problems[]='DELIVERY_CONFIRMATIONS_OVERDUE';
        if((int)$queries['missing']>0)$problems[]='MESSAGE_LOOKUPS_NEED_REVIEW';
        if((int)$queries['recent_gateway_errors']>0)$problems[]='STATUS_GATEWAY_COOLDOWN';
        return ['available'=>true,'status'=>$problems ? 'attention' : 'healthy',
            'awaiting_confirmation'=>(int)$messages['waiting'],'not_yet_queried'=>(int)$messages['unqueried'],
            'older_than_day'=>(int)$messages['older_than_day'],'callback_auth_configured'=>$webhookAuthConfigured,
            'last_callback_at'=>$last,'unprocessed_callbacks'=>(int)$callbacks['unprocessed'],
            'missing_lookups_last_day'=>(int)$queries['missing'],'gateway_cooldown'=>(int)$queries['recent_gateway_errors']>0,
            'problems'=>$problems,'observed_at'=>$at($now)];
    }
}
