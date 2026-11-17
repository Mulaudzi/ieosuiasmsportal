<?php
require_once __DIR__.'/EmailCampaignPolicy.php';
final class EmailCampaignWebhook
{
    /** Adapter contract: HMAC-SHA256(timestamp + '.' + exact UTF-8 request body). */
    public static function verify(string $raw,string $timestamp,string $signature,?int $now=null): array
    {
        $secret=(string)env('EMAIL_CAMPAIGN_WEBHOOK_SECRET','');$previous=(string)env('EMAIL_CAMPAIGN_WEBHOOK_PREVIOUS_SECRET','');
        if(strlen($secret)<32)throw new EmailCampaignException('Email feedback is not configured.',503);
        if(strlen($raw)>32768 || !preg_match('/^[0-9]{10}$/D',$timestamp) || abs(($now??time())-(int)$timestamp)>300 || !preg_match('/^[a-f0-9]{64}$/D',$signature))throw new EmailCampaignException('Invalid provider event signature.',401);
        $valid=hash_equals(hash_hmac('sha256',$timestamp.'.'.$raw,$secret),$signature);
        if(strlen($previous)>=32)$valid=hash_equals(hash_hmac('sha256',$timestamp.'.'.$raw,$previous),$signature)||$valid;
        if(!$valid)throw new EmailCampaignException('Invalid provider event signature.',401);
        try{$event=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){throw new EmailCampaignException('Invalid provider event JSON.');}
        if(!is_array($event))throw new EmailCampaignException('Invalid provider event JSON.');
        return $event;
    }
}
