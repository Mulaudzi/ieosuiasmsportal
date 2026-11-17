<?php
require_once __DIR__.'/LogicSmsDeliveryStatus.php';
require_once __DIR__.'/SmsMessageState.php';
require_once __DIR__.'/SmsDispatchService.php';

/** Apply authenticated delivery evidence only. Never sends SMS or changes a wallet. */
final class LogicSmsDeliveryService
{
    public static function retainUnmatched(PDO $pdo,string $reference,string $status,array $payload,string $eventId):void
    {
        try{
            $q=$pdo->prepare('INSERT INTO sms_provider_events(provider,provider_event_id,provider_message_id,event_type,payload_json,created_at) VALUES(?,?,?,?,?,NOW())');
            $q->execute(['logicsms',$eventId,$reference,'delivery.'.LogicSmsDeliveryStatus::normalize($status),json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        }catch(PDOException $e){
            if(!in_array((string)$e->getCode(),['23000','23505'],true))throw $e;
            $q=$pdo->prepare("SELECT provider_message_id FROM sms_provider_events WHERE provider='logicsms' AND provider_event_id=?");$q->execute([$eventId]);
            if($q->fetchColumn()!==$reference)throw new DomainException('Provider event reference mismatch');
        }
    }

    public static function record(PDO $pdo,int $messageId,string $providerId,string $status,array $payload,string $eventId):array
    {
        $pdo->beginTransaction();
        try{
            // Consistent message-before-event lock order also covers immediate postbacks.
            $q=$pdo->prepare('SELECT * FROM sms_messages WHERE id=? FOR UPDATE');$q->execute([$messageId]);$message=$q->fetch(PDO::FETCH_ASSOC);
            if(!$message)throw new RuntimeException('Matched SMS message disappeared');
            if(!empty($message['provider'])&&$message['provider']!=='logicsms')throw new DomainException('Provider reference mismatch');
            if($providerId!==''&&!empty($message['provider_message_id'])&&$providerId!==$message['provider_message_id'])throw new DomainException('Provider reference mismatch');
            $reference=$message['provider_message_id']?:($providerId?:'portal-message-'.$messageId);
            $q=$pdo->prepare("SELECT * FROM sms_provider_events WHERE provider='logicsms' AND provider_event_id=? FOR UPDATE");$q->execute([$eventId]);$event=$q->fetch(PDO::FETCH_ASSOC);
            if($event){
                if(!in_array($event['provider_message_id'],[$reference,'portal-message-'.$messageId],true))throw new DomainException('Provider event reference mismatch');
                if($event['processed_at']!==null){$pdo->commit();return ['received'=>true,'matched'=>true,'duplicate'=>true];}
            }else{
                $q=$pdo->prepare('INSERT INTO sms_provider_events(provider,provider_event_id,provider_message_id,event_type,payload_json,created_at) VALUES(?,?,?,?,?,NOW())');
                $q->execute(['logicsms',$eventId,$reference,'delivery.'.LogicSmsDeliveryStatus::normalize($status),json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            }
            // A postback can outrun the submission transaction. Persist it, but do
            // not change a worker's processing state or bypass its normal settlement.
            if(!SmsMessageState::accepted($message)){
                $pdo->commit();return ['received'=>true,'matched'=>true,'deferred'=>true];
            }
            self::apply($pdo,$message,$status);
            self::processed($pdo,$eventId);
            SmsDispatchService::refreshCampaign($pdo,(int)$message['campaign_id']);
            $pdo->commit();return ['received'=>true,'matched'=>true];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    /** Called only after successful submission has committed, never as a resend. */
    public static function reconcile(PDO $pdo,int $messageId):void
    {
        $pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT * FROM sms_messages WHERE id=? FOR UPDATE');$q->execute([$messageId]);$message=$q->fetch(PDO::FETCH_ASSOC);
            if(!$message||($message['provider']??'')!=='logicsms'||!SmsMessageState::accepted($message)){$pdo->commit();return;}
            $q=$pdo->prepare("SELECT * FROM sms_provider_events WHERE provider='logicsms' AND processed_at IS NULL AND provider_message_id IN (?,?) ORDER BY created_at,id FOR UPDATE");
            $q->execute([$message['provider_message_id'],'portal-message-'.$messageId]);
            $events=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!$events){$pdo->commit();return;}
            foreach($events as $event){
                $payload=json_decode($event['payload_json'],true,512,JSON_THROW_ON_ERROR);
                $status=(string)($payload['Status']??$payload['status']??$payload['dlr_status']??'');
                self::apply($pdo,$message,$status);self::processed($pdo,$event['provider_event_id']);
            }
            SmsDispatchService::refreshCampaign($pdo,(int)$message['campaign_id']);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private static function apply(PDO $pdo,array &$message,string $providerStatus):void
    {
        $state=LogicSmsDeliveryStatus::normalize($providerStatus);
        $blocked=LogicSmsDeliveryStatus::isBlocked($providerStatus);
        // A specific BLOCKED result can refine a generic failure without changing
        // counts or charges. A recorded block cannot be reversed automatically.
        $refinesFailure=$message['state']==='failed'&&$blocked&&!LogicSmsDeliveryStatus::isBlocked($message['last_error']??null);
        if(!$refinesFailure&&!LogicSmsDeliveryStatus::shouldApply($message['state'],$state,$message['last_error']??null))return;
        $fields=['state=?','updated_at=NOW()'];$values=[$state];
        if($state==='delivered'){$fields[]='delivered_at=COALESCE(delivered_at,NOW())';$fields[]='failed_at=NULL';$fields[]='last_error=NULL';}
        elseif($state==='failed'){$fields[]='failed_at=COALESCE(failed_at,NOW())';$fields[]='last_error=?';$values[]='LogicSMS delivery status: '.$providerStatus;}
        elseif($state==='dlr_unavailable'){$fields[]='last_error=?';$values[]='LogicSMS delivery status: '.$providerStatus;}
        else $fields[]='last_error=NULL';
        $values[]=$message['id'];$pdo->prepare('UPDATE sms_messages SET '.implode(',',$fields).' WHERE id=?')->execute($values);
        $message['state']=$state;
        if($state==='failed'||$state==='dlr_unavailable')$message['last_error']='LogicSMS delivery status: '.$providerStatus;
        else $message['last_error']=null;
    }
    private static function processed(PDO $pdo,string $eventId):void
    { $pdo->prepare("UPDATE sms_provider_events SET processed_at=NOW() WHERE provider='logicsms' AND provider_event_id=?")->execute([$eventId]); }
}
