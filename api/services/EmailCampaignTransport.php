<?php
require_once __DIR__.'/../domain/EmailCampaignPolicy.php';
require_once __DIR__.'/../lib/PHPMailer/Exception.php';
require_once __DIR__.'/../lib/PHPMailer/SMTP.php';
require_once __DIR__.'/../lib/PHPMailer/PHPMailer.php';

final class EmailCampaignSmtpEvidence extends \PHPMailer\PHPMailer\SMTP
{
    public bool $enteredData=false;
    public bool $accepted=false;
    public bool $explicitRejection=false;
    public function data($msg_data)
    {
        $this->enteredData=true;
        $result=parent::data($msg_data);
        $this->accepted=$result===true;
        $this->explicitRejection=!$result && preg_match('/^[45][0-9]{2}(?:[ -]|$)/',$this->getLastReply())===1;
        return $result;
    }
    public function outcome(): string { return $this->accepted?'accepted':(!$this->enteredData || $this->explicitRejection?'rejected':'uncertain'); }
}

final class EmailCampaignTransport
{
    /** One recipient per SMTP envelope. No BCC exposure, PHP mail(), debug output or system-SMTP fallback. */
    public static function build(array $job): \PHPMailer\PHPMailer\PHPMailer
    {
        EmailCampaignPolicy::requireReady(); $c=EmailCampaignPolicy::configuration();
        $smtp=new EmailCampaignSmtpEvidence();$mail=new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();$mail->setSMTPInstance($smtp);$mail->SMTPDebug=0;
            $mail->Host=$c['host'];$mail->Port=$c['port'];$mail->SMTPAuth=true;
            $mail->Username=$c['username'];$mail->Password=$c['password'];
            $mail->SMTPSecure=$c['encryption'];$mail->SMTPAutoTLS=true;
            $mail->Timeout=15;$smtp->Timelimit=15;$mail->SMTPKeepAlive=false;
            $mail->SMTPOptions=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]];
            $mail->CharSet='UTF-8';$mail->Encoding='quoted-printable';
            $sender=$job['sender'];$r=$job['recipient'];
            $mail->setFrom($c['from'],$sender['business_name'].' via IEOSUIA');
            $mail->addReplyTo($sender['reply_to'],$sender['business_name']);$mail->addAddress($r['email']);
            $mail->MessageID=$r['message_id'];$mail->Subject=$job['campaign']['subject'];
            $url=$c['public_url'].'/email/preferences/'.$r['id'].'?token='.$job['unsubscribe_token'];
            $content=EmailCampaignPolicy::render($job['campaign'],$sender,$url,$url.'&report=1');
            $mail->isHTML(true);$mail->Body=$content['html'];$mail->AltBody=$content['text'];
            $mail->addCustomHeader('List-Unsubscribe','<'.$url.'>');
            $mail->addCustomHeader('List-Unsubscribe-Post','List-Unsubscribe=One-Click');
            if(env('EMAIL_CAMPAIGN_PROVIDER','smtp')==='ses'){
                $mail->addCustomHeader('X-SES-CONFIGURATION-SET',(string)env('EMAIL_SES_CONFIGURATION_SET',''));
                $mail->addCustomHeader('X-SES-MESSAGE-TAGS','ieosuia_channel=email_campaign, ieosuia_recipient_id='.$r['id'].', ieosuia_campaign_id='.$job['campaign']['id']);
            }
        return $mail;
    }
    public static function submit(array $job): string
    {
        $mail=self::build($job);$smtp=$mail->getSMTPInstance();
        try {
            $mail->send();
        } catch(Throwable) {
            // Neither credentials, recipients, message content nor SMTP server replies enter logs/API errors.
        } finally { try {$mail->smtpClose();}catch(Throwable){} }
        return $smtp->outcome();
    }
}
