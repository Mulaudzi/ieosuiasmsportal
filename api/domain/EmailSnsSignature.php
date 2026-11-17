<?php
require_once __DIR__.'/EmailCampaignPolicy.php';

/** Only the explicitly configured AWS SNS topic is trusted. Never follows arbitrary URLs. */
final class EmailSnsSignature
{
    public function __construct(private mixed $fetchCertificate=null) {}
    public static function configuration(): array
    {
        $region=(string)env('EMAIL_SES_REGION','eu-north-1');$account=(string)env('EMAIL_SES_ACCOUNT_ID','');$topic=(string)env('EMAIL_SES_SNS_TOPIC_ARN','');
        if(!preg_match('/^[a-z]{2}-[a-z]+-[1-9]$/D',$region)||!preg_match('/^[0-9]{12}$/D',$account)||!preg_match('~^arn:aws:sns:'.preg_quote($region,'~').':'.$account.':[A-Za-z0-9_-]{1,256}$~D',$topic))throw new EmailCampaignException('SES feedback trust is not configured.',503);
        return ['region'=>$region,'account'=>$account,'topic'=>$topic,'host'=>'sns.'.$region.'.amazonaws.com'];
    }
    public static function stringToSign(array $m): string
    {
        $fields=($m['Type']??'')==='Notification'?['Message','MessageId',...(array_key_exists('Subject',$m)?['Subject']:[]),'Timestamp','TopicArn','Type']:['Message','MessageId','SubscribeURL','Timestamp','Token','TopicArn','Type'];
        $text='';foreach($fields as $field){if(!isset($m[$field])||!is_string($m[$field])||($field!=='Subject'&&$m[$field]===''))throw new EmailCampaignException('Malformed SNS message.',400);$text.=$field."\n".$m[$field]."\n";}return $text;
    }
    public static function certificateUrl(string $url): void
    {
        $c=self::configuration();if(!preg_match('~^https://'.preg_quote($c['host'],'~').'/SimpleNotificationService-[a-f0-9]{32}\.pem$~D',$url))throw new EmailCampaignException('Untrusted SNS signing certificate.',401);
    }
    public static function confirmationUrl(array $m): string
    {
        $c=self::configuration();$url=$m['SubscribeURL']??'';$p=is_string($url)?parse_url($url):false;
        if(!$p||($p['scheme']??'')!=='https'||($p['host']??'')!==$c['host']||($p['path']??'/')!=='/'||isset($p['user'],$p['pass'])||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['fragment']))throw new EmailCampaignException('Untrusted SNS confirmation URL.',401);
        parse_str($p['query']??'',$query);
        if(count($query)!==3||($query['Action']??'')!=='ConfirmSubscription'||($query['TopicArn']??'')!==$c['topic']||!is_string($query['Token']??null)||!hash_equals((string)$m['Token'],$query['Token']))throw new EmailCampaignException('Untrusted SNS confirmation URL.',401);
        return $url;
    }
    /** TLS, public IPv4 pinning, no redirects or proxies, bounded response. */
    public static function fetch(string $url,int $limit=16384): string
    {
        if(!function_exists('curl_init'))throw new EmailCampaignException('Secure SNS certificate retrieval is unavailable.',503);
        $c=self::configuration();$ips=gethostbynamel($c['host']);if(!$ips)throw new EmailCampaignException('SNS certificate service unavailable.',503);
        foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)||preg_match('/^(?:0|127|169\.254|100\.(?:6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7]))\./',$ip))throw new EmailCampaignException('Unsafe SNS certificate address.',503);
        $h=curl_init($url);$body='';
        curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$c['host'].':443:'.$ips[0]],CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,$limit){if(strlen($body)+strlen($chunk)>$limit)return 0;$body.=$chunk;return strlen($chunk);}]);
        try{$ok=curl_exec($h);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);if($ok===false||$status!==200)throw new EmailCampaignException('SNS certificate service unavailable.',503);return $body;}finally{curl_close($h);}
    }
    public function verify(string $raw,?int $now=null): array
    {
        if(strlen($raw)>262144)throw new EmailCampaignException('SNS message too large.',413);
        try{$m=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){throw new EmailCampaignException('Invalid SNS JSON.',400);}
        $c=self::configuration();$now??=time();
        if(!is_array($m)||!in_array($m['Type']??'', ['Notification','SubscriptionConfirmation','UnsubscribeConfirmation'],true)||($m['TopicArn']??'')!==$c['topic']||($m['SignatureVersion']??'')!=='2'||!is_string($m['MessageId']??null)||!preg_match('/^[a-f0-9-]{36}$/D',$m['MessageId']))throw new EmailCampaignException('Untrusted SNS message.',401);
        if(!is_string($m['Timestamp']??null)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?Z$/D',$m['Timestamp'])||($sent=strtotime($m['Timestamp']))===false||$sent>$now+300||$sent<$now-1987200)throw new EmailCampaignException('Invalid SNS message time.',401);
        $url=$m['SigningCertURL']??'';if(!is_string($url))throw new EmailCampaignException('Invalid SNS certificate.',401);self::certificateUrl($url);
        $sign=$m['Signature']??'';$signature=is_string($sign)?base64_decode($sign,true):false;
        if($signature===false||strlen($signature)<256||strlen($signature)>1024)throw new EmailCampaignException('Invalid SNS signature.',401);
        $text=self::stringToSign($m);
        if($m['Type']!=='Notification')self::confirmationUrl($m);
        $pem=$this->fetchCertificate?($this->fetchCertificate)($url):self::fetch($url);
        if(!is_string($pem)||strlen($pem)>16384||!str_starts_with($pem,'-----BEGIN CERTIFICATE-----'))throw new EmailCampaignException('Invalid SNS certificate.',401);
        $cert=openssl_x509_read($pem);$details=$cert?openssl_x509_parse($cert):false;$key=$cert?openssl_pkey_get_public($cert):false;$kd=$key?openssl_pkey_get_details($key):false;
        if(!$details||!$kd||$kd['type']!==OPENSSL_KEYTYPE_RSA||$kd['bits']<2048||$sent<$details['validFrom_time_t']||$sent>$details['validTo_time_t']||openssl_verify($text,$signature,$key,OPENSSL_ALGO_SHA256)!==1)throw new EmailCampaignException('Invalid SNS signature.',401);
        return $m;
    }
}
