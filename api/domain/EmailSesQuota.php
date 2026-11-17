<?php
require_once __DIR__.'/EmailCampaignPolicy.php';

/** Read-only AWS quota snapshots; the sending host never receives operator AWS credentials. */
final class EmailSesQuota
{
    public static function snapshot(?int $now=null): array
    {
        $path=(string)env('EMAIL_SES_QUOTA_FILE','');$secret=(string)env('EMAIL_SES_QUOTA_HMAC_KEY','');$now??=time();
        if($path===''||strlen($secret)<32||!is_file($path)||filesize($path)>16384)throw new EmailCampaignException('A fresh verified SES quota snapshot is required.',503);
        $raw=file_get_contents($path,false,null,0,16385);$prefix="<?php exit; ?>\n";
        if(!is_string($raw)||!str_starts_with($raw,$prefix))throw new EmailCampaignException('Invalid SES quota snapshot.',503);
        try{$envelope=json_decode(substr($raw,strlen($prefix)),true,8,JSON_THROW_ON_ERROR);}catch(JsonException){throw new EmailCampaignException('Invalid SES quota snapshot.',503);}
        if(!is_array($envelope)||!is_string($envelope['payload']??null)||!is_string($envelope['signature']??null)||!hash_equals(hash_hmac('sha256',$envelope['payload'],$secret),$envelope['signature']))throw new EmailCampaignException('Untrusted SES quota snapshot.',503);
        try{$s=json_decode($envelope['payload'],true,8,JSON_THROW_ON_ERROR);}catch(JsonException){throw new EmailCampaignException('Invalid SES quota payload.',503);}
        $ttl=(int)env('EMAIL_SES_QUOTA_MAX_AGE',300);
        if(!is_array($s)||!is_int($s['observed_at']??null)||$s['observed_at']>$now+30||$s['observed_at']<$now-max(30,min(600,$ttl))||($s['account_id']??'')!==(string)env('EMAIL_SES_ACCOUNT_ID','')||($s['region']??'')!==(string)env('EMAIL_SES_REGION','eu-north-1')||($s['configuration_set']??'')!==(string)env('EMAIL_SES_CONFIGURATION_SET','')||($s['topic_arn']??'')!==(string)env('EMAIL_SES_SNS_TOPIC_ARN','')||($s['identity']??'')!==(string)env('EMAIL_SES_IDENTITY','campaigns.ieosuia.com'))throw new EmailCampaignException('SES quota scope is stale or incorrect.',503);
        foreach(['max_per_second','max_24h','sent_last_24h'] as $field)if(!is_int($s[$field]??null)&&!is_float($s[$field]??null)||!is_finite((float)($s[$field]??NAN))||($s[$field]??-1)<0)throw new EmailCampaignException('Invalid SES quota values.',503);
        if($s['max_per_second']<=0||$s['max_24h']<1||($s['production_access']??false)!==true||($s['sending_enabled']??false)!==true||($s['configuration_enabled']??false)!==true||($s['identity_verified']??false)!==true||($s['feedback_destination_verified']??false)!==true||($s['enforcement_status']??'')!=='HEALTHY')throw new EmailCampaignException('SES production, identity, sending or feedback approval is incomplete.',503);
        return $s;
    }
    public static function rate(array $s): float
    {
        $local=filter_var(env('EMAIL_SES_SEND_RATE','1'),FILTER_VALIDATE_FLOAT);
        if($local===false||!is_finite($local)||$local<=0||$local>50)throw new EmailCampaignException('Choose a safe SES sending rate.',503);
        return min((float)$s['max_per_second'],$local);
    }
    public static function allowed(PDO $p,?float $now=null): bool
    {
        $s=self::snapshot();$now??=microtime(true);$rate=self::rate($s);
        $next=$p->query('SELECT next_allowed_at FROM email_campaign_dispatch_budget WHERE id=1')->fetchColumn();
        if($next===false)throw new EmailCampaignException('SES dispatch budget needs setup.',503);
        if((float)$next>$now)return false;
        $q=$p->prepare('SELECT COUNT(*) FROM email_campaign_recipients WHERE started_at>=?');$q->execute([gmdate('Y-m-d H:i:s',(int)$now-86400)]);$local=(int)$q->fetchColumn();
        // Count the overlap again to be conservative about provider reporting lag.
        $q->execute([gmdate('Y-m-d H:i:s',$s['observed_at']-60)]);$recent=(int)$q->fetchColumn();
        $reserve=max(1,(int)env('EMAIL_SES_QUOTA_RESERVE',10));$cap=(int)floor($s['max_24h'])-$reserve;
        return $local<$cap&&$s['sent_last_24h']+$recent<$cap;
    }
    /** Called in the same transaction as the durable submitting checkpoint, under the worker lock. */
    public static function reserveSlot(PDO $p,?float $now=null): void
    {
        $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        if(!$p->inTransaction())throw new EmailCampaignException('SES quota transaction required.',503);
        $row=$p->query('SELECT next_allowed_at FROM email_campaign_dispatch_budget WHERE id=1'.($mysql?' FOR UPDATE':''))->fetchColumn();
        if($row===false)throw new EmailCampaignException('SES dispatch budget needs setup.',503);
        $now??=microtime(true);if((float)$row>$now||!self::allowed($p,$now))throw new EmailCampaignException('SES dispatch allowance changed.',429);
        $next=max($now,(float)$row)+1/self::rate(self::snapshot());
        $q=$p->prepare('UPDATE email_campaign_dispatch_budget SET next_allowed_at=? WHERE id=1');$q->execute([sprintf('%.6F',$next)]);
    }
    public static function delay(PDO $p): float
    {
        $row=$p->query('SELECT next_allowed_at FROM email_campaign_dispatch_budget WHERE id=1')->fetchColumn();
        return $row===false?0:max(0,(float)$row-microtime(true));
    }
}
