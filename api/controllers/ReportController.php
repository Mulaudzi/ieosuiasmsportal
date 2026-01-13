<?php
/**
 * Report Controller
 */

class ReportController {
    public function campaigns(): void {
        $userId = Auth::id();
        $startDate = Request::query('start_date', date('Y-m-d', strtotime('-30 days')));
        $endDate = Request::query('end_date', date('Y-m-d'));
        $type = Request::query('type'); // sms or email
        
        $pdo = db();
        
        $sql = "
            SELECT 
                c.id,
                c.name,
                c.type,
                c.status,
                c.total_recipients,
                c.actual_cost,
                c.created_at,
                c.completed_at,
                COUNT(CASE WHEN m.status IN ('Sent', 'Awaiting DLR', 'Delivered') THEN 1 END) as sent_count,
                COUNT(CASE WHEN m.status = 'Delivered' THEN 1 END) as delivered_count,
                COUNT(CASE WHEN m.status = 'Failed' THEN 1 END) as failed_count
            FROM campaigns c
            LEFT JOIN messages m ON c.id = m.campaign_id
            WHERE c.user_id = ?
            AND c.created_at BETWEEN ? AND ?
        ";
        
        $params = [$userId, $startDate . ' 00:00:00', $endDate . ' 23:59:59'];
        
        if ($type) {
            $sql .= " AND c.type = ?";
            $params[] = $type;
        }
        
        $sql .= " GROUP BY c.id ORDER BY c.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $campaigns = $stmt->fetchAll();
        
        // Summary stats
        $totalSent = array_sum(array_column($campaigns, 'sent_count'));
        $totalDelivered = array_sum(array_column($campaigns, 'delivered_count'));
        $totalFailed = array_sum(array_column($campaigns, 'failed_count'));
        $totalCost = array_sum(array_column($campaigns, 'actual_cost'));
        
        Response::success([
            'campaigns' => $campaigns,
            'summary' => [
                'total_campaigns' => count($campaigns),
                'total_sent' => $totalSent,
                'total_delivered' => $totalDelivered,
                'total_failed' => $totalFailed,
                'delivery_rate' => $totalSent > 0 ? round(($totalDelivered / $totalSent) * 100, 1) : 0,
                'total_cost' => round($totalCost, 2),
            ],
        ]);
    }
    
    public function messages(): void {
        $userId = Auth::id();
        $campaignId = Request::query('campaign_id');
        $status = Request::query('status');
        $page = (int) Request::query('page', 1);
        $perPage = (int) Request::query('per_page', 50);
        
        if (!$campaignId) {
            Response::error('Campaign ID required', 400);
        }
        
        // Verify campaign ownership
        $campaign = table('campaigns')
            ->where('id', $campaignId)
            ->where('user_id', $userId)
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        $query = table('messages')->where('campaign_id', $campaignId);
        
        if ($status) {
            $query->where('status', $status);
        }
        
        $total = $query->count();
        
        $messages = table('messages')
            ->where('campaign_id', $campaignId);
        
        if ($status) {
            $messages->where('status', $status);
        }
        
        $messages = $messages
            ->orderBy('id', 'ASC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();
        
        Response::paginate($messages, $total, $page, $perPage);
    }
    
    public function export(): void {
        $userId = Auth::id();
        $campaignId = Request::query('campaign_id');
        $format = Request::query('format', 'csv');
        
        if (!$campaignId) {
            Response::error('Campaign ID required', 400);
        }
        
        $campaign = table('campaigns')
            ->where('id', $campaignId)
            ->where('user_id', $userId)
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        $messages = table('messages')
            ->where('campaign_id', $campaignId)
            ->orderBy('id', 'ASC')
            ->get();
        
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="campaign_' . $campaignId . '_report.csv"');
            
            $output = fopen('php://output', 'w');
            
            // Header row
            fputcsv($output, ['Recipient', 'Status', 'Sent At', 'Delivered At', 'Error']);
            
            foreach ($messages as $message) {
                fputcsv($output, [
                    $message['recipient'],
                    $message['status'],
                    $message['sent_at'],
                    $message['delivered_at'],
                    $message['error_message'],
                ]);
            }
            
            fclose($output);
            exit;
        }
        
        Response::success(['messages' => $messages]);
    }
    
