<?php
/**
 * Email Service - SMTP via PHPMailer
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Include PHPMailer if using Composer autoload
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

class EmailService {
    private ?PHPMailer $mailer = null;
    private string $fromEmail;
    private string $fromName;
    private string $appUrl;
    
    public function __construct() {
        $this->fromEmail = env('MAIL_FROM_ADDRESS', 'noreply@ieosuia.com');
        $this->fromName = env('MAIL_FROM_NAME', 'IEOSUIA SMS Portal');
        $this->appUrl = env('APP_URL', 'https://ieosuia.com');
        
        // Initialize PHPMailer if available
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $this->mailer = new PHPMailer(true);
            $this->configureSMTP();
        }
    }
    
    private function configureSMTP(): void {
        if (!$this->mailer) return;
        
        try {
            $this->mailer->isSMTP();
            $this->mailer->Host = env('MAIL_HOST', 'smtp.gmail.com');
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = env('MAIL_USERNAME', '');
            $this->mailer->Password = env('MAIL_PASSWORD', '');
            $this->mailer->SMTPSecure = env('MAIL_ENCRYPTION', 'tls') === 'ssl' 
                ? PHPMailer::ENCRYPTION_SMTPS 
                : PHPMailer::ENCRYPTION_STARTTLS;
            $this->mailer->Port = (int) env('MAIL_PORT', 587);
            $this->mailer->setFrom($this->fromEmail, $this->fromName);
            $this->mailer->isHTML(true);
            $this->mailer->CharSet = 'UTF-8';
        } catch (Exception $e) {
            error_log('PHPMailer configuration error: ' . $e->getMessage());
        }
    }
    
    /**
     * Send a generic email
     */
    public function send(string $to, string $subject, string $body): array {
        if ($this->mailer) {
            return $this->sendViaSMTP($to, $subject, $body);
        }
        return $this->sendViaMail($to, $subject, $body);
    }
    
    /**
     * Send email verification
     */
    public function sendVerificationEmail(string $to, string $name, string $token): array {
        $verifyUrl = $this->appUrl . '/verify-email?token=' . urlencode($token);
        
        $subject = 'Verify Your Email Address - IEOSUIA SMS';
        $body = $this->renderTemplate('verification', [
            'name' => $name,
            'verifyUrl' => $verifyUrl,
            'appName' => $this->fromName,
        ]);
        
        return $this->send($to, $subject, $body);
    }
    
    /**
     * Send password reset OTP
     */
    public function sendPasswordResetEmail(string $to, string $name, string $otp): array {
        $subject = 'Password Reset Code - IEOSUIA SMS';
        $body = $this->renderTemplate('password-reset', [
            'name' => $name,
            'otp' => $otp,
            'appName' => $this->fromName,
            'expiresIn' => '15 minutes',
        ]);
        
        return $this->send($to, $subject, $body);
    }
    
    /**
     * Send welcome email after registration
     */
    public function sendWelcomeEmail(string $to, string $name): array {
        $subject = 'Welcome to IEOSUIA SMS!';
        $body = $this->renderTemplate('welcome', [
            'name' => $name,
            'appName' => $this->fromName,
            'loginUrl' => $this->appUrl . '/login',
        ]);
        
        return $this->send($to, $subject, $body);
    }
    
    private function sendViaSMTP(string $to, string $subject, string $body): array {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
            
            $this->mailer->send();
            
            return [
                'success' => true,
                'message_id' => uniqid('email_'),
            ];
        } catch (Exception $e) {
            error_log('Email sending failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to send email: ' . $e->getMessage(),
            ];
        }
    }
    
    private function sendViaMail(string $to, string $subject, string $body): array {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            "From: {$this->fromName} <{$this->fromEmail}>",
            "Reply-To: {$this->fromEmail}",
        ];
        
        $result = @mail($to, $subject, $body, implode("\r\n", $headers));
        
        if ($result) {
            return ['success' => true, 'message_id' => uniqid('email_')];
        }
        
        return ['success' => false, 'error' => 'Failed to send email via mail()'];
    }
    
    private function renderTemplate(string $template, array $data): string {
        $templates = [
            'verification' => '
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                </head>
                <body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; background-color: #f4f4f5; margin: 0; padding: 40px 20px;">
                    <div style="max-width: 480px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                        <div style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); padding: 32px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px;">{{appName}}</h1>
                        </div>
                        <div style="padding: 32px;">
                            <h2 style="color: #18181b; margin: 0 0 16px;">Verify Your Email</h2>
                            <p style="color: #52525b; line-height: 1.6; margin: 0 0 24px;">
                                Hi {{name}},<br><br>
                                Thank you for signing up! Please verify your email address by clicking the button below.
                            </p>
                            <a href="{{verifyUrl}}" style="display: inline-block; background: #3b82f6; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 600;">
                                Verify Email Address
                            </a>
                            <p style="color: #71717a; font-size: 14px; margin: 24px 0 0;">
                                This link will expire in 24 hours. If you didn\'t create an account, you can safely ignore this email.
                            </p>
                        </div>
                        <div style="background: #f4f4f5; padding: 20px; text-align: center;">
                            <p style="color: #71717a; font-size: 12px; margin: 0;">
                                © ' . date('Y') . ' {{appName}}. All rights reserved.
                            </p>
                        </div>
                    </div>
                </body>
                </html>
            ',
            
            'password-reset' => '
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                </head>
                <body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; background-color: #f4f4f5; margin: 0; padding: 40px 20px;">
                    <div style="max-width: 480px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                        <div style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); padding: 32px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px;">{{appName}}</h1>
                        </div>
                        <div style="padding: 32px;">
                            <h2 style="color: #18181b; margin: 0 0 16px;">Password Reset Code</h2>
                            <p style="color: #52525b; line-height: 1.6; margin: 0 0 24px;">
                                Hi {{name}},<br><br>
                                You requested a password reset. Use the code below to reset your password:
                            </p>
                            <div style="background: #f4f4f5; border-radius: 8px; padding: 20px; text-align: center; margin: 0 0 24px;">
                                <span style="font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #18181b;">{{otp}}</span>
                            </div>
                            <p style="color: #71717a; font-size: 14px; margin: 0;">
                                This code will expire in {{expiresIn}}. If you didn\'t request a password reset, please ignore this email or contact support.
                            </p>
                        </div>
                        <div style="background: #f4f4f5; padding: 20px; text-align: center;">
                            <p style="color: #71717a; font-size: 12px; margin: 0;">
                                © ' . date('Y') . ' {{appName}}. All rights reserved.
                            </p>
                        </div>
                    </div>
                </body>
                </html>
            ',
            
            'welcome' => '
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                </head>
                <body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; background-color: #f4f4f5; margin: 0; padding: 40px 20px;">
                    <div style="max-width: 480px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                        <div style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); padding: 32px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px;">Welcome! 🎉</h1>
                        </div>
                        <div style="padding: 32px;">
                            <h2 style="color: #18181b; margin: 0 0 16px;">Hi {{name}}!</h2>
                            <p style="color: #52525b; line-height: 1.6; margin: 0 0 24px;">
                                Welcome to {{appName}}! We\'re excited to have you on board.<br><br>
                                You now have access to powerful SMS and email marketing tools to help grow your business.
                            </p>
                            <a href="{{loginUrl}}" style="display: inline-block; background: #3b82f6; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 600;">
                                Go to Dashboard
                            </a>
                        </div>
                        <div style="background: #f4f4f5; padding: 20px; text-align: center;">
                            <p style="color: #71717a; font-size: 12px; margin: 0;">
                                © ' . date('Y') . ' {{appName}}. All rights reserved.
                            </p>
                        </div>
                    </div>
                </body>
                </html>
            ',
        ];
        
        $html = $templates[$template] ?? '<p>Email content</p>';
        
        foreach ($data as $key => $value) {
            $html = str_replace('{{' . $key . '}}', htmlspecialchars($value), $html);
        }
        
        return $html;
    }
}
