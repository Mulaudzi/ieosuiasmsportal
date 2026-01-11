<?php
/**
 * Admin Notification Service - Sends notifications to admins for critical events
 */

require_once __DIR__ . '/../config/database.php';

class AdminNotificationService
{
    /**
     * Notify all admins about an event
     */
    public static function notifyAdmins(string $type, string $title, string $message, ?array $data = null): void
    {
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
            error_log('Admin notification failed: ' . $e->getMessage());
        }
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
                'failure_rate' => $failureRate,
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
        
        self::notifyAdmins(
            'new_sender_id',
            'New Sender ID Pending',
            "New {$type} sender ID \"{$senderId}\" submitted by {$userName} requires approval",
            [
                'sender_id_id' => $senderIdId,
                'sender_id' => $senderId,
                'type' => $type,
                'user_id' => $userId,
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
        
        // Only notify if balance is critically low
        if ($balance < 10) {
            self::notifyAdmins(
                'low_credits',
                'User Low Credits Warning',
                "User {$userName} has low credit balance: R" . number_format($balance, 2),
                [
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'balance' => $balance,
                ]
            );
        }
    }
}
