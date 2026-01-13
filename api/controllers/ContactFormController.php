<?php
/**
 * Contact Form Controller
 * Handles public contact form submissions and sends emails to appropriate departments
 */

require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';
require_once __DIR__ . '/../services/EmailService.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class ContactFormController
{
    public static function submit(): void
    {
        $data = Request::all();
        
        // Validate required fields
        $required = ['name', 'email', 'message', 'purpose'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                Response::error("Missing required field: $field", 400);
                return;
            }
        }
        
        // Validate email format
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email address', 400);
            return;
        }
        
        // Validate purpose and get recipient email
        $purposeEmails = [
            'general' => 'hello@ieosuia.com',
            'support' => 'support@ieosuia.com',
            'sales' => 'sales@ieosuia.com',
        ];
        
        $purpose = $data['purpose'];
        if (!isset($purposeEmails[$purpose])) {
            Response::error('Invalid inquiry purpose', 400);
            return;
        }
        
        $recipientEmail = $purposeEmails[$purpose];
        $ccEmail = 'info@ieosuia.com';
        
        // Sanitize inputs
        $name = htmlspecialchars(trim($data['name']), ENT_QUOTES, 'UTF-8');
        $senderEmail = filter_var(trim($data['email']), FILTER_SANITIZE_EMAIL);
        $message = htmlspecialchars(trim($data['message']), ENT_QUOTES, 'UTF-8');
        $originUrl = isset($data['originUrl']) ? htmlspecialchars(trim($data['originUrl']), ENT_QUOTES, 'UTF-8') : 'Not provided';
        
        // Build subject
        $purposeLabels = [
            'general' => 'General Inquiry',
            'support' => 'Support Request',
            'sales' => 'Sales Inquiry',
        ];
        $subject = "[{$purposeLabels[$purpose]}] Contact Form - {$name}";
        
        // Build HTML email body
        $html = self::getContactEmailTemplate($name, $senderEmail, $message, $purpose, $purposeLabels[$purpose], $originUrl);
        
        // Send email to department
        $result = self::sendContactEmail($recipientEmail, $subject, $html, $senderEmail, $name);
        
        if (!$result['success']) {
            Response::error('Failed to send message. Please try again later.', 500);
            return;
        }
        
        // Send CC to info@ieosuia.com
        self::sendContactEmail($ccEmail, "[CC] {$subject}", $html, $senderEmail, $name);
        
        // Send confirmation to sender
        self::sendConfirmationEmail($senderEmail, $name, $purpose, $purposeLabels[$purpose]);
        
        Response::success([
            'message' => 'Your message has been sent successfully. We will get back to you soon.',
            'recipient' => $recipientEmail,
        ], 201);
    }
    
    /**
     * Send contact email using PHPMailer
     */
    private static function sendContactEmail(string $to, string $subject, string $html, string $replyTo, string $replyToName): array
    {
        try {
            // Get SMTP settings
            require_once __DIR__ . '/SmtpSettingsController.php';
            $settings = SmtpSettingsController::getSettings('system');
            
            $mail = new PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = $settings['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $settings['username'];
            $mail->Password = $settings['password'];
            $mail->Port = $settings['port'];
            
            switch ($settings['encryption']) {
                case 'ssl':
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    break;
                case 'tls':
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    break;
                default:
                    $mail->SMTPSecure = '';
                    $mail->SMTPAutoTLS = false;
            }
            
            // Sender
            $mail->setFrom($settings['from_email'], $settings['from_name']);
            $mail->addReplyTo($replyTo, $replyToName);
            
            // Recipient
            $mail->addAddress($to);
            
            // Content
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
            
            $mail->send();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            error_log("Contact form email error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send confirmation email to the sender
     */
    private static function sendConfirmationEmail(string $email, string $name, string $purpose, string $purposeLabel): void
    {
        $appName = env('SMTP_FROM_NAME', 'IEOSUIA SMS Portal');
        $subject = "We received your message - {$appName}";
        
        $html = self::getConfirmationEmailTemplate($name, $purposeLabel);
        
        self::sendContactEmail($email, $subject, $html, 'noreply@ieosuia.com', $appName);
    }
    
    /**
     * Get contact form email template
     */
    private static function getContactEmailTemplate(
        string $name,
        string $email,
        string $message,
        string $purpose,
        string $purposeLabel,
        string $originUrl
    ): string {
        $appName = env('SMTP_FROM_NAME', 'IEOSUIA SMS Portal');
        $date = date('F j, Y \a\t g:i A');
        $messageHtml = nl2br($message);
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Contact Form Submission</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f4f4f5;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); padding: 32px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 700;">New Contact Form Submission</h1>
                            <p style="color: rgba(255,255,255,0.9); margin: 8px 0 0; font-size: 14px;">{$purposeLabel}</p>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding: 32px;">
                            <!-- Sender Info -->
                            <table role="presentation" style="width: 100%; margin-bottom: 24px; background: #f4f4f5; border-radius: 8px;">
                                <tr>
                                    <td style="padding: 16px;">
                                        <p style="margin: 0 0 8px; color: #71717a; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">From</p>
                                        <p style="margin: 0; color: #18181b; font-size: 16px; font-weight: 600;">{$name}</p>
                                        <p style="margin: 4px 0 0; color: #3b82f6; font-size: 14px;">
                                            <a href="mailto:{$email}" style="color: #3b82f6; text-decoration: none;">{$email}</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Message -->
                            <div style="margin-bottom: 24px;">
                                <p style="margin: 0 0 8px; color: #71717a; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Message</p>
                                <div style="background: #fafafa; border-left: 4px solid #3b82f6; padding: 16px; border-radius: 0 8px 8px 0;">
                                    <p style="margin: 0; color: #3f3f46; font-size: 15px; line-height: 1.6;">{$messageHtml}</p>
                                </div>
                            </div>
                            
                            <!-- Reply Button -->
                            <table role="presentation" style="margin: 24px 0;">
                                <tr>
                                    <td style="border-radius: 8px; background: #3b82f6;">
                                        <a href="mailto:{$email}?subject=Re: {$purposeLabel} - {$appName}" style="display: inline-block; padding: 14px 32px; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 15px;">Reply to {$name}</a>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Meta Info -->
                            <div style="border-top: 1px solid #e4e4e7; padding-top: 16px; margin-top: 24px;">
                                <p style="margin: 0 0 4px; color: #a1a1aa; font-size: 12px;">
                                    <strong>Submitted:</strong> {$date}
                                </p>
                                <p style="margin: 0 0 4px; color: #a1a1aa; font-size: 12px;">
                                    <strong>Category:</strong> {$purposeLabel}
                                </p>
                                <p style="margin: 0; color: #a1a1aa; font-size: 12px;">
                                    <strong>Origin:</strong> {$originUrl}
                                </p>
                            </div>
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
     * Get confirmation email template for sender
     */
    private static function getConfirmationEmailTemplate(string $name, string $purposeLabel): string
    {
        $appName = env('SMTP_FROM_NAME', 'IEOSUIA SMS Portal');
        $year = date('Y');
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>We Received Your Message</title>
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
                            <h2 style="color: #18181b; margin: 0 0 16px; font-size: 20px; font-weight: 600;">We've Got Your Message! 📨</h2>
                            <p style="color: #3f3f46; line-height: 1.6; margin: 0 0 8px; font-size: 15px;">Hello {$name},</p>
                            <p style="color: #52525b; line-height: 1.6; margin: 0 0 24px; font-size: 15px;">
                                Thank you for reaching out to us. We've received your <strong>{$purposeLabel}</strong> and our team will review it shortly.
                            </p>
                            <p style="color: #52525b; line-height: 1.6; margin: 0 0 24px; font-size: 15px;">
                                We typically respond within <strong>24-48 business hours</strong>. If your matter is urgent, please don't hesitate to reach us via WhatsApp at <a href="https://wa.me/27799282775" style="color: #3b82f6;">+27 79 928 2775</a>.
                            </p>
                            <p style="color: #71717a; font-size: 13px; line-height: 1.5; margin: 0;">
                                Best regards,<br>
                                The {$appName} Team
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background: #f4f4f5; padding: 20px; text-align: center;">
                            <p style="color: #71717a; font-size: 12px; margin: 0;">
                                © {$year} {$appName}. All rights reserved.
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
