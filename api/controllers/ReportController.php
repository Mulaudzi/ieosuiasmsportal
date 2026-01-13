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
}
