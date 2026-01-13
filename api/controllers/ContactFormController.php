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
        
        // Send notification to admin about new contact form submission
        self::sendAdminNotification($name, $senderEmail, $purpose, $purposeLabels[$purpose], $logId);
        
        // Push realtime notification for admin dashboard
        self::pushRealtimeNotification($name, $senderEmail, $purpose, $purposeLabels[$purpose], $logId);
        
        // Send alerts to configured recipients
        self::sendAlertEmails($name, $senderEmail, $purpose, $purposeLabels[$purpose], $data['message']);
        
        Response::success([
            'message' => 'Your message has been sent successfully. We will get back to you soon.',
            'recipient' => $recipientEmail,
        ], 201);
    }
    
    /**
     * Push realtime notification for admin dashboard
     */
    private static function pushRealtimeNotification(string $senderName, string $senderEmail, string $purpose, string $purposeLabel, int $logId): void
    {
        try {
            require_once __DIR__ . '/RealtimeController.php';
            RealtimeController::push(
                'admin',
                'new_contact_submission',
                "New Contact: {$purposeLabel}",
                "New message from {$senderName} ({$senderEmail})",
                [
                    'log_id' => $logId,
                    'sender_name' => $senderName,
                    'sender_email' => $senderEmail,
                    'purpose' => $purpose,
                    'purpose_label' => $purposeLabel,
                ]
            );
        } catch (\Exception $e) {
            error_log("Failed to push realtime notification: " . $e->getMessage());
        }
    }
    
    /**
     * Send alert emails to configured recipients
     */
    private static function sendAlertEmails(string $senderName, string $senderEmail, string $purpose, string $purposeLabel, string $message): void
    {
        try {
            require_once __DIR__ . '/ContactAlertController.php';
            $recipients = ContactAlertController::getRecipientsForPurpose($purpose);
            
            if (empty($recipients)) {
                return;
            }
            
            $appName = env('SMTP_FROM_NAME', 'IEOSUIA SMS Portal');
            $subject = "🔔 New {$purposeLabel} from {$senderName}";
            $frontendUrl = env('FRONTEND_URL', 'https://sms.ieosuia.com');
            
            $messagePreview = strlen($message) > 200 ? substr($message, 0, 200) . '...' : $message;
            $messagePreview = htmlspecialchars($messagePreview, ENT_QUOTES, 'UTF-8');
            $date = date('F j, Y \a\t g:i A');
            
            $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f4f4f5;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" style="max-width: 480px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); padding: 24px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 18px;">🔔 New Contact Form Alert</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px;">
                            <div style="background: #f4f4f5; border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                                <p style="margin: 0; color: #71717a; font-size: 12px;">CATEGORY</p>
                                <p style="margin: 4px 0 0; color: #18181b; font-size: 16px; font-weight: 600;">{$purposeLabel}</p>
                            </div>
                            <p style="margin: 0 0 8px; color: #71717a; font-size: 14px;">From: <strong style="color: #18181b;">{$senderName}</strong></p>
                            <p style="margin: 0 0 16px; color: #3b82f6; font-size: 14px;"><a href="mailto:{$senderEmail}" style="color: #3b82f6;">{$senderEmail}</a></p>
                            <div style="background: #fafafa; border-left: 3px solid #3b82f6; padding: 12px; margin-bottom: 16px;">
                                <p style="margin: 0; color: #3f3f46; font-size: 14px; line-height: 1.5;">{$messagePreview}</p>
                            </div>
                            <p style="margin: 0 0 16px; color: #a1a1aa; font-size: 12px;">Received: {$date}</p>
                            <a href="{$frontendUrl}/admin" style="display: inline-block; padding: 10px 20px; background: #3b82f6; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500;">View in Dashboard</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
            
            foreach ($recipients as $recipient) {
                try {
                    self::sendContactEmail($recipient['email'], $subject, $html, 'noreply@ieosuia.com', $appName);
                } catch (\Exception $e) {
                    error_log("Failed to send alert to {$recipient['email']}: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to send alert emails: " . $e->getMessage());
        }
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
     * Get contact email statistics (admin only)
     */
    public static function stats(): void
    {
        $user = Auth::user();
        if (!$user || $user['account_type'] !== 'admin') {
            Response::error('Unauthorized', 403);
            return;
        }
        
        $db = db();
        
        // Total submissions
        $total = table('contact_email_logs')->count();
        
        // Status breakdown
        $sent = table('contact_email_logs')->where('status', 'sent')->count();
        $failed = table('contact_email_logs')->where('status', 'failed')->count();
        $bounced = table('contact_email_logs')->where('status', 'bounced')->count();
        
        // Read/Unread counts
        $read = table('contact_email_logs')->where('read_by_admin', 1)->count();
        $unread = table('contact_email_logs')->where('read_by_admin', 0)->count();
        
        // Reply stats
        $replied = table('contact_email_logs')->where('replied', 1)->count();
        $pending = table('contact_email_logs')->where('replied', 0)->count();
        
        // Response rate (replied / total that were read)
        $responseRate = $read > 0 ? round(($replied / $read) * 100, 1) : 0;
        
        // Calculate average response time (time between created_at and replied_at)
        $avgResponseTime = null;
        $avgResponseStmt = $db->prepare("
            SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, replied_at)) as avg_seconds
            FROM contact_email_logs 
            WHERE replied = 1 AND replied_at IS NOT NULL
        ");
        $avgResponseStmt->execute();
        $avgResult = $avgResponseStmt->fetch(\PDO::FETCH_ASSOC);
        if ($avgResult && $avgResult['avg_seconds']) {
            $seconds = (int) $avgResult['avg_seconds'];
            if ($seconds < 60) {
                $avgResponseTime = $seconds . ' seconds';
            } elseif ($seconds < 3600) {
                $avgResponseTime = round($seconds / 60) . ' minutes';
            } elseif ($seconds < 86400) {
                $avgResponseTime = round($seconds / 3600, 1) . ' hours';
            } else {
                $avgResponseTime = round($seconds / 86400, 1) . ' days';
            }
        }
        
        // Purpose breakdown
        $general = table('contact_email_logs')->where('purpose', 'general')->count();
        $support = table('contact_email_logs')->where('purpose', 'support')->count();
        $sales = table('contact_email_logs')->where('purpose', 'sales')->count();
        
        // This week's submissions
        $thisWeek = table('contact_email_logs')
            ->whereRaw('created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')
            ->count();
        
        // Today's submissions
        $today = table('contact_email_logs')
            ->whereRaw('DATE(created_at) = CURDATE()')
            ->count();
        
        Response::success([
            'stats' => [
                'total' => $total,
                'today' => $today,
                'this_week' => $thisWeek,
                'status' => [
                    'sent' => $sent,
                    'failed' => $failed,
                    'bounced' => $bounced,
                ],
                'read_status' => [
                    'read' => $read,
                    'unread' => $unread,
                ],
                'reply_status' => [
                    'replied' => $replied,
                    'pending' => $pending,
                    'response_rate' => $responseRate,
                    'avg_response_time' => $avgResponseTime,
                ],
                'purpose' => [
                    'general' => $general,
                    'support' => $support,
                    'sales' => $sales,
                ],
            ],
        ]);
    }
    
    /**
     * Get submission trends for the last 30 days (admin only)
     */
    public static function trends(): void
    {
        $user = Auth::user();
        if (!$user || $user['account_type'] !== 'admin') {
            Response::error('Unauthorized', 403);
            return;
        }
        
        $db = db();
        $days = (int) ($_GET['days'] ?? 30);
        $days = min(max($days, 7), 90); // Limit between 7 and 90 days
        
        // Get daily submission counts
        $stmt = $db->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN purpose = 'general' THEN 1 ELSE 0 END) as general,
                SUM(CASE WHEN purpose = 'support' THEN 1 ELSE 0 END) as support,
                SUM(CASE WHEN purpose = 'sales' THEN 1 ELSE 0 END) as sales,
                SUM(CASE WHEN replied = 1 THEN 1 ELSE 0 END) as replied,
                SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) as bounced
            FROM contact_email_logs
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$days]);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Create a complete date range with zeros for missing days
        $trends = [];
        $startDate = new \DateTime("-{$days} days");
        $endDate = new \DateTime();
        $interval = new \DateInterval('P1D');
        $period = new \DatePeriod($startDate, $interval, $endDate->modify('+1 day'));
        
        $dataByDate = [];
        foreach ($results as $row) {
            $dataByDate[$row['date']] = $row;
        }
        
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            if (isset($dataByDate[$dateStr])) {
                $trends[] = [
                    'date' => $dateStr,
                    'total' => (int) $dataByDate[$dateStr]['total'],
                    'general' => (int) $dataByDate[$dateStr]['general'],
                    'support' => (int) $dataByDate[$dateStr]['support'],
                    'sales' => (int) $dataByDate[$dateStr]['sales'],
                    'replied' => (int) $dataByDate[$dateStr]['replied'],
                    'bounced' => (int) $dataByDate[$dateStr]['bounced'],
                ];
            } else {
                $trends[] = [
                    'date' => $dateStr,
                    'total' => 0,
                    'general' => 0,
                    'support' => 0,
                    'sales' => 0,
                    'replied' => 0,
                    'bounced' => 0,
                ];
            }
        }
        
        Response::success(['trends' => $trends]);
    }
    
    /**
     * Export contact email logs as CSV (admin only)
     */
    public static function exportCsv(): void
    {
        $user = Auth::user();
        if (!$user || $user['account_type'] !== 'admin') {
            Response::error('Unauthorized', 403);
            return;
        }
        
        $status = $_GET['status'] ?? null;
        $purpose = $_GET['purpose'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        
        $query = table('contact_email_logs');
        
        if ($status) {
            $query->where('status', $status);
        }
        if ($purpose) {
            $query->where('purpose', $purpose);
        }
        if ($dateFrom) {
            $query->whereRaw('DATE(created_at) >= ?', [$dateFrom]);
        }
        if ($dateTo) {
            $query->whereRaw('DATE(created_at) <= ?', [$dateTo]);
        }
        
        $emails = $query->orderBy('created_at', 'DESC')->get();
        
        // Generate CSV
        $filename = 'contact_emails_' . date('Y-m-d_His') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // CSV Headers
        fputcsv($output, [
            'ID',
            'Sender Name',
            'Sender Email',
            'Recipient',
            'Category',
            'Subject',
            'Message',
            'Status',
            'Read',
            'Replied',
            'Response Time',
            'Origin URL',
            'IP Address',
            'Notes',
            'Created At',
            'Replied At',
        ]);
        
        foreach ($emails as $email) {
            $responseTime = null;
            if ($email['replied'] && $email['replied_at']) {
                $created = new \DateTime($email['created_at']);
                $replied = new \DateTime($email['replied_at']);
                $diff = $created->diff($replied);
                if ($diff->days > 0) {
                    $responseTime = $diff->days . ' days ' . $diff->h . ' hours';
                } elseif ($diff->h > 0) {
                    $responseTime = $diff->h . ' hours ' . $diff->i . ' minutes';
                } else {
                    $responseTime = $diff->i . ' minutes';
                }
            }
            
            fputcsv($output, [
                $email['id'],
                $email['sender_name'],
                $email['sender_email'],
                $email['recipient_email'],
                $email['purpose'],
                $email['subject'],
                $email['message'],
                $email['status'],
                $email['read_by_admin'] ? 'Yes' : 'No',
                $email['replied'] ? 'Yes' : 'No',
                $responseTime ?? 'N/A',
                $email['origin_url'] ?? '',
                $email['ip_address'] ?? '',
                $email['notes'] ?? '',
                $email['created_at'],
                $email['replied_at'] ?? '',
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Export statistics as JSON for PDF generation (admin only)
     */
    public static function exportReport(): void
    {
        $user = Auth::user();
        if (!$user || $user['account_type'] !== 'admin') {
            Response::error('Unauthorized', 403);
            return;
        }
        
        $db = db();
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        
        // Get stats for the period
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) as bounced,
                SUM(CASE WHEN read_by_admin = 1 THEN 1 ELSE 0 END) as `read`,
                SUM(CASE WHEN replied = 1 THEN 1 ELSE 0 END) as replied,
                SUM(CASE WHEN purpose = 'general' THEN 1 ELSE 0 END) as general,
                SUM(CASE WHEN purpose = 'support' THEN 1 ELSE 0 END) as support,
                SUM(CASE WHEN purpose = 'sales' THEN 1 ELSE 0 END) as sales
            FROM contact_email_logs
            WHERE DATE(created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $stats = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // Get daily trends
        $trendStmt = $db->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as total
            FROM contact_email_logs
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $trendStmt->execute([$dateFrom, $dateTo]);
        $trends = $trendStmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Calculate response rate
        $responseRate = (int) $stats['read'] > 0 
            ? round(((int) $stats['replied'] / (int) $stats['read']) * 100, 1) 
            : 0;
        
        // Get average response time
        $avgStmt = $db->prepare("
            SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, replied_at)) as avg_hours
            FROM contact_email_logs 
            WHERE replied = 1 AND replied_at IS NOT NULL
            AND DATE(created_at) BETWEEN ? AND ?
        ");
        $avgStmt->execute([$dateFrom, $dateTo]);
        $avgResult = $avgStmt->fetch(\PDO::FETCH_ASSOC);
        $avgResponseHours = $avgResult['avg_hours'] ? round((float) $avgResult['avg_hours'], 1) : null;
        
        Response::success([
            'report' => [
                'period' => [
                    'from' => $dateFrom,
                    'to' => $dateTo,
                ],
                'summary' => [
                    'total_submissions' => (int) $stats['total'],
                    'sent' => (int) $stats['sent'],
                    'failed' => (int) $stats['failed'],
                    'bounced' => (int) $stats['bounced'],
                    'read' => (int) $stats['read'],
                    'replied' => (int) $stats['replied'],
                    'response_rate' => $responseRate,
                    'avg_response_hours' => $avgResponseHours,
                ],
                'by_category' => [
                    'general' => (int) $stats['general'],
                    'support' => (int) $stats['support'],
                    'sales' => (int) $stats['sales'],
                ],
                'daily_trends' => $trends,
                'generated_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    }
    
    /**
     * Handle email bounce webhook
     * Updates the status of bounced emails in the database
     */
    public static function bounceWebhook(): void
    {
        // Get raw POST data
        $rawData = file_get_contents('php://input');
        $data = json_decode($rawData, true);
        
        // Validate webhook secret if configured
        $webhookSecret = env('EMAIL_WEBHOOK_SECRET', '');
        if ($webhookSecret) {
            $providedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
            if (!hash_equals($webhookSecret, $providedSecret)) {
                Response::error('Invalid webhook secret', 401);
                return;
            }
        }
        
        // Log webhook receipt for debugging
        error_log("Email bounce webhook received: " . $rawData);
        
        // Extract bounce info based on common ESP formats
        $email = null;
        $bounceType = 'hard'; // hard or soft
        $reason = null;
        $timestamp = date('Y-m-d H:i:s');
        
        // Amazon SES format
        if (isset($data['notificationType']) && $data['notificationType'] === 'Bounce') {
            $bounce = $data['bounce'] ?? [];
            $email = $bounce['bouncedRecipients'][0]['emailAddress'] ?? null;
            $bounceType = strtolower($bounce['bounceType'] ?? 'hard');
            $reason = $bounce['bouncedRecipients'][0]['diagnosticCode'] ?? null;
        }
        // Mailgun format
        elseif (isset($data['event']) && in_array($data['event'], ['bounced', 'failed'])) {
            $email = $data['recipient'] ?? null;
            $bounceType = ($data['severity'] ?? '') === 'temporary' ? 'soft' : 'hard';
            $reason = $data['reason'] ?? ($data['delivery-status']['message'] ?? null);
        }
        // SendGrid format
        elseif (isset($data[0]['event']) && in_array($data[0]['event'], ['bounce', 'dropped'])) {
            $email = $data[0]['email'] ?? null;
            $bounceType = $data[0]['type'] ?? 'hard';
            $reason = $data[0]['reason'] ?? null;
        }
        // Postmark format
        elseif (isset($data['RecordType']) && $data['RecordType'] === 'Bounce') {
            $email = $data['Email'] ?? null;
            $bounceType = $data['Type'] ?? 'hard';
            $reason = $data['Description'] ?? null;
        }
        // Generic format
        elseif (isset($data['email'])) {
            $email = $data['email'];
            $bounceType = $data['type'] ?? 'hard';
            $reason = $data['reason'] ?? null;
        }
        
        if (!$email) {
            Response::error('No email address found in webhook payload', 400);
            return;
        }
        
        // Update the email log status
        $updated = table('contact_email_logs')
            ->where('sender_email', $email)
            ->where('status', 'sent')
            ->update([
                'status' => 'bounced',
                'error_message' => $reason ? substr($reason, 0, 500) : "Email bounced ({$bounceType})",
                'updated_at' => $timestamp,
            ]);
        
        // Also update by recipient email (for confirmation emails that bounced)
        $updatedRecipient = table('contact_email_logs')
            ->where('recipient_email', $email)
            ->where('status', 'sent')
            ->update([
                'status' => 'bounced',
                'error_message' => $reason ? substr($reason, 0, 500) : "Email bounced ({$bounceType})",
                'updated_at' => $timestamp,
            ]);
        
        $totalUpdated = $updated + $updatedRecipient;
        
        // Log the bounce for auditing
        error_log("Email bounce processed: {$email} ({$bounceType}) - {$totalUpdated} records updated. Reason: {$reason}");
        
        Response::success([
            'message' => 'Bounce processed',
            'email' => $email,
            'bounce_type' => $bounceType,
            'records_updated' => $totalUpdated,
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
    
    /**
     * Send admin notification about new contact form submission
     */
    private static function sendAdminNotification(string $senderName, string $senderEmail, string $purpose, string $purposeLabel, int $logId): void
    {
        $appName = env('SMTP_FROM_NAME', 'IEOSUIA SMS Portal');
        $adminEmail = 'admin@ieosuia.com';
        $frontendUrl = env('FRONTEND_URL', 'https://sms.ieosuia.com');
        $subject = "🔔 New Contact Form: {$purposeLabel} from {$senderName}";
        
        $date = date('F j, Y \a\t g:i A');
        
        $html = <<<HTML
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
                <table role="presentation" style="max-width: 480px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); padding: 24px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 20px; font-weight: 700;">🔔 New Contact Form Submission</h1>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding: 24px;">
                            <div style="background: #f4f4f5; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                                <p style="margin: 0 0 4px; color: #71717a; font-size: 12px; text-transform: uppercase;">Category</p>
                                <p style="margin: 0; color: #18181b; font-size: 16px; font-weight: 600;">{$purposeLabel}</p>
                            </div>
                            <table style="width: 100%; margin-bottom: 16px;">
                                <tr>
                                    <td style="color: #71717a; font-size: 14px; padding: 4px 0;">From:</td>
                                    <td style="color: #18181b; font-size: 14px; font-weight: 500; padding: 4px 0;">{$senderName}</td>
                                </tr>
                                <tr>
                                    <td style="color: #71717a; font-size: 14px; padding: 4px 0;">Email:</td>
                                    <td style="color: #3b82f6; font-size: 14px; padding: 4px 0;">
                                        <a href="mailto:{$senderEmail}" style="color: #3b82f6; text-decoration: none;">{$senderEmail}</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="color: #71717a; font-size: 14px; padding: 4px 0;">Time:</td>
                                    <td style="color: #18181b; font-size: 14px; padding: 4px 0;">{$date}</td>
                                </tr>
                            </table>
                            <table role="presentation" style="margin: 0 auto;">
                                <tr>
                                    <td style="border-radius: 8px; background: #3b82f6;">
                                        <a href="{$frontendUrl}/admin" style="display: inline-block; padding: 12px 24px; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 14px;">View in Admin Dashboard</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
        
        self::sendContactEmail($adminEmail, $subject, $html, 'noreply@ieosuia.com', $appName);
    }
}
