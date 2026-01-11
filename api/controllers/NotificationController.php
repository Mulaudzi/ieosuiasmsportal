<?php
/**
 * Notification Controller - Real-time alerts for campaigns, credits, etc.
 */

class NotificationController
{
    /**
     * Get user notifications
     */
    public function index(): void
    {
        $userId = Auth::id();
        $page = (int) Request::query('page', 1);
        $perPage = (int) Request::query('per_page', 20);
        
        $notifications = table('notifications')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();
        
        $unreadCount = table('notifications')
            ->where('user_id', $userId)
            ->where('read', 0)
            ->count();
        
        Response::success([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }
    
    /**
     * Mark a notification as read
     */
    public function markAsRead(array $params): void
    {
        $notification = table('notifications')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$notification) {
            Response::error('Notification not found', 404);
        }
        
        table('notifications')->where('id', $params['id'])->update([
            'read' => 1,
            'read_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success(['message' => 'Notification marked as read']);
    }
    
    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(): void
    {
        table('notifications')
            ->where('user_id', Auth::id())
            ->where('read', 0)
            ->update([
                'read' => 1,
                'read_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        Response::success(['message' => 'All notifications marked as read']);
    }
    
    /**
     * Delete a notification
     */
    public function destroy(array $params): void
    {
        $notification = table('notifications')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$notification) {
            Response::error('Notification not found', 404);
        }
        
        table('notifications')->where('id', $params['id'])->delete();
        
        Response::noContent();
    }
    
    /**
     * Create a notification (internal use)
     */
    public static function create(int $userId, string $type, string $title, string $message, ?array $data = null): int
    {
        return table('notifications')->insert([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data ? json_encode($data) : null,
            'read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    
    /**
     * Create campaign complete notification
     */
    public static function campaignComplete(int $userId, string $campaignName, int $sent, int $failed): void
    {
        self::create(
            $userId,
            'campaign_complete',
            'Campaign Completed',
            "Your campaign \"{$campaignName}\" has finished. {$sent} sent, {$failed} failed.",
            ['campaign_name' => $campaignName, 'sent' => $sent, 'failed' => $failed]
        );
    }
    
    /**
     * Create campaign failed notification
     */
    public static function campaignFailed(int $userId, string $campaignName, string $reason): void
    {
        self::create(
            $userId,
            'campaign_failed',
            'Campaign Failed',
            "Your campaign \"{$campaignName}\" failed: {$reason}",
            ['campaign_name' => $campaignName, 'reason' => $reason]
        );
    }
    
    /**
     * Create low credits notification
     */
    public static function lowCredits(int $userId, float $balance, float $threshold = 10.0): void
    {
        if ($balance <= $threshold) {
            // Check if we already sent this recently (within 24h)
            $existing = table('notifications')
                ->where('user_id', $userId)
                ->where('type', 'low_credits')
                ->where('created_at', '>', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->first();
            
            if (!$existing) {
                self::create(
                    $userId,
                    'low_credits',
                    'Low Credit Balance',
                    "Your balance is running low (R{$balance}). Top up to continue sending.",
                    ['balance' => $balance]
                );
            }
        }
    }
    
    /**
     * Create sender ID approved notification
     */
    public static function senderApproved(int $userId, string $senderId, string $type): void
    {
        self::create(
            $userId,
            'sender_approved',
            'Sender ID Approved',
            "Your {$type} sender ID \"{$senderId}\" has been approved.",
            ['sender_id' => $senderId, 'type' => $type]
        );
    }
    
    /**
     * Create message failed notification
     */
    public static function messageFailed(int $userId, int $failedCount, string $campaignName): void
    {
        self::create(
            $userId,
            'message_failed',
            'Messages Failed',
            "{$failedCount} messages failed in campaign \"{$campaignName}\".",
            ['failed_count' => $failedCount, 'campaign_name' => $campaignName]
        );
    }
}