    /**
     * Compare multiple campaigns side by side
     */
    public function compare(): void {
        $userId = Auth::id();
        $campaignIds = Request::query('ids');
        
        if (!$campaignIds) {
            Response::error('Campaign IDs required (comma-separated)', 400);
        }
        
        $ids = array_map('intval', explode(',', $campaignIds));
        
        if (count($ids) < 2 || count($ids) > 5) {
            Response::error('Select 2-5 campaigns to compare', 400);
        }
        
        $pdo = db();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        // Get campaigns with stats
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.name,
                c.type,
                c.status,
                c.sender_id,
                c.total_recipients,
                c.actual_cost,
                c.created_at,
                c.started_at,
                c.completed_at,
                c.is_ab_test,
                c.ab_winner_variant,
                COUNT(m.id) as total_messages,
                COUNT(CASE WHEN m.status IN ('Sent', 'Awaiting DLR', 'Delivered') THEN 1 END) as sent_count,
                COUNT(CASE WHEN m.status = 'Delivered' THEN 1 END) as delivered_count,
                COUNT(CASE WHEN m.status = 'Failed' THEN 1 END) as failed_count,
                COUNT(CASE WHEN m.status = 'Pending' THEN 1 END) as pending_count,
                AVG(CASE WHEN m.delivered_at IS NOT NULL AND m.sent_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(SECOND, m.sent_at, m.delivered_at) END) as avg_delivery_time_seconds
            FROM campaigns c
            LEFT JOIN messages m ON c.id = m.campaign_id
            WHERE c.user_id = ?
            AND c.id IN ($placeholders)
            GROUP BY c.id
            ORDER BY c.created_at DESC
        ");
        
        $stmt->execute(array_merge([$userId], $ids));
        $campaigns = $stmt->fetchAll();
        
        if (count($campaigns) < 2) {
            Response::error('Could not find all specified campaigns', 404);
        }
        
        // Calculate rates and format data
        foreach ($campaigns as &$campaign) {
            $campaign['delivery_rate'] = $campaign['sent_count'] > 0 
                ? round(($campaign['delivered_count'] / $campaign['sent_count']) * 100, 1) 
                : 0;
            $campaign['failure_rate'] = $campaign['sent_count'] > 0 
                ? round(($campaign['failed_count'] / $campaign['sent_count']) * 100, 1) 
                : 0;
            $campaign['avg_delivery_time_seconds'] = $campaign['avg_delivery_time_seconds'] 
                ? round($campaign['avg_delivery_time_seconds'], 1) 
                : null;
            
            // Get hourly distribution
            $stmtHourly = $pdo->prepare("
                SELECT 
                    HOUR(sent_at) as hour,
                    COUNT(*) as count,
                    SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) as delivered
                FROM messages
                WHERE campaign_id = ? AND sent_at IS NOT NULL
                GROUP BY HOUR(sent_at)
                ORDER BY hour
            ");
            $stmtHourly->execute([$campaign['id']]);
            $campaign['hourly_distribution'] = $stmtHourly->fetchAll();
            
            // Get A/B test variants if applicable
            if ($campaign['is_ab_test']) {
                $stmtVariants = $pdo->prepare("
                    SELECT * FROM campaign_variants WHERE campaign_id = ?
                ");
                $stmtVariants->execute([$campaign['id']]);
                $campaign['variants'] = $stmtVariants->fetchAll();
            }
        }
        
        // Calculate comparison metrics
        $comparison = [
            'best_delivery_rate' => null,
            'best_delivery_campaign' => null,
            'fastest_delivery' => null,
            'fastest_campaign' => null,
            'lowest_cost_per_delivery' => null,
            'best_value_campaign' => null,
        ];
        
        foreach ($campaigns as $c) {
            if ($comparison['best_delivery_rate'] === null || $c['delivery_rate'] > $comparison['best_delivery_rate']) {
                $comparison['best_delivery_rate'] = $c['delivery_rate'];
                $comparison['best_delivery_campaign'] = $c['id'];
            }
            
            if ($c['avg_delivery_time_seconds'] !== null) {
                if ($comparison['fastest_delivery'] === null || $c['avg_delivery_time_seconds'] < $comparison['fastest_delivery']) {
                    $comparison['fastest_delivery'] = $c['avg_delivery_time_seconds'];
                    $comparison['fastest_campaign'] = $c['id'];
                }
            }
            
            $costPerDelivery = $c['delivered_count'] > 0 ? $c['actual_cost'] / $c['delivered_count'] : null;
            if ($costPerDelivery !== null) {
                if ($comparison['lowest_cost_per_delivery'] === null || $costPerDelivery < $comparison['lowest_cost_per_delivery']) {
                    $comparison['lowest_cost_per_delivery'] = round($costPerDelivery, 4);
                    $comparison['best_value_campaign'] = $c['id'];
                }
            }
        }
        
        Response::success([
            'campaigns' => $campaigns,
            'comparison' => $comparison,
        ]);
    }
    
    /**
     * Get A/B test results for a campaign
     */
    public function abTestResults(): void {
        $userId = Auth::id();
        $campaignId = Request::query('campaign_id');
        
        if (!$campaignId) {
            Response::error('Campaign ID required', 400);
        }
        
        $campaign = table('campaigns')
            ->where('id', $campaignId)
            ->where('user_id', $userId)
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        if (!$campaign['is_ab_test']) {
            Response::error('Campaign is not an A/B test', 400);
        }
        
        $pdo = db();
        
        // Get variant stats
        $stmt = $pdo->prepare("
            SELECT 
                m.variant_name,
                COUNT(*) as total_count,
                SUM(CASE WHEN m.status IN ('Sent', 'Awaiting DLR', 'Delivered') THEN 1 ELSE 0 END) as sent_count,
                SUM(CASE WHEN m.status = 'Delivered' THEN 1 ELSE 0 END) as delivered_count,
                SUM(CASE WHEN m.status = 'Failed' THEN 1 ELSE 0 END) as failed_count
            FROM messages m
            WHERE m.campaign_id = ? AND m.variant_name IS NOT NULL
            GROUP BY m.variant_name
        ");
        $stmt->execute([$campaignId]);
        $variantStats = $stmt->fetchAll();
        
        // Get variant content
        $stmt = $pdo->prepare("SELECT * FROM campaign_variants WHERE campaign_id = ?");
        $stmt->execute([$campaignId]);
        $variants = $stmt->fetchAll();
        
        // Merge stats with content
        $results = [];
        foreach ($variants as $variant) {
            $stats = null;
            foreach ($variantStats as $s) {
                if ($s['variant_name'] === $variant['variant_name']) {
                    $stats = $s;
                    break;
                }
            }
            
            $deliveryRate = ($stats && $stats['sent_count'] > 0) 
                ? round(($stats['delivered_count'] / $stats['sent_count']) * 100, 1) 
                : 0;
            
            $results[] = [
                'variant_name' => $variant['variant_name'],
                'message_content' => $variant['message_content'],
                'subject' => $variant['subject'],
                'recipient_count' => $stats ? (int)$stats['total_count'] : 0,
                'sent_count' => $stats ? (int)$stats['sent_count'] : 0,
                'delivered_count' => $stats ? (int)$stats['delivered_count'] : 0,
                'failed_count' => $stats ? (int)$stats['failed_count'] : 0,
                'delivery_rate' => $deliveryRate,
                'is_winner' => $campaign['ab_winner_variant'] === $variant['variant_name'],
            ];
        }
        
        // Determine winner if not set
        $winner = null;
        $bestRate = 0;
        foreach ($results as $r) {
            if ($r['sent_count'] >= 10 && $r['delivery_rate'] > $bestRate) {
                $bestRate = $r['delivery_rate'];
                $winner = $r['variant_name'];
            }
        }
        
        Response::success([
            'campaign' => [
                'id' => $campaign['id'],
                'name' => $campaign['name'],
                'type' => $campaign['type'],
                'status' => $campaign['status'],
                'ab_winner_variant' => $campaign['ab_winner_variant'],
            ],
            'variants' => $results,
            'suggested_winner' => $winner,
            'winner_delivery_rate' => $bestRate,
        ]);
    }
    
    /**
     * Manually select A/B test winner
     */
    public function selectAbTestWinner(): void {
        $userId = Auth::id();
        $data = Request::validate([
            'campaign_id' => 'required',
            'variant_name' => 'required',
        ]);
        
        $campaign = table('campaigns')
            ->where('id', $data['campaign_id'])
            ->where('user_id', $userId)
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        if (!$campaign['is_ab_test']) {
            Response::error('Campaign is not an A/B test', 400);
        }
        
        $variant = table('campaign_variants')
            ->where('campaign_id', $data['campaign_id'])
            ->where('variant_name', $data['variant_name'])
            ->first();
        
        if (!$variant) {
            Response::error('Variant not found', 404);
        }
        
        // Update campaign
        table('campaigns')
            ->where('id', $data['campaign_id'])
            ->update([
                'ab_winner_variant' => $data['variant_name'],
                'ab_winner_selected_at' => date('Y-m-d H:i:s'),
                'message' => $variant['message_content'],
                'subject' => $variant['subject'] ?? $campaign['subject'],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        // Reset all variants, then mark winner
        table('campaign_variants')
            ->where('campaign_id', $data['campaign_id'])
            ->update(['is_winner' => 0]);
            
        table('campaign_variants')
            ->where('id', $variant['id'])
            ->update(['is_winner' => 1]);
        
        Response::success([
            'message' => "Variant {$data['variant_name']} selected as winner",
            'winner' => $data['variant_name'],
        ]);
    }
    
    /**
     * Get best performing template based on A/B test history
     */
    public function getBestPerformingVariant(): void {
        $userId = Auth::id();
        $type = Request::query('type', 'sms');
        
        $pdo = db();
        
        // Get winning variants with best delivery rates
        $stmt = $pdo->prepare("
            SELECT 
                cv.message_content,
                cv.subject,
                c.type,
                cv.delivery_rate,
                c.name as campaign_name,
                c.created_at
            FROM campaign_variants cv
            JOIN campaigns c ON cv.campaign_id = c.id
            WHERE c.user_id = ?
            AND c.type = ?
            AND cv.is_winner = 1
            AND cv.delivery_rate > 0
            ORDER BY cv.delivery_rate DESC
            LIMIT 5
        ");
        $stmt->execute([$userId, $type]);
        $winners = $stmt->fetchAll();
        
        Response::success([
            'best_performing' => $winners,
            'recommendation' => count($winners) > 0 
                ? "Based on your A/B tests, messages similar to your top performer achieve {$winners[0]['delivery_rate']}% delivery rate."
                : 'Run more A/B tests to get personalized recommendations.',
        ]);
    }
    
    /**
     * Get report stats for the dashboard
     */
    public function stats(): void {
        $userId = Auth::id();
        $range = Request::query('range', '7d');
        
        $days = $range === '30d' ? 30 : ($range === '90d' ? 90 : 7);
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        
        $pdo = db();
        
        // Get message stats
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_messages,
                SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'Failed' THEN 1 ELSE 0 END) as failed
            FROM messages m
            JOIN campaigns c ON m.campaign_id = c.id
            WHERE c.user_id = ? AND m.created_at >= ?
        ");
        $stmt->execute([$userId, $startDate]);
        $messageStats = $stmt->fetch();
        
        // SMS stats
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_sent,
                SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'Failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
                COALESCE(SUM(cost), 0) as credits_used
            FROM messages m
            JOIN campaigns c ON m.campaign_id = c.id
            WHERE c.user_id = ? AND c.type = 'sms' AND m.created_at >= ?
        ");
        $stmt->execute([$userId, $startDate]);
        $smsStats = $stmt->fetch();
        
        // Email stats
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_sent,
                SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'Failed' THEN 1 ELSE 0 END) as bounced
            FROM messages m
            JOIN campaigns c ON m.campaign_id = c.id
            WHERE c.user_id = ? AND c.type = 'email' AND m.created_at >= ?
        ");
        $stmt->execute([$userId, $startDate]);
        $emailStats = $stmt->fetch();
        
        $totalMessages = (int) $messageStats['total_messages'];
        $delivered = (int) $messageStats['delivered'];
        
        Response::success([
            'summary' => [
                'total_messages' => $totalMessages,
                'delivered' => $delivered,
                'failed' => (int) $messageStats['failed'],
                'avg_delivery_time' => '2.3s',
                'delivery_rate' => $totalMessages > 0 ? round(($delivered / $totalMessages) * 100, 1) : 0,
            ],
            'sms' => $smsStats,
            'email' => array_merge($emailStats ?: [], ['opened' => 0, 'clicked' => 0]),
        ]);
    }
    
    /**
     * Get chart data for reports
     */
    public function chart(): void {
        $userId = Auth::id();
        $range = Request::query('range', '7d');
        $days = $range === '30d' ? 30 : ($range === '90d' ? 90 : 7);
        
        $pdo = db();
        $chart = [];
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN c.type = 'sms' THEN 1 ELSE 0 END) as sms,
                    SUM(CASE WHEN c.type = 'email' THEN 1 ELSE 0 END) as email,
                    SUM(CASE WHEN m.status = 'Delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN m.status = 'Failed' THEN 1 ELSE 0 END) as failed
                FROM messages m
                JOIN campaigns c ON m.campaign_id = c.id
                WHERE c.user_id = ? AND DATE(m.created_at) = ?
            ");
            $stmt->execute([$userId, $date]);
            $row = $stmt->fetch();
            $chart[] = [
                'date' => date('M j', strtotime($date)),
                'sms' => (int) ($row['sms'] ?? 0),
                'email' => (int) ($row['email'] ?? 0),
                'delivered' => (int) ($row['delivered'] ?? 0),
                'failed' => (int) ($row['failed'] ?? 0),
            ];
        }
        
        Response::success(['chart' => $chart]);
    }
    
    /**
     * Get delivery breakdown
     */
    public function delivery(): void {
        $userId = Auth::id();
        $range = Request::query('range', '7d');
        $days = $range === '30d' ? 30 : ($range === '90d' ? 90 : 7);
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT m.status, COUNT(*) as count
            FROM messages m
            JOIN campaigns c ON m.campaign_id = c.id
            WHERE c.user_id = ? AND m.created_at >= ?
            GROUP BY m.status
        ");
        $stmt->execute([$userId, $startDate]);
        $breakdown = $stmt->fetchAll();
        
        Response::success(['breakdown' => $breakdown]);
    }
}
