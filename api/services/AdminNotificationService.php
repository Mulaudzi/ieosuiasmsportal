<?php
/**
 * Admin Notification Service - Sends notifications to admins for critical events
 * Now includes email notifications based on admin notification settings
 */

require_once __DIR__ . '/../config/database.php';

class AdminNotificationService
{
    /**
     * Check if a notification type is enabled
     */
    private static function isNotificationEnabled(string $eventType, string $channel = 'email'): bool
    {
        try {
            $setting = table('admin_notification_settings')
                ->where('event_type', $eventType)
                ->first();

            if (!$setting || !$setting['is_enabled']) {
                return false;
            }

            if ($channel === 'email') {
                return (bool) $setting['notify_email'];
            }
            if ($channel === 'inapp') {
                return (bool) $setting['notify_inapp'];
            }

            return (bool) $setting['is_enabled'];
        } catch (Exception $e) {
            // If table doesn't exist, default to enabled for in-app
            return $channel === 'inapp';
        }
    }

    /**
     * Send admin email notification
     */
    private static function sendAdminEmail(string $type, string $title, string $message, ?array $data = null): void
    {
        if (!self::isNotificationEnabled($type, 'email')) {
            return;
        }

        try {
            require_once __DIR__ . '/EmailService.php';
            
            $pdo = db();
            
            // Get all admin emails
            $stmt = $pdo->query("SELECT email, name FROM users WHERE role = 'admin' AND is_active = 1");
            $admins = $stmt->fetchAll();
            
            if (empty($admins)) {
                return;
            }

            // Build email content
            $htmlBody = self::buildEmailTemplate($type, $title, $message, $data);
            
            foreach ($admins as $admin) {
                try {
                    EmailService::send(
                        $admin['email'],
                        "[IEOSUIA Admin] {$title}",
                        $htmlBody
                    );
                } catch (Exception $e) {
                    error_log("Failed to send admin email to {$admin['email']}: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log('Admin email notification failed: ' . $e->getMessage());
        }
    }

    /**
     * Build HTML email template for admin notifications
     */
    private static function buildEmailTemplate(string $type, string $title, string $message, ?array $data): string
    {
        $typeColors = [
            'new_user' => '#3b82f6',
            'new_sender_id' => '#f59e0b',
            'campaign_failed' => '#ef4444',
            'high_failure_rate' => '#ef4444',
            'low_credits' => '#f59e0b',
            'large_campaign' => '#8b5cf6',
            'scheduled_campaign_sent' => '#10b981',
            'user_deactivated' => '#6b7280',
        ];

        $color = $typeColors[$type] ?? '#3b82f6';
        $timestamp = date('F j, Y \a\t g:i A');
        
        // Build data section if available
        $dataSection = '';
        if ($data) {
            $dataRows = '';
            foreach ($data as $key => $value) {
                $label = ucwords(str_replace('_', ' ', $key));
                $dataRows .= "<tr><td style=\"padding: 8px 12px; border-bottom: 1px solid #e5e7eb; color: #6b7280; font-size: 14px;\">{$label}</td><td style=\"padding: 8px 12px; border-bottom: 1px solid #e5e7eb; font-size: 14px; font-weight: 500;\">" . htmlspecialchars((string)$value) . "</td></tr>";
            }
            $dataSection = "
                <table style=\"width: 100%; border-collapse: collapse; margin-top: 20px; background: #f9fafb; border-radius: 8px; overflow: hidden;\">
                    <thead>
                        <tr><th colspan=\"2\" style=\"padding: 12px; background: #f3f4f6; text-align: left; font-size: 13px; text-transform: uppercase; color: #6b7280;\">Event Details</th></tr>
                    </thead>
                    <tbody>
                        {$dataRows}
                    </tbody>
                </table>
            ";
        }

        $dashboardUrl = env('APP_URL', 'https://sms.ieosuia.com') . '/admin';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f3f4f6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow: hidden;">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, {$color}, {$color}dd); padding: 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">⚡ IEOSUIA Admin Alert</h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 30px;">
                            <h2 style="margin: 0 0 15px; color: #111827; font-size: 20px; font-weight: 600;">{$title}</h2>
                            <p style="margin: 0 0 20px; color: #4b5563; font-size: 16px; line-height: 1.6;">{$message}</p>
                            
                            {$dataSection}
                            
                            <div style="margin-top: 30px; text-align: center;">
                                <a href="{$dashboardUrl}" style="display: inline-block; padding: 12px 30px; background-color: {$color}; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 500; font-size: 14px;">View Admin Dashboard</a>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 20px 30px; background-color: #f9fafb; border-top: 1px solid #e5e7eb; text-align: center;">
                            <p style="margin: 0; color: #9ca3af; font-size: 13px;">
                                Sent on {$timestamp}<br>
                                You received this because you're an admin at IEOSUIA SMS Portal
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
     * Notify all admins about an event (in-app + email)
     */
    public static function notifyAdmins(string $type, string $title, string $message, ?array $data = null): void
    {
        // In-app notifications
        if (self::isNotificationEnabled($type, 'inapp')) {
            try {
                $pdo = db();
                
                // Get all admin users
                $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
                $admins = $stmt->fetchAll();
                
                foreach ($admins as $admin) {
                    table('notifications')->insert([
                        'user_id' => $admin['id'],
                        'type' => $type,
                        'title' => $title,
                        'message' => $message,
                        'data' => $data ? json_encode($data) : null,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            } catch (Exception $e) {
                error_log('Admin in-app notification failed: ' . $e->getMessage());
            }
        }

        // Email notifications (async-ish - after in-app)
        self::sendAdminEmail($type, $title, $message, $data);
    }
    
    /**
     * Notify admins about a new user registration
     */
    public static function notifyNewUser(int $userId, string $userName, string $userEmail): void
    {
        self::notifyAdmins(
            'new_user',
            'New User Registration',
            "New user registered: {$userName} ({$userEmail})",
            [
                'user_id' => $userId,
                'user_name' => $userName,
                'user_email' => $userEmail,
            ]
        );
    }
    
    /**
     * Notify admins about a failed campaign
     */
    public static function notifyCampaignFailed(int $campaignId, string $campaignName, int $failedCount, int $userId): void
    {
        // Get user info
        $user = table('users')->where('id', $userId)->first();
        $userName = $user ? $user['name'] : 'Unknown';
        
        self::notifyAdmins(
            'campaign_failed',
            'Campaign Failed',
            "Campaign \"{$campaignName}\" by {$userName} has {$failedCount} failed messages",
            [
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignName,
                'failed_count' => $failedCount,
                'user_id' => $userId,
                'user_name' => $userName,
            ]
        );
    }
    
    /**
     * Notify admins about a high failure rate campaign
     */
    public static function notifyHighFailureRate(int $campaignId, string $campaignName, float $failureRate, int $userId): void
    {
        $user = table('users')->where('id', $userId)->first();
        $userName = $user ? $user['name'] : 'Unknown';
        
        self::notifyAdmins(
            'high_failure_rate',
            'High Campaign Failure Rate',
            "Campaign \"{$campaignName}\" by {$userName} has a " . round($failureRate * 100) . "% failure rate",
            [
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignName,
                'failure_rate' => round($failureRate * 100) . '%',
                'user_id' => $userId,
                'user_name' => $userName,
            ]
        );
    }
    
    /**
     * Notify admins about a new sender ID pending approval
     */
    public static function notifyNewSenderId(int $senderIdId, string $senderId, string $type, int $userId): void
    {
        $user = table('users')->where('id', $userId)->first();
        $userName = $user ? $user['name'] : 'Unknown';
        $userEmail = $user ? $user['email'] : 'Unknown';
        
        self::notifyAdmins(
            'new_sender_id',
            'New Sender ID Pending Approval',
            "New {$type} sender ID \"{$senderId}\" submitted by {$userName} requires approval",
            [
                'sender_id_id' => $senderIdId,
                'sender_id' => $senderId,
                'type' => strtoupper($type),
                'user_name' => $userName,
                'user_email' => $userEmail,
            ]
        );
    }
    
    /**
     * Notify admins about a large campaign sent
     */
    public static function notifyLargeCampaign(int $campaignId, string $campaignName, int $recipientCount, int $userId): void
    {
        $user = table('users')->where('id', $userId)->first();
        $userName = $user ? $user['name'] : 'Unknown';
        
        self::notifyAdmins(
            'large_campaign',
            'Large Campaign Sent',
            "Campaign \"{$campaignName}\" with {$recipientCount} recipients was sent by {$userName}",
            [
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignName,
                'recipient_count' => number_format($recipientCount),
                'user_name' => $userName,
            ]
        );
    }
    
    /**
     * Notify admins about a scheduled campaign being processed
     */
    public static function notifyScheduledCampaignSent(int $campaignId, string $campaignName, int $recipientCount, int $userId): void
    {
        $user = table('users')->where('id', $userId)->first();
        $userName = $user ? $user['name'] : 'Unknown';
        
        self::notifyAdmins(
            'scheduled_campaign_sent',
            'Scheduled Campaign Processed',
            "Scheduled campaign \"{$campaignName}\" was automatically sent to {$recipientCount} recipients",
            [
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignName,
                'recipient_count' => number_format($recipientCount),
                'user_name' => $userName,
            ]
        );
    }
    
    /**
     * Notify admins about low system credits (if applicable)
     */
    public static function notifyLowCredits(int $userId, float $balance): void
    {
        $user = table('users')->where('id', $userId)->first();
        $userName = $user ? $user['name'] : 'Unknown';
        $userEmail = $user ? $user['email'] : 'Unknown';
        
        // Only notify if balance is critically low
        if ($balance < 10) {
            self::notifyAdmins(
                'low_credits',
                'User Low Credits Warning',
                "User {$userName} has low credit balance: R" . number_format($balance, 2),
                [
                    'user_name' => $userName,
                    'user_email' => $userEmail,
                    'balance' => 'R' . number_format($balance, 2),
                ]
            );
        }
    }
    
    /**
     * Notify admins about user deactivation
     */
    public static function notifyUserDeactivated(int $userId, string $userName, string $userEmail, string $reason = 'Admin action'): void
    {
        self::notifyAdmins(
            'user_deactivated',
            'User Account Deactivated',
            "User account {$userName} ({$userEmail}) has been deactivated",
            [
                'user_id' => $userId,
                'user_name' => $userName,
                'user_email' => $userEmail,
                'reason' => $reason,
            ]
        );
    }
}
