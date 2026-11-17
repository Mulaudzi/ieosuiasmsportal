<?php
/**
 * Email Service - SMTP via PHPMailer
 * Rewritten to match production-ready patterns
 */

// Include local PHPMailer files
require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    private static ?PHPMailer $mailer = null;
    private const SECURITY_EMAIL = 'security@ieosuia.com';

    /** One requested ownership code, independent of campaign sending and wallet accounting. */
    public static function sendCampaignReplyToCode(string $to, #[\SensitiveParameter] string $code):array
    {
        if (!preg_match('/^[0-9]{6}$/D', $code)) throw new InvalidArgumentException('Invalid verification code');
        $subject='Verify your campaign Reply-To email';
        $text="IEOSUIA SMS Portal\n\nUse this code to verify this inbox for replies to your email campaigns:\n\n".$code."\n\nExpires in 10 minutes. Use once only. Enter it only at sms.ieosuia.com. This code does not sign you in or approve a campaign.\n\nIf you did not request this, ignore this email. Do not share the code.\nhttps://sms.ieosuia.com";
        $html='<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$subject.'</title></head><body style="margin:0;padding:24px 12px;background:#f2f5fb;color:#14233e;font-family:Arial,sans-serif"><table role="presentation" width="100%"><tr><td align="center"><table role="presentation" width="100%" style="max-width:560px;background:white;border:1px solid #dce5f3;border-radius:16px;overflow:hidden" cellspacing="0"><tr><td style="padding:24px;background:#09213e;color:white;border-bottom:4px solid #2563eb"><strong style="font-size:24px">IEOSUIA</strong><br>SMS PORTAL</td></tr><tr><td style="padding:28px 24px"><h1 style="font-size:24px">Verify your reply inbox</h1><p style="line-height:1.7">Use this code to verify this email address for replies to your campaigns.</p><p style="padding:22px;background:#eff5ff;text-align:center;font-size:34px;font-family:monospace;letter-spacing:5px;color:#1d4ed8">'.$code.'</p><p>Expires in 10 minutes · Use once only</p><p style="line-height:1.7">Enter this code only at sms.ieosuia.com. It does not sign you in or approve a campaign. Never share it.</p><p style="font-size:13px;color:#607089">If you did not request this email, ignore it.</p></td></tr><tr><td style="padding:18px 24px;border-top:1px solid #e5ebf4;font-size:12px;color:#607089">IEOSUIA Messaging · sms.ieosuia.com</td></tr></table></td></tr></table></body></html>';
        $mailer=self::getMailer();$debug=$mailer->SMTPDebug;$mailer->SMTPDebug=SMTP::DEBUG_OFF;
        try{return self::send($to,$subject,$html,$text,self::SECURITY_EMAIL);}
        finally{$mailer->SMTPDebug=$debug;}
    }

    public static function sendSmsLoginCode(string $to, #[\SensitiveParameter] string $code,string $purpose):array
    {
        $content = self::renderSmsLoginCode($code, $purpose);
        $mailer=self::getMailer();$debug=$mailer->SMTPDebug;$mailer->SMTPDebug=SMTP::DEBUG_OFF;
        try{
            return self::send($to, $content['subject'], $content['html'], $content['text'], self::SECURITY_EMAIL);
        }finally{$mailer->SMTPDebug=$debug;}
    }

    /** Pure rendering: no SMTP, tracking pixels, credentials or authentication links. */
    private static function renderSmsLoginCode(#[\SensitiveParameter] string $code, string $purpose): array
    {
        if (!preg_match('/^[0-9]{6}$/D', $code)) throw new InvalidArgumentException('Invalid verification code');
        $action = match ($purpose) {
            'enable' => 'enable your extra sign-in check',
            'disable' => 'disable your extra sign-in check',
            'login' => 'finish signing in',
            'deployment_test' => 'preview your verification email',
            default => throw new InvalidArgumentException('Invalid verification purpose'),
        };
        $test = $purpose === 'deployment_test';
        $subject = $test ? 'IEOSUIA SMS Portal — verification email delivery test' : 'Your SMS Portal verification code';
        $title = $test ? 'Your verification email is ready' : 'A little extra security';
        $intro = $test ? 'This is the single delivery test you approved. The sample below cannot sign you in or change your settings.' : 'Use the code below to '.$action.'. This request applies to the SMS Portal only.';
        $label = $test ? 'SAMPLE CODE · NOT VALID FOR SIGN-IN' : 'YOUR VERIFICATION CODE';
        $expiry = $test ? 'Delivery test only — no action required.' : 'Expires in 10 minutes · Use once only';
        $notice = $test ? 'Extra sign-in verification has not been enabled on your account. No credits were moved or charged. You can opt in yourself in Settings → Security when available.' : 'Enter this code only at sms.ieosuia.com. Never share it with anyone, including our support team.';
        $footer = $test ? 'Please confirm receipt only. Do not send us any real verification or recovery codes.' : 'Didn’t request this? Do not use the code. Secure your IEOSUIA account and contact us if you need help.';
        $safe = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subjectHtml = $safe($subject); $title = $safe($title); $introHtml = $safe($intro);
        $labelHtml = $safe($label); $expiryHtml = $safe($expiry); $noticeHtml = $safe($notice); $footerHtml = $safe($footer);
        $html = <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{$subjectHtml}</title></head>
<body style="margin:0;padding:0;background:#f2f5fb;color:#14233e;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all">{$expiryHtml} — SMS Portal account security.</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f2f5fb"><tr><td align="center" style="padding:24px 12px">
<!--[if mso]><table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background:#ffffff;border:1px solid #dce5f3;border-radius:16px;overflow:hidden">
<tr><td style="padding:25px 24px;background:#09213e;border-bottom:4px solid #2563eb"><span style="font-size:25px;font-weight:700;letter-spacing:1px;color:#ffffff">IEO<span style="color:#20c4ed">SUIA</span></span><br><span style="display:inline-block;padding-top:6px;font-size:11px;letter-spacing:2px;color:#c5d9ee">SMS PORTAL · ACCOUNT SECURITY</span></td></tr>
<tr><td style="padding:28px 24px 12px"><h1 style="margin:0 0 12px;font-size:25px;line-height:1.3;color:#10213b">{$title}</h1><p style="margin:0;font-size:15px;line-height:1.65;color:#50617b">{$introHtml}</p></td></tr>
<tr><td style="padding:12px 24px 20px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#eff5ff;border:1px solid #d4e3ff;border-radius:12px"><tr><td align="center" style="padding:22px 8px"><p style="margin:0 0 12px;font-size:10px;font-weight:700;letter-spacing:1px;line-height:1.6;color:#47618a">{$labelHtml}</p><div style="font-family:Consolas,'Courier New',monospace;font-size:34px;line-height:1.25;font-weight:700;letter-spacing:5px;color:#1d4ed8;white-space:nowrap">{$code}</div><p style="margin:12px 0 0;font-size:12px;line-height:1.6;color:#50617b">{$expiryHtml}</p></td></tr></table></td></tr>
<tr><td style="padding:0 24px 24px"><p style="margin:0 0 16px;padding:14px;background:#f7f9fc;border-left:3px solid #2563eb;font-size:13px;line-height:1.65;color:#334965">{$noticeHtml}</p><p style="margin:0;font-size:13px;line-height:1.65;color:#607089">{$footerHtml}</p></td></tr>
<tr><td style="padding:18px 24px;border-top:1px solid #e5ebf4;background:#fbfcfe;font-size:12px;line-height:1.8;color:#607089">Account security help: <a href="mailto:security@ieosuia.com" style="color:#1d4ed8;text-decoration:underline">security@ieosuia.com</a><br><a href="https://sms.ieosuia.com" style="color:#50617b;text-decoration:none">sms.ieosuia.com</a> · Secure messaging, made simple.</td></tr>
</table><!--[if mso]></td></tr></table><![endif]-->
</td></tr></table></body></html>
HTML;
        $text = "IEOSUIA SMS Portal\n\n".$intro."\n\n".$label.": ".$code."\n".$expiry."\n\n".$notice."\n\n".$footer."\n\nAccount security help: ".self::SECURITY_EMAIL."\nhttps://sms.ieosuia.com";
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /** Transactional financial verification, independent of bulk email campaigns. */
    public static function sendCreditTransferCode(string $to, #[\SensitiveParameter] string $code, int $credits, string $recipient, string $reference): array
    {
        $content = self::renderCreditTransferCode($code, $credits, $recipient, $reference);
        $mailer=self::getMailer();$debug=$mailer->SMTPDebug;
        $mailer->SMTPDebug=SMTP::DEBUG_OFF;
        try {
            return self::send($to, $content['subject'], $content['html'], $content['text'], self::SECURITY_EMAIL);
        } finally { $mailer->SMTPDebug=$debug; }
    }

    /** Pure transactional-email rendering. Does not create a transfer or issue a code. */
    private static function renderCreditTransferCode(#[\SensitiveParameter] string $code, int $credits, string $recipient, string $reference): array
    {
        if (!preg_match('/^[0-9]{6}$/D', $code)) throw new InvalidArgumentException('Invalid verification code');
        if ($credits < 1) throw new InvalidArgumentException('Invalid transfer amount');
        $safe = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $amount = number_format($credits);
        $displayReference = 'TRF-'.strtoupper($reference);
        $safeRecipient = $safe($recipient); $safeReference = $safe($displayReference);
        $subject = 'Confirm your SMS credit transfer';
        $html = <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Confirm your SMS credit transfer</title></head>
<body style="margin:0;padding:0;background:#f2f5fb;color:#14233e;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all">Review your credit transfer and enter your one-time code in the SMS Portal. Never share this code.</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f2f5fb"><tr><td align="center" style="padding:24px 12px">
<!--[if mso]><table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background:#ffffff;border:1px solid #dce5f3;border-radius:16px;overflow:hidden;table-layout:fixed">
<tr><td style="padding:25px 24px;background:#09213e;border-bottom:4px solid #2563eb"><span style="font-size:25px;font-weight:700;letter-spacing:1px;color:#ffffff">IEO<span style="color:#20c4ed">SUIA</span></span><br><span style="display:inline-block;padding-top:6px;font-size:11px;letter-spacing:2px;line-height:1.6;color:#c5d9ee">SMS PORTAL · SECURE CREDIT TRANSFER</span></td></tr>
<tr><td style="padding:28px 24px 18px"><h1 style="margin:0 0 12px;font-size:25px;line-height:1.3;color:#10213b">Confirm your credit transfer</h1><p style="margin:0;font-size:14px;line-height:1.7;color:#50617b">Before we move your credits, please check the amount and recipient below.</p></td></tr>
<tr><td style="padding:0 24px 20px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f7f9fd;border:1px solid #dce5f3;border-radius:12px;table-layout:fixed">
<tr><td style="padding:16px 16px 12px"><p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:.7px;color:#607089">TRANSFER AMOUNT</p><p style="margin:0;font-size:28px;line-height:1.4;font-weight:700;color:#10213b;overflow-wrap:anywhere;word-break:break-word">{$amount} <span style="font-size:14px;font-weight:400;color:#50617b">SMS credits</span></p></td></tr>
<tr><td style="padding:12px 16px 16px;border-top:1px solid #e3eaf4"><p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:.7px;color:#607089">TO ACCOUNT</p><p style="margin:0;font-size:14px;line-height:1.65;font-weight:600;color:#14233e;overflow-wrap:anywhere;word-break:break-all">{$safeRecipient}</p></td></tr></table></td></tr>
<tr><td style="padding:0 24px 20px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#eff5ff;border:1px solid #d4e3ff;border-radius:12px"><tr><td align="center" style="padding:20px 8px"><p style="margin:0 0 12px;font-size:10px;font-weight:700;letter-spacing:1px;color:#47618a">YOUR ONE-TIME CODE</p><div style="font-family:Consolas,'Courier New',monospace;font-size:34px;line-height:1.25;font-weight:700;letter-spacing:5px;color:#1d4ed8;white-space:nowrap">{$code}</div><p style="margin:12px 0 0;font-size:12px;line-height:1.65;color:#50617b">Expires with your transfer review,<br>within 10 minutes. Use once only.</p></td></tr></table></td></tr>
<tr><td style="padding:0 24px 20px"><p style="margin:0;font-size:13px;line-height:1.7;color:#50617b">Enter this code only in the IEOSUIA SMS Portal. Confirm only if you recognise the recipient and amount. Never share the code with anyone, including support.</p><p style="margin:16px 0 0;font-size:11px;line-height:1.7;color:#607089">TRANSFER REFERENCE<br><span style="font-family:Consolas,'Courier New',monospace;color:#334965;overflow-wrap:anywhere;word-break:break-all">{$safeReference}</span></p></td></tr>
<tr><td style="padding:0 24px 24px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#fff8eb;border:1px solid #f4dfb7;border-radius:10px"><tr><td style="padding:14px"><p style="margin:0 0 6px;font-size:13px;font-weight:700;color:#775014">Didn't request this transfer?</p><p style="margin:0;font-size:12px;line-height:1.75;color:#66553b">Do not use the code. No credits move without confirmation. Secure your IEOSUIA account and contact <a href="mailto:security@ieosuia.com" style="color:#1d4ed8;text-decoration:underline;overflow-wrap:anywhere">security@ieosuia.com</a>.</p></td></tr></table></td></tr>
<tr><td style="padding:18px 24px;border-top:1px solid #e5ebf4;background:#fbfcfe;font-size:12px;line-height:1.8;color:#607089">Account security help: <a href="mailto:security@ieosuia.com" style="color:#1d4ed8;text-decoration:underline;overflow-wrap:anywhere">security@ieosuia.com</a><br><a href="https://sms.ieosuia.com/wallet" style="color:#50617b;text-decoration:underline">Open the SMS Portal</a><br><span style="font-size:11px">This email confirms a request, not a completed transfer.</span></td></tr>
</table><!--[if mso]></td></tr></table><![endif]-->
</td></tr></table></body></html>
HTML;
        $text = "IEOSUIA SMS Portal — secure credit transfer\n\nConfirm your SMS credit transfer\n\nTransfer amount: ".$amount." SMS credits\nTo account: ".$recipient."\nReference: ".$displayReference."\n\nYour one-time code: ".$code."\nExpires with your transfer review, within 10 minutes. Use once only.\n\nEnter this code only in the IEOSUIA SMS Portal. Confirm only if you recognise the recipient and amount. Never share the code with anyone, including support.\n\nDidn't request this transfer? Do not use the code. No credits move without confirmation. Secure your IEOSUIA account and contact ".self::SECURITY_EMAIL.".\n\nOpen the SMS Portal: https://sms.ieosuia.com/wallet\nThis email confirms a request, not a completed transfer.";
        return ['subject'=>$subject, 'html'=>$html, 'text'=>$text];
    }

    /** Receipt for a committed transfer only; never contains a verification code. */
    public static function sendCreditTransferCompleted(string $to,int $credits,string $id,string $completedAt):array
    {
        $content=self::renderCreditTransferCompleted($credits,$id,$completedAt);
        $mail=self::getMailer();$debug=$mail->SMTPDebug;$messageId=$mail->MessageID;
        $mail->SMTPDebug=SMTP::DEBUG_OFF;$mail->MessageID='<credit-transfer.'.$id.'@sms.ieosuia.com>';
        try{return self::send($to,$content['subject'],$content['html'],$content['text'],self::SECURITY_EMAIL);}
        finally{$mail->SMTPDebug=$debug;$mail->MessageID=$messageId;}
    }

    private static function renderCreditTransferCompleted(int $credits,string $id,string $completedAt):array
    {
        if($credits<1||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Invalid transfer receipt.');
        $amount=number_format($credits);$reference='TRF-'.strtoupper($id);
        $time=htmlspecialchars($completedAt,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html=<<<HTML
<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Your credit transfer is complete</title></head>
<body style="margin:0;background:#f2f5fb;font-family:Arial,Helvetica,sans-serif;color:#14233e;-webkit-text-size-adjust:100%">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;table-layout:fixed;background:#fff;border:1px solid #dce5f3;border-radius:16px">
<tr><td style="padding:24px;background:#09213e;border-bottom:4px solid #2563eb;color:#fff"><strong style="font-size:25px">IEO<span style="color:#20c4ed">SUIA</span></strong><br><span style="font-size:11px;letter-spacing:2px;line-height:2">SMS PORTAL · TRANSFER RECEIPT</span></td></tr>
<tr><td style="padding:28px 24px"><h1 style="margin:0 0 12px;font-size:25px;line-height:1.3">Your credit transfer is complete</h1><p style="font-size:14px;line-height:1.7;color:#50617b">The credits have left your SMS Portal account and have been added to the recipient's account.</p>
<div style="padding:20px;background:#ecfdf5;border:1px solid #bcebd7;border-radius:12px"><span style="font-size:11px;color:#176d51">SUCCESSFULLY TRANSFERRED</span><br><strong style="font-size:30px;line-height:1.8;color:#116448">{$amount}</strong> SMS credits</div>
<p style="font-size:12px;line-height:1.8;color:#50617b">Completed: {$time} (South Africa time)<br>Reference:<br><span style="font-family:monospace;overflow-wrap:anywhere;word-break:break-all">{$reference}</span></p>
<p style="font-size:14px;line-height:1.7"><a href="https://sms.ieosuia.com/wallet" style="color:#1d4ed8">View your transfer history</a></p>
<div style="padding:14px;background:#fff8eb;border:1px solid #f4dfb7;border-radius:10px;font-size:12px;line-height:1.8"><strong>Didn't authorise this transfer?</strong><br>Secure your IEOSUIA account and contact <a href="mailto:security@ieosuia.com" style="color:#1d4ed8;overflow-wrap:anywhere">security@ieosuia.com</a>. Never share passwords or verification codes.</div></td></tr>
<tr><td style="padding:18px 24px;border-top:1px solid #e5ebf4;font-size:12px;line-height:1.8;color:#607089">This is a receipt, not another request to transfer credits.<br>IEOSUIA SMS Portal · Secure messaging, made simple.</td></tr>
</table></td></tr></table></body></html>
HTML;
        return ['subject'=>'Your SMS credit transfer is complete','html'=>$html,'text'=>"IEOSUIA SMS Portal\n\nYour credit transfer is complete.\n".$amount." SMS credits have left your account and were added to the recipient's account.\nCompleted: ".$completedAt." (South Africa time)\nReference: ".$reference."\nView transfer history: https://sms.ieosuia.com/wallet\n\nDidn't authorise this transfer? Secure your account and contact security@ieosuia.com. Never share passwords or verification codes.\nThis is a receipt, not another transfer request."];
    }

    private static function getMailer(): PHPMailer
    {
        if (self::$mailer === null) {
            $mailer = new PHPMailer(true);
            
            // Get system SMTP settings from database (fallback to env)
            require_once __DIR__ . '/../controllers/SmtpSettingsController.php';
            $settings = SmtpSettingsController::getSettings('system');

            // Server settings
            $mailer->isSMTP();
            $mailer->Host = $settings['host'];
            $mailer->SMTPAuth = true;
            $mailer->Username = $settings['username'];
            $mailer->Password = $settings['password'];
            $mailer->Port = $settings['port'];
            $mailer->Timeout = max(5, min(60, (int) env('SMTP_TIMEOUT', 20)));
            $mailer->SMTPKeepAlive = false;
            
            switch ($settings['encryption']) {
                case 'ssl':
                    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    break;
                case 'tls':
                    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    break;
                default:
                    $mailer->SMTPSecure = '';
                    $mailer->SMTPAutoTLS = false;
            }

            // Default sender
            $mailer->setFrom($settings['from_email'], $settings['from_name']);

            // Encoding
            $mailer->CharSet = 'UTF-8';
            $mailer->Encoding = 'base64';

            // Debug (set to 0 for production)
            $mailer->SMTPDebug = (env('APP_DEBUG', 'false') === 'true') ? SMTP::DEBUG_SERVER : SMTP::DEBUG_OFF;
            self::$mailer = $mailer;
        }

        return self::$mailer;
    }

    /**
     * Send email using PHPMailer
     */
    public static function send(string $to, string $subject, string $htmlBody, ?string $textBody = null, ?string $replyTo = null, array $attachments = []): array
    {
        try {
            $mail = self::getMailer();

            // Clear previous recipients
            $mail->clearAddresses();
            $mail->clearReplyTos();
            $mail->clearAttachments();
            foreach($attachments as $attachment){
                $mail->addStringAttachment($attachment['data'],$attachment['name'],'base64',$attachment['type']);
            }

            // Recipient
            $mail->addAddress($to);
            $mail->addReplyTo($replyTo ?: env('SUPPORT_EMAIL', 'support@ieosuia.com'), 'IEOSUIA Support');

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

            $mail->send();

            error_log("Email sent successfully to: $to");
            return [
                'success' => true,
                'message_id' => uniqid('email_'),
            ];

        } catch (\Throwable $e) {
            error_log("PHPMailer Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to send email: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Send verification email
     */
    public static function sendVerificationEmail(string $email, string $name, string $token): array
    {
        $appUrl = env('FRONTEND_URL', env('APP_URL', 'https://sms.ieosuia.com'));
        $verifyUrl = $appUrl . '/verify-email?token=' . urlencode($token);
        $appName = env('SMTP_FROM_NAME', env('MAIL_FROM_NAME', 'IEOSUIA SMS Portal'));

        $subject = 'Verify your email address - ' . $appName;

        $html = self::getEmailTemplate(
            'Verify Your Email',
            "Hello $name,",
            'Thank you for signing up for ' . $appName . '! Please click the button below to verify your email address and activate your account.',
            $verifyUrl,
            'Verify Email Address',
            'If you didn\'t create an account with us, you can safely ignore this email. This link will expire in 24 hours.'
        );

        return self::send($email, $subject, $html, null, env('SECURITY_EMAIL', 'security@ieosuia.com'));
    }

    /**
     * Send password reset email
     */
    public static function sendPasswordResetEmail(string $email, string $name, string $otp): array
    {
        $appName = env('SMTP_FROM_NAME', env('MAIL_FROM_NAME', 'IEOSUIA SMS Portal'));
        
        $subject = 'Reset your password - ' . $appName;

        $html = self::getOtpEmailTemplate(
            'Reset Your Password',
            "Hello $name,",
            'We received a request to reset your password. Use the code below to create a new password. This code will expire in 15 minutes.',
            $otp,
            'If you didn\'t request a password reset, you can safely ignore this email. Your password will remain unchanged.'
        );

        return self::send($email, $subject, $html, null, env('SECURITY_EMAIL', 'security@ieosuia.com'));
    }

    /**
     * Send payment confirmation email
     */
    public static function sendPaymentConfirmationEmail(string $email, string $name, float $amount, string $reference, ?int $purchasedCredits = null): array
    {
        $appUrl = env('FRONTEND_URL', env('APP_URL', 'https://sms.ieosuia.com'));
        $walletUrl = $appUrl . '/wallet';
        $appName = env('SMTP_FROM_NAME', env('MAIL_FROM_NAME', 'IEOSUIA SMS Portal'));
        
        $pricePerCredit = (float) env('SMS_PRICE_PER_SEGMENT', 0.35);
        $credits = $purchasedCredits ?? ($pricePerCredit > 0 ? max(0, (int) floor(($amount + 0.000001) / $pricePerCredit)) : 0);
        $subject = 'SMS credit purchase confirmed';
        
        $html = self::getEmailTemplate(
            'Payment Successful!',
            "Hello $name,",
            'Your payment of <strong>R' . number_format($amount, 2) . '</strong> has been processed. You can now use <strong>' . number_format($credits) . ' SMS credits</strong>; one credit sends one billable SMS segment.<br><br>Reference: <code>' . htmlspecialchars($reference) . '</code>',
            $walletUrl,
            'View Your Wallet',
            'Your purchased SMS credits are now available. We do not charge VAT. If you have questions, contact sms@ieosuia.com with your payment reference.'
        );
        
        return self::send($email, $subject, $html, null, env('BILLING_EMAIL', 'billing@ieosuia.com'));
    }
    
    /**
     * Send welcome email after verification
     */
    public static function sendWelcomeEmail(string $email, string $name): array
    {
        $appUrl = env('FRONTEND_URL', env('APP_URL', 'https://sms.ieosuia.com'));
        $dashboardUrl = $appUrl . '/dashboard';
        $appName = env('SMTP_FROM_NAME', env('MAIL_FROM_NAME', 'IEOSUIA SMS Portal'));

        $subject = 'Welcome to ' . $appName . '! 🎉';

        $html = self::getEmailTemplate(
            'Welcome Aboard!',
            "Hello $name,",
            'Your email has been verified and your account is now fully activated! You\'re all set to start using our powerful SMS and email marketing tools to grow your business.',
            $dashboardUrl,
            'Go to Dashboard',
            'Start creating campaigns today and unlock the power of smart engagement.'
        );

        return self::send($email, $subject, $html);
    }

    /**
     * Get styled email template with button
     */
    private static function getEmailTemplate(
        string $title,
        string $greeting,
        string $message,
        string $buttonUrl,
        string $buttonText,
        string $footer
    ): string {
        $appName = env('SMTP_FROM_NAME', env('MAIL_FROM_NAME', 'IEOSUIA SMS Portal'));
        $year = date('Y');
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f4f4f5;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" style="max-width: 480px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); padding: 32px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 700;">{$appName}</h1>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding: 32px;">
                            <h2 style="color: #18181b; margin: 0 0 16px; font-size: 20px; font-weight: 600;">{$title}</h2>
                            <p style="color: #3f3f46; line-height: 1.6; margin: 0 0 8px; font-size: 15px;">{$greeting}</p>
                            <p style="color: #52525b; line-height: 1.6; margin: 0 0 24px; font-size: 15px;">{$message}</p>
                            
                            <!-- Button -->
                            <table role="presentation" style="margin: 0 auto 24px;">
                                <tr>
                                    <td style="border-radius: 8px; background: #3b82f6;">
                                        <a href="{$buttonUrl}" target="_blank" style="display: inline-block; padding: 14px 32px; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 15px;">{$buttonText}</a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="color: #71717a; font-size: 13px; line-height: 1.5; margin: 0 0 16px;">{$footer}</p>
                            
                            <!-- Link fallback -->
                            <div style="background: #f4f4f5; border-radius: 6px; padding: 12px; margin-top: 16px;">
                                <p style="color: #71717a; font-size: 12px; margin: 0 0 4px;">If the button doesn't work, copy and paste this link into your browser:</p>
                                <p style="color: #3b82f6; font-size: 12px; margin: 0; word-break: break-all;">{$buttonUrl}</p>
                            </div>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background: #f4f4f5; padding: 20px; text-align: center;">
                            <p style="color: #71717a; font-size: 12px; margin: 0;">
                                © {$year} {$appName}. All rights reserved.<br>
                                This email was sent to you because you registered at {$appName}.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    /**
     * Get styled email template with OTP code
     */
    private static function getOtpEmailTemplate(
        string $title,
        string $greeting,
        string $message,
        string $otp,
        string $footer
    ): string {
        $appName = env('SMTP_FROM_NAME', env('MAIL_FROM_NAME', 'IEOSUIA SMS Portal'));
        $year = date('Y');
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f4f4f5;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" style="max-width: 480px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); padding: 32px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 700;">{$appName}</h1>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding: 32px;">
                            <h2 style="color: #18181b; margin: 0 0 16px; font-size: 20px; font-weight: 600;">{$title}</h2>
                            <p style="color: #3f3f46; line-height: 1.6; margin: 0 0 8px; font-size: 15px;">{$greeting}</p>
                            <p style="color: #52525b; line-height: 1.6; margin: 0 0 24px; font-size: 15px;">{$message}</p>
                            
                            <!-- OTP Code -->
                            <div style="background: #f4f4f5; border-radius: 8px; padding: 24px; text-align: center; margin: 0 0 24px;">
                                <span style="font-size: 36px; font-weight: 700; letter-spacing: 8px; color: #18181b; font-family: 'Courier New', monospace;">{$otp}</span>
                            </div>
                            
                            <p style="color: #71717a; font-size: 13px; line-height: 1.5; margin: 0;">{$footer}</p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background: #f4f4f5; padding: 20px; text-align: center;">
                            <p style="color: #71717a; font-size: 12px; margin: 0;">
                                © {$year} {$appName}. All rights reserved.<br>
                                This email was sent to you because you requested a password reset.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
    
    /**
     * Send a simple raw text email
     */
    public static function sendRawEmail(string $to, string $subject, string $body): array
    {
        $html = nl2br(htmlspecialchars($body));
        return self::send($to, $subject, $html, $body);
    }
}
