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
    public static function send(string $to, string $subject, string $htmlBody, ?string $textBody = null, ?string $replyTo = null): array
    {
        try {
            $mail = self::getMailer();

            // Clear previous recipients
            $mail->clearAddresses();
            $mail->clearReplyTos();

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
    public static function sendPaymentConfirmationEmail(string $email, string $name, float $amount, string $reference): array
    {
        $appUrl = env('FRONTEND_URL', env('APP_URL', 'https://sms.ieosuia.com'));
        $walletUrl = $appUrl . '/wallet';
        $appName = env('SMTP_FROM_NAME', env('MAIL_FROM_NAME', 'IEOSUIA SMS Portal'));
        
        $pricePerCredit = (float) env('SMS_PRICE_PER_SEGMENT', 0.35);
        $credits = $pricePerCredit > 0 ? max(0, (int) floor(($amount + 0.000001) / $pricePerCredit)) : 0;
        $subject = 'SMS credit purchase confirmed';
        
        $html = self::getEmailTemplate(
            'Payment Successful!',
            "Hello $name,",
            'Your payment of <strong>R' . number_format($amount, 2) . '</strong> has been processed. You can now use <strong>' . number_format($credits) . ' SMS credits</strong>; one credit sends one billable SMS segment.<br><br>Reference: <code>' . htmlspecialchars($reference) . '</code>',
            $walletUrl,
            'View Your Wallet',
            'Your SMS credits are available immediately. Any amount smaller than one full credit remains safely available toward future SMS usage. If you have questions, please contact support.'
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
