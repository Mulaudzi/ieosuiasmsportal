<?php
/**
 * Dashboard Controller
 */

require_once __DIR__.'/../domain/SmsReportingService.php';

class DashboardController {
    public function stats(): void {
        $userId = Auth::id();
        $range=(string)Request::query('range','7d');try{$sms=SmsReportingService::summary((int)$userId,$range);}catch(InvalidArgumentException){Response::error('Invalid reporting range',422);return;}
        
        $pdo=db();$campaignStatement=$pdo->prepare("SELECT COUNT(*) total,SUM(state IN ('scheduled','queued','processing')) active FROM sms_campaigns WHERE user_id=?");$campaignStatement->execute([$userId]);$campaignMetrics=$campaignStatement->fetch()?:[];
        $totalCampaigns=(int)($campaignMetrics['total']??0);$activeCampaigns=(int)($campaignMetrics['active']??0);$totalSent=$sms['total_messages'];$totalDelivered=$sms['delivered'];$totalFailed=$sms['failed'];
        
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
            'total_pending' => $sms['pending'],
            'total_awaiting_delivery' => $sms['awaiting_delivery'],
            'total_dlr_unavailable' => $sms['dlr_unavailable'],
            'delivery_rate' => $sms['delivery_rate'],
            'reporting_range'=>$range,'reporting_start'=>$sms['start'],'reporting_end'=>$sms['end'],
            'total_contacts' => $totalContacts,
            'wallet_balance' => $balance,
        ]);
    }
    
    public function chart(): void {
        $userId = Auth::id();
        $range=(string)Request::query('range',Request::query('days','7')==='30'?'30d':'7d');try{$rows=SmsReportingService::daily((int)$userId,$range);}catch(InvalidArgumentException){Response::error('Invalid reporting range',422);return;}$data=array_map(static fn($row)=>['date'=>$row['date'],'sent'=>(int)$row['total_messages'],'delivered'=>(int)$row['delivered'],'failed'=>(int)$row['failed'],'pending'=>(int)$row['pending']],$rows);
        
        Response::success(['chart' => $data]);
    }
    
    public function recentCampaigns(): void {
        $userId = Auth::id();
        
        $campaigns = table('sms_campaigns')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit(5)
            ->get();
        
        // Add message counts
        foreach ($campaigns as &$campaign) {
            $campaign['status']=$campaign['state'];
            $campaign['total_recipients']=(int)$campaign['recipient_count'];
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
                SUM(m.state='delivered') as delivered_count
            FROM sms_messages m
            WHERE m.user_id = ?
            AND m.sent_at >= ?
            AND m.sent_at IS NOT NULL
            GROUP BY HOUR(m.sent_at), DAYOFWEEK(m.sent_at)
            HAVING total_count >= 5
        ");
        $stmt->execute([$userId, $thirtyDaysAgo]);
        $stats = $stmt->fetchAll();
        
        if (empty($stats)) {
            // Fall back to global stats if user has no data
            $stmt = $pdo->prepare("
                SELECT 
                    HOUR(m.sent_at) as hour,
                    DAYOFWEEK(m.sent_at) as day_of_week,
                    COUNT(*) as total_count,
                    SUM(m.state='delivered') as delivered_count
                FROM sms_messages m
                WHERE m.sent_at >= ?
                AND m.sent_at IS NOT NULL
                GROUP BY HOUR(m.sent_at), DAYOFWEEK(m.sent_at)
                HAVING total_count >= 10
            ");
            $stmt->execute([$thirtyDaysAgo]);
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
