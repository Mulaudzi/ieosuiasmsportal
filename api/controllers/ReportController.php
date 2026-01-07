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
}
