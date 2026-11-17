<?php
require_once __DIR__.'/EmailSnsSignature.php';
require_once __DIR__.'/EmailCampaignService.php';

final class EmailSesFeedback
{
    public function __construct(private PDO $pdo,private ?EmailSnsSignature $signatures=null,private mixed $confirm=null) {}
    public function receive(string $raw): array
    {
        if(env('EMAIL_SES_FEEDBACK_ENABLED','false')!=='true')throw new EmailCampaignException('SES feedback is not enabled.',503);
        $m=($this->signatures??new EmailSnsSignature())->verify($raw);$c=EmailSnsSignature::configuration();
        if($m['Type']!=='Notification'){
            if($m['Type']==='SubscriptionConfirmation'&&env('EMAIL_SES_AUTO_CONFIRM_SUBSCRIPTIONS','false')==='true'){
                $url=EmailSnsSignature::confirmationUrl($m);$reply=$this->confirm?($this->confirm)($url):EmailSnsSignature::fetch($url,32768);
                if(!is_string($reply)||!preg_match('~<SubscriptionArn>('.preg_quote($c['topic'],'~').':[a-f0-9-]{36})</SubscriptionArn>~',$reply))throw new EmailCampaignException('SNS subscription confirmation needs operator review.',503);
                return ['subscription_confirmed'=>true];
            }
            return ['subscription_confirmed'=>false,'operator_action_required'=>true];
        }
        try{$e=json_decode($m['Message'],true,16,JSON_THROW_ON_ERROR);}catch(JsonException){throw new EmailCampaignException('Invalid SES event.',400);}
        if(!is_array($e))throw new EmailCampaignException('Invalid SES event.',400);
        $type=$e['eventType']??$e['notificationType']??'';
        if(!in_array($type,['Send','Delivery','Bounce','Complaint','Reject'],true))return ['ignored'=>true,'reason'=>'unsupported_event_type'];
        $mail=$e['mail']??[];$tags=$mail['tags']??[];$config=(string)env('EMAIL_SES_CONFIGURATION_SET','');$identity=(string)env('EMAIL_SES_IDENTITY','campaigns.ieosuia.com');
        $rid=$tags['ieosuia_recipient_id'][0]??'';$cid=$tags['ieosuia_campaign_id'][0]??'';
        if(($mail['sendingAccountId']??'')!==$c['account']||($mail['sourceArn']??'')!=='arn:aws:ses:'.$c['region'].':'.$c['account'].':identity/'.$identity||$config===''||($tags['ses:configuration-set']??[])!==[$config]||($tags['ieosuia_channel']??[])!==['email_campaign']||!is_string($rid)||!preg_match('/^[a-f0-9]{32}$/D',$rid)||!is_string($cid)||!preg_match('/^[a-f0-9]{32}$/D',$cid)||($tags['ieosuia_recipient_id']??[])!==[$rid]||($tags['ieosuia_campaign_id']??[])!==[$cid]||($mail['headersTruncated']??false)!==false)throw new EmailCampaignException('SES event scope is not trusted.',401);
        $original=[];foreach($mail['headers']??[] as $h)if(is_array($h)&&strtolower((string)($h['name']??''))==='message-id')$original[]=$h['value']??null;
        if(count($original)!==1||!is_string($original[0]))throw new EmailCampaignException('Original email reference is missing or ambiguous.',409);
        $q=$this->pdo->prepare('SELECT email,message_id,campaign_id FROM email_campaign_recipients WHERE id=?');$q->execute([$rid]);$r=$q->fetch(PDO::FETCH_ASSOC);
        $provider=$mail['messageId']??'';
        if(!$r||$r['campaign_id']!==$cid||!hash_equals($r['message_id'],$original[0])||($mail['destination']??[])!==[$r['email']]||!is_string($provider)||!preg_match('/^[A-Za-z0-9._-]{1,200}$/D',$provider))throw new EmailCampaignException('SES event does not match the original email.',409);
        $target=match($type){'Send'=>'accepted','Delivery'=>'delivered','Complaint'=>'complaint','Reject'=>'provider_rejected','Bounce'=>(($e['bounce']['bounceType']??'')==='Permanent'?'hard_bounce':'soft_bounce')};
        if(!in_array($type,['Send','Reject'],true)){
            $recipients=match($type){'Delivery'=>$e['delivery']['recipients']??[],'Complaint'=>array_column($e['complaint']['complainedRecipients']??[],'emailAddress'),'Bounce'=>array_column($e['bounce']['bouncedRecipients']??[],'emailAddress')};
            if($recipients!==[$r['email']])throw new EmailCampaignException('SES result recipient is missing or ambiguous.',409);
        }
        $event=['event_id'=>'ses:'.hash('sha256',$c['topic'].':'.$m['MessageId'].':'.$rid),'recipient_id'=>$rid,'message_id'=>$r['message_id'],'event'=>$target,'provider'=>'ses:'.$c['region'].':'.$c['account'],'provider_message_id'=>$provider];
        return (new EmailCampaignService($this->pdo))->feedback($event,hash('sha256',$m['Message']));
    }
}
