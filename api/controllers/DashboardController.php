<?php
/**
 * Dashboard Controller
 */

class DashboardController {
    public function stats(): void {
        $userId = Auth::id();
        
        // Campaign counts
        $totalCampaigns = table('campaigns')->where('user_id', $userId)->count();
        $activeCampaigns = table('campaigns')
            ->where('user_id', $userId)
            ->whereIn('status', ['Sending', 'Scheduled'])
            ->count();
        
        // Message stats
        $campaigns = table('campaigns')
            ->select('id')
            ->where('user_id', $userId)
            ->get();
        $campaignIds = array_column($campaigns, 'id');
        
        $totalSent = 0;
        $totalDelivered = 0;
        $totalFailed = 0;
        
        if (!empty($campaignIds)) {
            $totalSent = table('messages')
                ->whereIn('campaign_id', $campaignIds)
                ->whereIn('status', ['Sent', 'Awaiting DLR', 'Delivered'])
                ->count();
            
            $totalDelivered = table('messages')
                ->whereIn('campaign_id', $campaignIds)
                ->where('status', 'Delivered')
                ->count();
            
            $totalFailed = table('messages')
                ->whereIn('campaign_id', $campaignIds)
                ->where('status', 'Failed')
                ->count();
        }
        
        // Contacts
        $totalContacts = table('contacts')->where('user_id', $userId)->count();
        
        // Wallet
        $wallet = table('wallets')->where('user_id', $userId)->first();
        $balance = $wallet ? (float) $wallet['balance'] : 0;
        
        // Delivery rate
        $deliveryRate = $totalSent > 0 ? round(($totalDelivered / $totalSent) * 100, 1) : 0;
        
        Response::success([
            'total_campaigns' => $totalCampaigns,
            'active_campaigns' => $activeCampaigns,
            'total_sent' => $totalSent,
            'total_delivered' => $totalDelivered,
            'total_failed' => $totalFailed,
            'delivery_rate' => $deliveryRate,
            'total_contacts' => $totalContacts,
            'wallet_balance' => $balance,
        ]);
    }
    
    public function chart(): void {
        $userId = Auth::id();
        $days = (int) Request::query('days', 30);
        
        $startDate = date('Y-m-d', strtotime("-$days days"));
        
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT 
                DATE(m.sent_at) as date,
                COUNT(*) as sent,
                SUM(CASE WHEN m.status = 'Delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN m.status = 'Failed' THEN 1 ELSE 0 END) as failed
            FROM messages m
            JOIN campaigns c ON m.campaign_id = c.id
            WHERE c.user_id = ?
            AND m.sent_at >= ?
            GROUP BY DATE(m.sent_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$userId, $startDate]);
        $data = $stmt->fetchAll();
        
        Response::success(['chart' => $data]);
    }
    
    public function recentCampaigns(): void {
        $userId = Auth::id();
        
        $campaigns = table('campaigns')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit(5)
            ->get();
        
        // Add message counts
        foreach ($campaigns as &$campaign) {
            $campaign['sent_count'] = table('messages')
                ->where('campaign_id', $campaign['id'])
                ->whereIn('status', ['Sent', 'Awaiting DLR', 'Delivered'])
                ->count();
            
            $campaign['delivered_count'] = table('messages')
                ->where('campaign_id', $campaign['id'])
                ->where('status', 'Delivered')
                ->count();
            
            $campaign['failed_count'] = table('messages')
                ->where('campaign_id', $campaign['id'])
                ->where('status', 'Failed')
                ->count();
        }
        
        Response::success(['campaigns' => $campaigns]);
    }
    
    /**
     * Get schedule recommendations based on delivery success rates
     */
    public function scheduleRecommendations(): void {
        $userId = Auth::id();
        $type = Request::query('type', 'sms'); // sms or email
        
        $pdo = db();
        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
        
        // Get delivery stats by hour and day of week for this user's campaigns
        $stmt = $pdo->prepare("
            SELECT 
                HOUR(m.sent_at) as hour,
                DAYOFWEEK(m.sent_at) as day_of_week,
                COUNT(*) as total_count,
                SUM(CASE WHEN m.status = 'Delivered' THEN 1 ELSE 0 END) as delivered_count
            FROM messages m
            JOIN campaigns c ON m.campaign_id = c.id
            WHERE c.user_id = ?
            AND c.type = ?
            AND m.sent_at >= ?
            AND m.sent_at IS NOT NULL
            GROUP BY HOUR(m.sent_at), DAYOFWEEK(m.sent_at)
            HAVING total_count >= 5
        ");
        $stmt->execute([$userId, $type, $thirtyDaysAgo]);
        $stats = $stmt->fetchAll();
        
        if (empty($stats)) {
            // Fall back to global stats if user has no data
            $stmt = $pdo->prepare("
                SELECT 
                    HOUR(m.sent_at) as hour,
                    DAYOFWEEK(m.sent_at) as day_of_week,
                    COUNT(*) as total_count,
                    SUM(CASE WHEN m.status = 'Delivered' THEN 1 ELSE 0 END) as delivered_count
                FROM messages m
                JOIN campaigns c ON m.campaign_id = c.id
                WHERE c.type = ?
                AND m.sent_at >= ?
                AND m.sent_at IS NOT NULL
                GROUP BY HOUR(m.sent_at), DAYOFWEEK(m.sent_at)
                HAVING total_count >= 10
            ");
            $stmt->execute([$type, $thirtyDaysAgo]);
            $stats = $stmt->fetchAll();
        }
        
        if (empty($stats)) {
            Response::success([
                'recommendations' => [],
                'has_data' => false,
            ]);
            return;
        }
        
        // Calculate success rates and find top times
        $dayNames = ['', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $recommendations = [];
        
        foreach ($stats as $row) {
            $successRate = $row['total_count'] > 0 
                ? round(($row['delivered_count'] / $row['total_count']) * 100, 1) 
                : 0;
            
            if ($successRate >= 70) { // Only recommend times with decent success rates
                $recommendations[] = [
                    'day' => $dayNames[(int)$row['day_of_week']],
                    'day_index' => (int)$row['day_of_week'],
                    'hour' => (int)$row['hour'],
                    'success_rate' => $successRate,
                    'message_count' => (int)$row['total_count'],
                ];
            }
        }
        
        // Sort by success rate descending
        usort($recommendations, function($a, $b) {
            return $b['success_rate'] <=> $a['success_rate'];
        });
        
        // Return top 6 recommendations
        Response::success([
            'recommendations' => array_slice($recommendations, 0, 6),
            'has_data' => true,
        ]);
    }
}
