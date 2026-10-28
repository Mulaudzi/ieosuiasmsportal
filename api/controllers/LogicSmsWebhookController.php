<?php

require_once __DIR__.'/../domain/LogicSmsDeliveryStatus.php';
require_once __DIR__.'/../domain/SmsDispatchService.php';
require_once __DIR__.'/../domain/LogicSmsDeliveryService.php';
require_once __DIR__.'/../services/OperationalLogger.php';

final class LogicSmsWebhookController
{
    public function delivery(array $params=[]):void
    {
        $configured=array_values(array_filter(array_unique([trim((string)env('LOGICSMS_WEBHOOK_SECRET','')),trim((string)env('DLR_WEBHOOK_SECRET',''))]),static fn($secret)=>strlen($secret)>=32));
        if(!$configured){Response::error('Webhook is not configured',503);return;}
        $supplied=(string)($_SERVER['HTTP_X_WEBHOOK_SECRET']??Request::query('token',Request::query('secret',$params['token']??'')));
        $authenticated=false;foreach($configured as $secret)$authenticated=hash_equals($secret,$supplied)||$authenticated;
        if($supplied===''||!$authenticated){OperationalLogger::log('warning','logicsms-webhook','dlr_auth_rejected','Delivery callback authentication failed');Response::error('Invalid webhook authentication',403);return;}

        $payload=Request::all();
        unset($payload['token'],$payload['secret']);
        $providerId=trim((string)($payload['Id']??$payload['id']??$payload['MessageId']??$payload['messageId']??$payload['message_id']??$payload['external_id']??''));
        $unique=trim((string)($payload['Unique']??$payload['unique']??$payload['reference']??''));
        $providerStatus=trim((string)($payload['Status']??$payload['status']??$payload['dlr_status']??''));
        $state=LogicSmsDeliveryStatus::normalize($providerStatus);
        if(($providerId===''&&$unique==='')||$state==='unknown'){OperationalLogger::log('warning','logicsms-webhook','dlr_payload_rejected','Delivery callback has no recognized message reference or status');Response::error('Unsupported delivery payload',422);return;}

        $message=null;
        if($providerId!=='')$message=table('sms_messages')->where('provider','logicsms')->where('provider_message_id',$providerId)->first();
        if(!$message&&preg_match('/^portal-message-(\d+)$/',$unique,$match))$message=table('sms_messages')->where('id',(int)$match[1])->first();
        if($message&&preg_match('/^portal-message-(\d+)$/',$unique,$match)&&(int)$message['id']!==(int)$match[1]){Response::error('Conflicting message references',422);return;}
        $canonical=$payload;ksort($canonical);$raw=json_encode($canonical,JSON_THROW_ON_ERROR);
        $eventId=trim((string)($payload['EventId']??$payload['event_id']??''));
        if($eventId==='')$eventId=hash('sha256',$providerId.'|'.$unique.'|'.$providerStatus.'|'.$raw);
        if(strlen($eventId)>120)$eventId=hash('sha256',$eventId);
        if(!$message){
            try{LogicSmsDeliveryService::retainUnmatched(db(),$providerId?:$unique,$providerStatus,$payload,$eventId);}
            catch(DomainException $e){Response::error('Conflicting provider delivery reference',422);return;}
            OperationalLogger::log('warning','logicsms-webhook','unmatched_dlr','Delivery report retained for message reconciliation',['provider_message_id'=>$providerId]);Response::success(['received'=>true,'matched'=>false]);return;
        }
        $pdo=db();
        try{
            $result=LogicSmsDeliveryService::record($pdo,(int)$message['id'],$providerId,$providerStatus,$payload,$eventId);
            OperationalLogger::log('info','logicsms-webhook','dlr_processed','Delivery report processed',['campaign_id'=>$message['campaign_id'],'sms_message_id'=>$message['id'],'state'=>$state]);
            Response::success($result);
        }catch(DomainException $e){Response::error('Conflicting provider delivery reference',422);}
        catch(Throwable $e){OperationalLogger::log('error','logicsms-webhook','dlr_failed',$e->getMessage(),['campaign_id'=>$message['campaign_id']??null,'sms_message_id'=>$message['id']??null]);throw $e;}
    }
}
