<?php
/**
 * Contact Form Controller
 * Handles public contact form submissions and sends emails to appropriate departments
 * Includes reCAPTCHA validation, rate limiting, and email delivery logging
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
    /**
     * Submit contact form - public endpoint with spam protection
     */
    public static function submit(): void
    {
        $data = Request::all();
        
        // Get client info for logging
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // Rate limiting - 5 submissions per 15 minutes per IP
        RateLimiter::checkOrFail("contact_form:{$ip}", 5, 15);
        
        // Verify reCAPTCHA
        RecaptchaValidator::verifyOrFail($data['recaptcha_token'] ?? '', 'contact_form');
        
        // Validate required fields
        $required = ['name', 'email', 'message', 'purpose'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                Response::error("Missing required field: $field", 400);
                return;
            }
        }
        
        // Validate input lengths
        if (strlen($data['name']) > 100) {
            Response::error('Name must be less than 100 characters', 400);
            return;
        }
        if (strlen($data['email']) > 255) {
            Response::error('Email must be less than 255 characters', 400);
            return;
        }
        if (strlen($data['message']) > 5000) {
            Response::error('Message must be less than 5000 characters', 400);
            return;
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
        
        // Log the email before sending
        $logId = self::logEmail([
            'sender_name' => $name,
            'sender_email' => $senderEmail,
            'recipient_email' => $recipientEmail,
            'purpose' => $purpose,
            'subject' => $subject,
            'message' => $data['message'], // Store original message without HTML encoding
            'origin_url' => $originUrl,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'status' => 'sent', // Will be updated if failed
        ]);
        
        // Send email to department
        $result = self::sendContactEmail($recipientEmail, $subject, $html, $senderEmail, $name);
        
        if (!$result['success']) {
            // Update log with failure
            self::updateEmailLog($logId, [
                'status' => 'failed',
                'error_message' => $result['error'] ?? 'Unknown error',
            ]);
            Response::error('Failed to send message. Please try again later.', 500);
            return;
        }
        
        // Send CC to info@ieosuia.com
        self::sendContactEmail($ccEmail, "[CC] {$subject}", $html, $senderEmail, $name);
        
        // Send confirmation to sender
        $confirmResult = self::sendConfirmationEmail($senderEmail, $name, $purpose, $purposeLabels[$purpose]);
        
        // Update log with confirmation status
        self::updateEmailLog($logId, [
            'confirmation_sent' => $confirmResult['success'] ? 1 : 0,
        ]);
        
        Response::success([
            'message' => 'Your message has been sent successfully. We will get back to you soon.',
            'recipient' => $recipientEmail,
        ], 201);
    }
    
    /**
     * Get all contact form submissions (admin only)
     */
    public static function index(): void
    {
        // Check if user is admin
        $user = Auth::user();
        if (!$user || $user['account_type'] !== 'admin') {
            Response::error('Unauthorized', 403);
            return;
        }
        
        $page = (int) ($_GET['page'] ?? 1);
        $perPage = (int) ($_GET['per_page'] ?? 20);
        $status = $_GET['status'] ?? null;
        $purpose = $_GET['purpose'] ?? null;
        $unreadOnly = isset($_GET['unread']) && $_GET['unread'] === 'true';
        $search = $_GET['search'] ?? null;
        
        $query = table('contact_email_logs');
        
        if ($status) {
            $query->where('status', $status);
        }
        if ($purpose) {
            $query->where('purpose', $purpose);
        }
        if ($unreadOnly) {
            $query->where('read_by_admin', 0);
        }
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('sender_name', 'LIKE', "%{$search}%")
                  ->orWhere('sender_email', 'LIKE', "%{$search}%")
                  ->orWhere('message', 'LIKE', "%{$search}%");
            });
        }
        
        $total = $query->count();
        $emails = $query->orderBy('created_at', 'DESC')
                       ->limit($perPage)
                       ->offset(($page - 1) * $perPage)
                       ->get();
        
        // Get unread count
        $unreadCount = table('contact_email_logs')
            ->where('read_by_admin', 0)
            ->count();
        
        Response::success([
            'emails' => $emails,
            'unread_count' => $unreadCount,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
            ],
        ]);
    }
    
    /**
     * Get single contact form submission (admin only)
     */
    public static function show(array $params): void
    {
        $user = Auth::user();
        if (!$user || $user['account_type'] !== 'admin') {
            Response::error('Unauthorized', 403);
            return;
        }
        
        $id = $params['id'] ?? null;
        if (!$id) {
            Response::error('Missing email ID', 400);
            return;
        }
        
        $email = table('contact_email_logs')->where('id', $id)->first();
        
        if (!$email) {
            Response::error('Email not found', 404);
            return;
        }
        
        // Mark as read
        if (!$email['read_by_admin']) {
            table('contact_email_logs')->where('id', $id)->update([
                'read_by_admin' => 1,
                'read_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $email['read_by_admin'] = 1;
            $email['read_at'] = date('Y-m-d H:i:s');
        }
        
        Response::success(['email' => $email]);
    }
    
    /**
     * Mark email as replied (admin only)
     */
    public static function markReplied(array $params): void
    {
        $user = Auth::user();
        if (!$user || $user['account_type'] !== 'admin') {
            Response::error('Unauthorized', 403);
            return;
        }
        
        $id = $params['id'] ?? null;
        if (!$id) {
            Response::error('Missing email ID', 400);
            return;
        }
        
        $data = Request::all();
        
        table('contact_email_logs')->where('id', $id)->update([
            'replied' => 1,
            'replied_at' => date('Y-m-d H:i:s'),
            'replied_by' => $user['id'],
            'notes' => $data['notes'] ?? null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success(['message' => 'Email marked as replied']);
    }
    
    /**
     * Add note to email (admin only)
     */
    public static function addNote(array $params): void
    {
        $user = Auth::user();
        if (!$user || $user['account_type'] !== 'admin') {
            Response::error('Unauthorized', 403);
            return;
        }
        
        $id = $params['id'] ?? null;
        $data = Request::all();
        
        if (!$id || empty($data['notes'])) {
            Response::error('Missing required fields', 400);
            return;
        }
        
        table('contact_email_logs')->where('id', $id)->update([
            'notes' => $data['notes'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success(['message' => 'Note added successfully']);
    }
    
    /**
     * Log email to database
     */
    private static function logEmail(array $data): int
    {
        try {
            return table('contact_email_logs')->insert([
                'sender_name' => $data['sender_name'],
                'sender_email' => $data['sender_email'],
                'recipient_email' => $data['recipient_email'],
                'purpose' => $data['purpose'],
                'subject' => $data['subject'],
                'message' => $data['message'],
                'status' => $data['status'],
                'origin_url' => $data['origin_url'],
                'ip_address' => $data['ip_address'],
                'user_agent' => $data['user_agent'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            error_log("Failed to log contact email: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Update email log
     */
    private static function updateEmailLog(int $id, array $data): void
    {
        if ($id === 0) return;
        
        try {
            $data['updated_at'] = date('Y-m-d H:i:s');
            table('contact_email_logs')->where('id', $id)->update($data);
        } catch (\Exception $e) {
            error_log("Failed to update contact email log: " . $e->getMessage());
        }
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
    private static function sendConfirmationEmail(string $email, string $name, string $purpose, string $purposeLabel): array
    {
        $appName = env('SMTP_FROM_NAME', 'IEOSUIA SMS Portal');
        $subject = "We received your message - {$appName}";
        
        $html = self::getConfirmationEmailTemplate($name, $purposeLabel);
        
        return self::sendContactEmail($email, $subject, $html, 'noreply@ieosuia.com', $appName);
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
