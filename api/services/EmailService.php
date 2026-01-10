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
            self::$mailer = new PHPMailer(true);

            // Server settings
            self::$mailer->isSMTP();
            self::$mailer->Host = env('SMTP_HOST', env('MAIL_HOST', 'sms.ieosuia.com'));
            self::$mailer->SMTPAuth = true;
            self::$mailer->Username = env('SMTP_USER', env('MAIL_USERNAME', 'noreply@sms.ieosuia.com'));
            self::$mailer->Password = env('SMTP_PASS', env('MAIL_PASSWORD', ''));
            self::$mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            self::$mailer->Port = (int) env('SMTP_PORT', env('MAIL_PORT', 465));

            // Default sender
            self::$mailer->setFrom(
                env('SMTP_FROM_EMAIL', env('MAIL_FROM_ADDRESS', 'noreply@sms.ieosuia.com')),
                env('SMTP_FROM_NAME', env('MAIL_FROM_NAME', 'IEOSUIA SMS Portal'))
            );

            // Encoding
            self::$mailer->CharSet = 'UTF-8';
            self::$mailer->Encoding = 'base64';

            // Debug (set to 0 for production)
            self::$mailer->SMTPDebug = (env('APP_DEBUG', 'false') === 'true') ? SMTP::DEBUG_SERVER : SMTP::DEBUG_OFF;
        }

        return self::$mailer;
    }

    /**
     * Send email using PHPMailer
     */
    public static function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): array
    {
        try {
            $mail = self::getMailer();

            // Clear previous recipients
            $mail->clearAddresses();
            $mail->clearReplyTos();

            // Recipient
            $mail->addAddress($to);

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

        } catch (Exception $e) {
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

        return self::send($email, $subject, $html);
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

        return self::send($email, $subject, $html);
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
}
