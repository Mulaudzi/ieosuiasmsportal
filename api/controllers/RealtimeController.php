<?php
/**
 * Realtime Notification Controller
 * Provides Server-Sent Events (SSE) for real-time push notifications to admin dashboard
 */

class RealtimeController
{
    /**
     * Server-Sent Events stream for admin notifications
     * GET /admin/realtime/stream
     */
    public static function stream(): void
    {
        // Verify admin access
        $user = Auth::user();
        if (!$user || $user['account_type'] !== 'admin') {
            Response::error('Unauthorized', 403);
            return;
        }
        
        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');
        header('X-Accel-Buffering: no'); // Disable nginx buffering
        
        // Disable output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        $lastId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int) $_SERVER['HTTP_LAST_EVENT_ID'] : 0;
        $channel = 'admin';
        
        // Send initial connection event
        echo "event: connected\n";
        echo "data: " . json_encode(['status' => 'connected', 'timestamp' => date('c')]) . "\n\n";
        flush();
        
        // Keep connection alive and poll for new notifications
        $startTime = time();
        $maxDuration = 30; // Max 30 seconds per connection (client will reconnect)
        
        while ((time() - $startTime) < $maxDuration) {
            // Check for new notifications
            $notifications = self::getNewNotifications($channel, $lastId);
            
            foreach ($notifications as $notification) {
                $lastId = (int) $notification['id'];
                
                echo "id: {$notification['id']}\n";
                echo "event: {$notification['type']}\n";
                echo "data: " . json_encode([
                    'id' => $notification['id'],
                    'type' => $notification['type'],
                    'title' => $notification['title'],
                    'message' => $notification['message'],
                    'data' => $notification['data'] ? json_decode($notification['data'], true) : null,
                    'created_at' => $notification['created_at'],
                ]) . "\n\n";
                flush();
            }
            
            // Send heartbeat every 15 seconds
            if ((time() - $startTime) % 15 === 0) {
                echo ": heartbeat\n\n";
                flush();
            }
            
            // Sleep briefly to avoid CPU overload
            usleep(500000); // 500ms
            
            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }
        }
        
        // Connection timeout - client should reconnect
        echo "event: timeout\n";
        echo "data: " . json_encode(['message' => 'reconnect']) . "\n\n";
        flush();
        
        exit;
    }
    
    /**
     * Get new notifications since last ID
     */
    private static function getNewNotifications(string $channel, int $lastId): array
    {
        try {
            $query = table('realtime_notifications')
                ->where('channel', $channel)
                ->where('id', '>', $lastId)
                ->orderBy('id', 'ASC')
                ->limit(10);
            
            return $query->get();
        } catch (\Exception $e) {
            error_log("Realtime notifications error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Poll for new notifications (alternative to SSE for browsers that don't support it)
     * GET /admin/realtime/poll
     */
    public static function poll(): void
    {
        $user = Auth::user();
        if (!$user || $user['account_type'] !== 'admin') {
            Response::error('Unauthorized', 403);
            return;
        }
        
        $lastId = (int) ($_GET['last_id'] ?? 0);
        $channel = 'admin';
        
        $notifications = self::getNewNotifications($channel, $lastId);
        
        Response::success([
            'notifications' => array_map(function($n) {
                return [
                    'id' => $n['id'],
                    'type' => $n['type'],
                    'title' => $n['title'],
                    'message' => $n['message'],
                    'data' => $n['data'] ? json_decode($n['data'], true) : null,
                    'created_at' => $n['created_at'],
                ];
            }, $notifications),
            'last_id' => $notifications ? end($notifications)['id'] : $lastId,
        ]);
    }
    
    /**
     * Push a notification to the realtime queue
     */
    public static function push(string $channel, string $type, string $title, ?string $message = null, ?array $data = null): void
    {
        try {
            table('realtime_notifications')->insert([
                'channel' => $channel,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data ? json_encode($data) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            error_log("Failed to push realtime notification: " . $e->getMessage());
        }
    }
    
    /**
     * Cleanup old notifications (called by cron)
     */
    public static function cleanup(): void
    {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("DELETE FROM realtime_notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $stmt->execute();
            $deleted = $stmt->rowCount();
            
            Response::success(['deleted' => $deleted]);
        } catch (\Exception $e) {
            Response::error('Cleanup failed: ' . $e->getMessage(), 500);
        }
    }
}
