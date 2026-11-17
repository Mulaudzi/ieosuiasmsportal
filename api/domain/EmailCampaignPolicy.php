<?php

final class EmailCampaignException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422) { parent::__construct($message); }
}

final class EmailCampaignPolicy
{
    public static function enabled(): bool { return env('EMAIL_CAMPAIGNS_ENABLED','false') === 'true'; }
    public static function configuration(): array
    {
        // Deliberately NEVER fall back to the transactional/cPanel SMTP credentials.
        return ['host'=>(string)env('EMAIL_CAMPAIGN_SMTP_HOST',''), 'port'=>(int)env('EMAIL_CAMPAIGN_SMTP_PORT',465),
            'encryption'=>(string)env('EMAIL_CAMPAIGN_SMTP_ENCRYPTION','ssl'), 'username'=>(string)env('EMAIL_CAMPAIGN_SMTP_USER',''),
            'password'=>(string)env('EMAIL_CAMPAIGN_SMTP_PASS',''), 'from'=>(string)env('EMAIL_CAMPAIGN_FROM',''),
            'public_url'=>rtrim((string)env('EMAIL_CAMPAIGN_PUBLIC_URL',''),'/'), 'abuse_email'=>(string)env('EMAIL_CAMPAIGN_ABUSE_EMAIL','sms@ieosuia.com')];
    }
    public static function readiness(): array
    {
        $c=self::configuration(); $reasons=[];$provider=(string)env('EMAIL_CAMPAIGN_PROVIDER','smtp');
        if (!self::enabled()) $reasons[]='Email campaigns are not enabled.';
        if (env('EMAIL_CAMPAIGNS_SEND_ENABLED','false') !== 'true') $reasons[]='Live email sending is disabled.';
        if (env('EMAIL_PROVIDER_APPROVED','false') !== 'true' || strlen(trim((string)env('EMAIL_PROVIDER_APPROVAL_REFERENCE',''))) < 10) $reasons[]='Written hosting and bulk-email provider permission is required.';
        if (env('EMAIL_DOMAIN_AUTH_VERIFIED','false') !== 'true' || strlen(trim((string)env('EMAIL_DOMAIN_AUTH_REFERENCE',''))) < 10) $reasons[]='SPF, DKIM, DMARC and sender-domain alignment must be verified.';
        if (env('EMAIL_CAMPAIGN_FEEDBACK_VERIFIED','false') !== 'true' || ($provider!=='ses'&&strlen((string)env('EMAIL_CAMPAIGN_WEBHOOK_SECRET',''))<32)) $reasons[]='Signed bounce and complaint feedback must be connected and tested.';
        if (!$c['host'] || !$c['username'] || !$c['password'] || !in_array($c['port'],[465,587],true) || !in_array($c['encryption'],['ssl','tls'],true) || !filter_var($c['from'],FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$c['host'].$c['username'].$c['from'])) $reasons[]='A dedicated TLS campaign email transport is required.';
        if (!self::publicUrl($c['public_url']) || parse_url($c['public_url'],PHP_URL_QUERY)!==null || parse_url($c['public_url'],PHP_URL_FRAGMENT)!==null || !filter_var($c['abuse_email'],FILTER_VALIDATE_EMAIL)) $reasons[]='Public HTTPS unsubscribe links and an abuse mailbox must be configured.';
        if ((int)env('EMAIL_CAMPAIGN_HOURLY_LIMIT',0)<1 || (int)env('EMAIL_CAMPAIGN_DAILY_LIMIT',0)<1) $reasons[]='Provider-approved hourly and daily limits must be configured.';
        if(!in_array($provider,['smtp','ses'],true))$reasons[]='Unsupported campaign transport.';
        if(str_ends_with(strtolower($c['host']),'.amazonaws.com')&&$provider!=='ses')$reasons[]='SES transport must use SES-specific feedback and quota controls.';
        if($provider==='ses'){
            $region=(string)env('EMAIL_SES_REGION','eu-north-1');$identity=(string)env('EMAIL_SES_IDENTITY','campaigns.ieosuia.com');$set=(string)env('EMAIL_SES_CONFIGURATION_SET','');
            if($c['host']!=='email-smtp.'.$region.'.amazonaws.com'||strtolower(substr(strrchr($c['from'],'@')?:'',1))!==$identity||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$set)||env('EMAIL_SES_FEEDBACK_ENABLED','false')!=='true')$reasons[]='The SES sender, region, configuration set and feedback endpoint must match.';
            try{require_once __DIR__.'/EmailSnsSignature.php';EmailSnsSignature::configuration();require_once __DIR__.'/EmailSesQuota.php';EmailSesQuota::rate(EmailSesQuota::snapshot());}catch(EmailCampaignException){$reasons[]='A fresh, trusted SES production/quota/feedback snapshot is required.';}
        }
        return ['ready'=>!$reasons,'reasons'=>$reasons];
    }
    public static function requireReady(): void
    {
        if (!self::readiness()['ready']) throw new EmailCampaignException('Email sending is awaiting provider and sender-domain approval. No email has been submitted.',503);
    }
    public static function publicUrl(string $value): bool
    {
        $p=parse_url($value); $host=strtolower((string)($p['host']??''));
        return ($p['scheme']??'')==='https' && $host!=='' && str_contains($host,'.') && !filter_var($host,FILTER_VALIDATE_IP)
            && !preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid)$/',$host)
            && !isset($p['user']) && !isset($p['pass']);
    }
    public static function email(string $raw): ?string
    {
        $value=strtolower(trim($raw));
        return strlen($value)<=254 && filter_var($value,FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n\x00]/',$value) ? $value : null;
    }
    public static function text(mixed $value,int $min,int $max,string $label,bool $multiline=false): string
    {
        if (!is_string($value)) throw new EmailCampaignException("{$label} must be text.");
        $value=trim(str_replace("\r\n","\n",$value));
        if (strlen($value)<$min || strlen($value)>$max || !preg_match('//u',$value) || preg_match($multiline?'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/':'/[\x00-\x1F\x7F]/',$value)) throw new EmailCampaignException("Check {$label}; it must contain {$min}–{$max} bytes of valid text without control characters.");
        return $value;
    }
    public static function unitCents(): int
    {
        $price=trim((string)env('SMS_PRICE_PER_SEGMENT',''));
        if (!preg_match('/^\d{1,5}(?:\.\d{1,4})?$/',$price)) throw new EmailCampaignException('Email pricing is not configured.',503);
        [$whole,$fraction]=array_pad(explode('.',$price),2,''); $units=(int)$whole*10000+(int)str_pad($fraction,4,'0');
        // A fraction of a cent must not silently disappear in existing DECIMAL(,2) wallets.
        if ($units<=0 || $units%500!==0) throw new EmailCampaignException('Email pricing must support exactly five emails per credit in whole currency cents.',503);
        return intdiv($units,500);
    }
    public static function moneyUnits(string $value): int
    {
        if (!preg_match('/^(\d{1,10})(?:\.(\d{1,4}))?$/',$value,$m)) throw new EmailCampaignException('Wallet reconciliation is required.',503);
        return (int)$m[1]*10000+(int)str_pad($m[2]??'',4,'0');
    }
    public static function amount(int $cents): string { return sprintf('%d.%02d00',intdiv($cents,100),$cents%100); }
    public static function credits(int $emails): string { return number_format($emails/5,1,'.',''); }
    public static function content(array $input): array
    {
        $subject=self::text($input['subject']??'',1,180,'subject');
        $body=self::text($input['body']??'',1,20000,'email message',true);
        // No HTML uploads, attachments or caller-controlled mail headers in this first release.
        if (isset($input['html']) || isset($input['attachments']) || isset($input['from']) || isset($input['headers'])) throw new EmailCampaignException('Custom From addresses, HTML, attachments and mail headers are not supported.');
        preg_match_all('~(?:https?://|www\.)[^\s<>]+~i',$body,$matches);
        foreach ($matches[0] as $url) if (!self::publicUrl(rtrim($url,".,;!)"))) throw new EmailCampaignException('Use public HTTPS links only. A reviewer will also check their safety.');
        return ['name'=>self::text($input['name']??'',0,100,'campaign name'),'subject'=>$subject,'body'=>$body];
    }
    public static function render(array $campaign,array $sender,string $unsubscribe,string $report): array
    {
        $escape=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $identity=$sender['business_name']." via IEOSUIA";
        $footer="Sent by {$sender['business_name']} via IEOSUIA.\n{$sender['postal_address']}\nReplies: {$sender['reply_to']}\nUnsubscribe: {$unsubscribe}\nReport abuse: {$report}";
        $html='<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:24px 12px;background:#f3f6fb;font-family:Arial,sans-serif;color:#13213b"><table role="presentation" style="width:100%;max-width:640px;margin:0 auto;border-collapse:collapse;background:white;border:1px solid #dfe7f2;border-radius:16px"><tr><td style="padding:24px 28px;background:#081d38;color:white"><div style="font-weight:bold;letter-spacing:2px;font-size:20px">IEOSUIA</div><p style="margin:12px 0 0;font-size:15px">'.$escape($identity).'</p></td></tr><tr><td style="padding:28px;font-size:16px;line-height:1.65;overflow-wrap:anywhere">'.nl2br($escape($campaign['body'])).'</td></tr><tr><td style="padding:24px 28px;border-top:1px solid #e4eaf3;background:#f8faff;font-size:12px;line-height:1.7;color:#586a82"><strong>'.$escape($sender['business_name']).'</strong><br>'.nl2br($escape($sender['postal_address'])).'<br>Replies: '.$escape($sender['reply_to']).'<p style="margin:12px 0 0">Sent via IEOSUIA · <a style="color:#245cf5" href="'.$escape($unsubscribe).'">Unsubscribe</a> · <a style="color:#245cf5" href="'.$escape($report).'">Report abuse</a></p></td></tr></table></body></html>';
        return ['text'=>$campaign['body']."\n\n---\n".$footer,'html'=>$html];
    }
}
