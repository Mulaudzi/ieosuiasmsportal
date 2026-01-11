<?php
/**
 * Batch Email Service - Production-ready email sending with retries and attachments
 */

require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class BatchEmailService
{
    private const BATCH_SIZE = 50;
    private const RETRY_LIMIT = 3;
    private const MAX_EMAILS_PER_HOUR = 100;
    private const BATCH_DELAY_SECONDS = 2;
    
    private static array $allowedAttachmentTypes = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'doc', 'txt', 'xlsx', 'xls'];
    private static array $blockedExtensions = ['exe', 'bat', 'js', 'vbs', 'zip', 'rar', 'dmg', 'sh', 'php', 'py'];
    
    private PHPMailer $mailer;
    private int $userId;
    
    public function __construct(int $userId)
    {
        $this->userId = $userId;
        $this->initializeMailer();
    }
    
    private function initializeMailer(): void
    {
        $this->mailer = new PHPMailer(true);
        
        // Get campaign SMTP settings from database (fallback to env)
        require_once __DIR__ . '/../controllers/SmtpSettingsController.php';
        $settings = SmtpSettingsController::getSettings('campaign');
        
        $this->mailer->isSMTP();
        $this->mailer->Host = $settings['host'];
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = $settings['username'];
        $this->mailer->Password = $settings['password'];
        $this->mailer->Port = $settings['port'];
        
        switch ($settings['encryption']) {
            case 'ssl':
                $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                break;
            case 'tls':
                $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                break;
            default:
                $this->mailer->SMTPSecure = '';
                $this->mailer->SMTPAutoTLS = false;
        }
        
        $this->mailer->setFrom($settings['from_email'], $settings['from_name']);
        
        $this->mailer->CharSet = 'UTF-8';
        $this->mailer->Encoding = 'base64';
        $this->mailer->isHTML(true);
        
        $this->mailer->SMTPDebug = env('APP_DEBUG', 'false') === 'true' ? SMTP::DEBUG_SERVER : SMTP::DEBUG_OFF;
    }
    
    /**
     * Check if user can send emails (rate limiting)
     */
    public function checkSendingLimits(int $emailCount): array
    {
        $limits = table('email_limits')->where('user_id', $this->userId)->first();
        
        if (!$limits) {
            // Create limits record
            table('email_limits')->insert([
                'user_id' => $this->userId,
                'sent_last_hour' => 0,
                'sent_today' => 0,
                'hourly_limit' => self::MAX_EMAILS_PER_HOUR,
                'daily_limit' => 1000,
                'last_reset_hour' => date('Y-m-d H:i:s'),
                'last_reset_day' => date('Y-m-d'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $limits = ['sent_last_hour' => 0, 'sent_today' => 0, 'hourly_limit' => self::MAX_EMAILS_PER_HOUR, 'daily_limit' => 1000];
        }
        
        // Reset counters if needed
        $lastHour = strtotime($limits['last_reset_hour'] ?? 'now');
        $currentHour = strtotime(date('Y-m-d H:00:00'));
        
        if ($lastHour < $currentHour) {
            table('email_limits')->where('user_id', $this->userId)->update([
                'sent_last_hour' => 0,
                'last_reset_hour' => date('Y-m-d H:i:s'),
            ]);
            $limits['sent_last_hour'] = 0;
        }
        
        $lastDay = $limits['last_reset_day'] ?? date('Y-m-d');
        if ($lastDay !== date('Y-m-d')) {
            table('email_limits')->where('user_id', $this->userId)->update([
                'sent_today' => 0,
                'last_reset_day' => date('Y-m-d'),
            ]);
            $limits['sent_today'] = 0;
        }
        
        $hourlyRemaining = (int) $limits['hourly_limit'] - (int) $limits['sent_last_hour'];
        $dailyRemaining = (int) $limits['daily_limit'] - (int) $limits['sent_today'];
        
        return [
            'can_send' => $emailCount <= $hourlyRemaining && $emailCount <= $dailyRemaining,
            'hourly_remaining' => $hourlyRemaining,
            'daily_remaining' => $dailyRemaining,
            'requested' => $emailCount,
            'hourly_limit' => (int) $limits['hourly_limit'],
            'daily_limit' => (int) $limits['daily_limit'],
        ];
    }
    
    /**
     * Send a batch email campaign
     */
    public function sendCampaign(int $campaignId): array
    {
        $campaign = table('campaigns')
            ->where('id', $campaignId)
            ->where('user_id', $this->userId)
            ->first();
        
        if (!$campaign) {
            return ['success' => false, 'error' => 'Campaign not found'];
        }
        
        // Get queued messages
        $messages = table('messages')
            ->where('campaign_id', $campaignId)
            ->whereIn('status', ['Pending', 'queued'])
            ->get();
        
        if (empty($messages)) {
            return ['success' => false, 'error' => 'No messages to send'];
        }
        
        // Check limits
        $limitCheck = $this->checkSendingLimits(count($messages));
        if (!$limitCheck['can_send']) {
            return [
                'success' => false,
                'error' => 'Sending limit exceeded',
                'limits' => $limitCheck,
            ];
        }
        
        // Update campaign status
        table('campaigns')->where('id', $campaignId)->update([
            'status' => 'Sending',
            'started_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Process in batches
        $batches = array_chunk($messages, self::BATCH_SIZE);
        $results = ['sent' => 0, 'failed' => 0, 'errors' => []];
        
        foreach ($batches as $batchIndex => $batch) {
            $batchResults = $this->processBatch($batch, $campaign);
            $results['sent'] += $batchResults['sent'];
            $results['failed'] += $batchResults['failed'];
            $results['errors'] = array_merge($results['errors'], $batchResults['errors']);
            
            // Update sending counters
            $this->incrementSendingCounters($batchResults['sent']);
            
            // Delay between batches
            if ($batchIndex < count($batches) - 1) {
                sleep(self::BATCH_DELAY_SECONDS);
            }
        }
        
        // Update campaign status
        $finalStatus = $results['failed'] === count($messages) ? 'Failed' : 'Sent';
        table('campaigns')->where('id', $campaignId)->update([
            'status' => $finalStatus,
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        return [
            'success' => true,
            'sent' => $results['sent'],
            'failed' => $results['failed'],
            'total' => count($messages),
        ];
    }
    
    /**
     * Process a batch of messages
     */
    private function processBatch(array $messages, array $campaign): array
    {
        $results = ['sent' => 0, 'failed' => 0, 'errors' => []];
        
        foreach ($messages as $message) {
            try {
                // Clear previous state
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();
                $this->mailer->clearReplyTos();
                
                // Set recipient
                $this->mailer->addAddress($message['recipient']);
                
                // Set subject and body
                $this->mailer->Subject = $message['subject'] ?? $campaign['subject'] ?? 'No Subject';
                $this->mailer->Body = $message['content'];
                $this->mailer->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $message['content']));
                
                // Add attachments
                $attachments = json_decode($message['attachments'] ?? '[]', true) ?: [];
                foreach ($attachments as $attachment) {
                    $path = $this->getAttachmentPath($attachment);
                    if ($path && file_exists($path)) {
                        $this->mailer->addAttachment($path, $attachment['original_name'] ?? basename($path));
                    }
                }
                
                // Send
                $this->mailer->send();
                
                // Update message status
                table('messages')->where('id', $message['id'])->update([
                    'status' => 'Delivered',
                    'sent_at' => date('Y-m-d H:i:s'),
                    'delivered_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                
                $results['sent']++;
                
            } catch (Exception $e) {
                $retryCount = (int) ($message['retry_count'] ?? 0) + 1;
                $newStatus = $retryCount >= self::RETRY_LIMIT ? 'Failed' : 'queued';
                
                table('messages')->where('id', $message['id'])->update([
                    'status' => $newStatus,
                    'retry_count' => $retryCount,
                    'error_message' => $e->getMessage(),
                    'failed_at' => $newStatus === 'Failed' ? date('Y-m-d H:i:s') : null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                
                if ($newStatus === 'Failed') {
                    $results['failed']++;
                    $results['errors'][] = [
                        'recipient' => $message['recipient'],
                        'error' => $e->getMessage(),
                    ];
                }
                
                error_log("Email send error to {$message['recipient']}: " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Retry failed messages
     */
    public function retryFailedMessages(int $campaignId): array
    {
        $messages = table('messages')
            ->where('campaign_id', $campaignId)
            ->where('status', 'queued')
            ->where('retry_count', '<', self::RETRY_LIMIT)
            ->get();
        
        if (empty($messages)) {
            return ['success' => true, 'retried' => 0, 'message' => 'No messages to retry'];
        }
        
        $campaign = table('campaigns')->where('id', $campaignId)->first();
        $results = $this->processBatch($messages, $campaign);
        
        return [
            'success' => true,
            'retried' => count($messages),
            'sent' => $results['sent'],
            'still_failed' => $results['failed'],
        ];
    }
    
    /**
     * Handle attachment upload
     */
    public static function uploadAttachment(array $file, int $userId, ?int $campaignId = null): array
    {
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'File upload error'];
        }
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Check blocked extensions
        if (in_array($ext, self::$blockedExtensions)) {
            return ['success' => false, 'error' => 'File type not allowed for security reasons'];
        }
        
        // Check allowed extensions
        if (!in_array($ext, self::$allowedAttachmentTypes)) {
            return ['success' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', self::$allowedAttachmentTypes)];
        }
        
        // Check file size (max 10MB)
        $maxSize = 10 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'error' => 'File too large. Maximum size is 10MB'];
        }
        
        // Create uploads directory
        $uploadsDir = env('UPLOADS_DIR', __DIR__ . '/../../uploads');
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        
        // Generate unique filename
        $storedName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
        $filePath = $uploadsDir . '/' . $storedName;
        
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['success' => false, 'error' => 'Failed to save file'];
        }
        
        // Store in database
        $attachmentId = table('email_attachments')->insert([
            'user_id' => $userId,
            'campaign_id' => $campaignId,
            'original_name' => $file['name'],
            'stored_name' => $storedName,
            'file_path' => $filePath,
            'file_size' => $file['size'],
            'mime_type' => $file['type'] ?: 'application/octet-stream',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        return [
            'success' => true,
            'attachment' => [
                'id' => $attachmentId,
                'name' => $file['name'],
                'size' => $file['size'],
                'stored_name' => $storedName,
            ],
        ];
    }
    
    /**
     * Get attachment file path
     */
    private function getAttachmentPath(array $attachment): ?string
    {
        if (isset($attachment['file_path']) && file_exists($attachment['file_path'])) {
            return $attachment['file_path'];
        }
        
        if (isset($attachment['id'])) {
            $att = table('email_attachments')
                ->where('id', $attachment['id'])
                ->where('user_id', $this->userId)
                ->first();
            return $att['file_path'] ?? null;
        }
        
        if (isset($attachment['stored_name'])) {
            $uploadsDir = env('UPLOADS_DIR', __DIR__ . '/../../uploads');
            $path = $uploadsDir . '/' . basename($attachment['stored_name']);
            return file_exists($path) ? $path : null;
        }
        
        return null;
    }
    
    /**
     * Increment sending counters
     */
    private function incrementSendingCounters(int $count): void
    {
        if ($count <= 0) return;
        
        $pdo = db();
        $stmt = $pdo->prepare("
            UPDATE email_limits 
            SET sent_last_hour = sent_last_hour + ?,
                sent_today = sent_today + ?,
                updated_at = NOW()
            WHERE user_id = ?
        ");
        $stmt->execute([$count, $count, $this->userId]);
    }
    
    /**
     * Personalize message content
     */
    public static function personalizeContent(string $content, array $contact): string
    {
        $replacements = [
            '{{name}}' => $contact['name'] ?? 'Esteemed',
            '{{first_name}}' => $contact['name'] ?? 'Esteemed',
            '{{surname}}' => $contact['surname'] ?? '',
            '{{email}}' => $contact['email'] ?? '',
            '{{phone}}' => $contact['phone'] ?? '',
            '{name}' => $contact['name'] ?? 'Esteemed',
            '{email}' => $contact['email'] ?? '',
            '{phone}' => $contact['phone'] ?? '',
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
    
    /**
     * Get email template HTML wrapper
     */
    public static function wrapInTemplate(string $content, string $subject, array $options = []): string
    {
        $appName = env('SMTP_FROM_NAME', 'IEOSUIA Portal');
        $year = date('Y');
        $unsubscribeUrl = $options['unsubscribe_url'] ?? '#';
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background-color: #f4f4f5;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); padding: 24px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 20px; font-weight: 700;">{$appName}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px;">
                            {$content}
                        </td>
                    </tr>
                    <tr>
                        <td style="background: #f4f4f5; padding: 20px; text-align: center;">
                            <p style="color: #71717a; font-size: 12px; margin: 0;">
                                © {$year} {$appName}. All rights reserved.<br>
                                <a href="{$unsubscribeUrl}" style="color: #3b82f6; text-decoration: none;">Unsubscribe</a>
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
