<?php

require_once __DIR__.'/../domain/LogicSmsDeliveryStatus.php';
require_once __DIR__.'/../domain/SmsDispatchService.php';
require_once __DIR__.'/../services/OperationalLogger.php';

final class LogicSmsWebhookController
{
    public function delivery(array $params=[]):void
    {
        $configured=array_values(array_filter(array_unique([trim((string)env('LOGICSMS_WEBHOOK_SECRET','')),trim((string)env('DLR_WEBHOOK_SECRET',''))]),static fn($secret)=>strlen($secret)>=32));
        if(!$configured){Response::error('Webhook is not configured',503);return;}
        $supplied=(string)($_SERVER['HTTP_X_WEBHOOK_SECRET']??Request::query('token',Request::query('secret',$params['token']??'')));
        $authenticated=false;foreach($configured as $secret)$authenticated=hash_equals($secret,$supplied)||$authenticated;
        if($supplied===''||!$authenticated){Response::error('Invalid webhook authentication',403);return;}

        $payload=Request::all();
        unset($payload['token']);
        $providerId=trim((string)($payload['Id']??$payload['id']??$payload['MessageId']??$payload['messageId']??$payload['message_id']??$payload['external_id']??''));
        $unique=trim((string)($payload['Unique']??$payload['unique']??$payload['reference']??''));
        $providerStatus=trim((string)($payload['Status']??$payload['status']??$payload['dlr_status']??''));
        $state=LogicSmsDeliveryStatus::normalize($providerStatus);
        if(($providerId===''&&$unique==='')||$state==='unknown'){Response::error('Unsupported delivery payload',422);return;}

        $message=null;
        if($providerId!=='')$message=table('sms_messages')->where('provider','logicsms')->where('provider_message_id',$providerId)->first();
        if(!$message&&preg_match('/^portal-message-(\d+)$/',$unique,$match))$message=table('sms_messages')->where('id',(int)$match[1])->first();
        if(!$message){OperationalLogger::log('warning','logicsms-webhook','unmatched_dlr','Delivery report did not match a local message',['provider_message_id'=>$providerId]);Response::success(['received'=>true,'matched'=>false]);return;}

        $raw=file_get_contents('php://input')?:json_encode($payload);
        $eventId=(string)($payload['EventId']??$payload['event_id']??hash('sha256',$providerId.'|'.$unique.'|'.$providerStatus.'|'.$raw));
        $pdo=db();$pdo->beginTransaction();
        try{
            $duplicate=false;
            try{
                $insert=$pdo->prepare('INSERT INTO sms_provider_events(provider,provider_event_id,provider_message_id,event_type,payload_json,created_at) VALUES(?,?,?,?,?,NOW())');
                $insert->execute(['logicsms',$eventId,$providerId?:$message['provider_message_id'],'delivery.'.$state,json_encode($payload,JSON_UNESCAPED_SLASHES)]);
            }catch(PDOException $e){
                if($e->getCode()!=='23000')throw $e;
                $duplicate=true;
                $existing=$pdo->prepare("SELECT processed_at FROM sms_provider_events WHERE provider='logicsms' AND provider_event_id=? FOR UPDATE");
                $existing->execute([$eventId]);
                $existingEvent=$existing->fetch(PDO::FETCH_ASSOC);
                if(!$existingEvent)throw new RuntimeException('Duplicate provider event could not be loaded');
                if($existingEvent&&$existingEvent['processed_at']!==null){$pdo->commit();Response::success(['received'=>true,'duplicate'=>true]);return;}
            }

            $locked=$pdo->prepare('SELECT * FROM sms_messages WHERE id=? FOR UPDATE');
            $locked->execute([$message['id']]);
            $message=$locked->fetch();
            if(!$message)throw new RuntimeException('Matched SMS message disappeared');

            $terminal=in_array($message['state'],['delivered','failed'],true);
            if(!$terminal||$state==='delivered'){
                $fields=['state=?','updated_at=NOW()'];$values=[$state];
                if($state==='delivered'){$fields[]='delivered_at=NOW()';$fields[]='last_error=NULL';}
                if($state==='failed'){$fields[]='failed_at=NOW()';$fields[]='last_error=?';$values[]='LogicSMS delivery status: '.$providerStatus;}
                $values[]=$message['id'];
                $pdo->prepare('UPDATE sms_messages SET '.implode(',',$fields).' WHERE id=?')->execute($values);
            }
            SmsDispatchService::refreshCampaign($pdo,(int)$message['campaign_id']);
            $pdo->prepare("UPDATE sms_provider_events SET processed_at=NOW() WHERE provider='logicsms' AND provider_event_id=?")->execute([$eventId]);
            $pdo->commit();
            OperationalLogger::log('info','logicsms-webhook','dlr_processed','Delivery report processed',['campaign_id'=>$message['campaign_id'],'sms_message_id'=>$message['id'],'state'=>$state]);
            Response::success(['received'=>true,'matched'=>true,'recovered'=>$duplicate]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();OperationalLogger::log('error','logicsms-webhook','dlr_failed',$e->getMessage(),['campaign_id'=>$message['campaign_id']??null,'sms_message_id'=>$message['id']??null]);throw $e;}
    }
}
