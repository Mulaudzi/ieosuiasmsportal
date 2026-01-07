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
}
