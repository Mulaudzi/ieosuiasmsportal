<?php
require_once __DIR__.'/../domain/SmsDeliveryPresentation.php';
/**
 * Report Controller
 */

require_once __DIR__.'/../domain/SmsReportingService.php';

class ReportController {
    public function campaigns(): void {
        $userId = Auth::id();
        $startDate = Request::query('start_date', date('Y-m-d', strtotime('-30 days')));
        $endDate = Request::query('end_date', date('Y-m-d'));
        $type = Request::query('type');
        if ($type && $type !== 'sms') Response::error('Email campaign reporting is coming soon.', 410);
        
        $pdo = db();
        
        $sql = "
            SELECT 
                c.id,
                c.name,
                'sms' AS type,
                c.state AS status,
                c.recipient_count AS total_recipients,
                c.actual_cost,
                c.created_at,
                c.completed_at,
                COUNT(CASE WHEN m.state IN ('sent','delivered') THEN 1 END) as sent_count,
                COUNT(CASE WHEN m.state = 'delivered' THEN 1 END) as delivered_count,
                COUNT(CASE WHEN m.state = 'failed' THEN 1 END) as failed_count
            FROM sms_campaigns c
            LEFT JOIN sms_messages m ON c.id = m.campaign_id AND m.user_id=c.user_id
            WHERE c.user_id = ?
            AND c.created_at BETWEEN ? AND ?
        ";
        
        $params = [$userId, $startDate . ' 00:00:00', $endDate . ' 23:59:59'];
        
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
        $campaign = table('sms_campaigns')
            ->where('id', $campaignId)
            ->where('user_id', $userId)
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        $query = table('sms_messages')->where('campaign_id', $campaignId)->where('user_id', $userId);
        
        if ($status) {
            $query->where('status', $status);
        }
        
        $total = $query->count();
        
        $messages = table('sms_messages')
            ->where('campaign_id', $campaignId)
            ->where('user_id', $userId);
        
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
        require_once __DIR__.'/../services/SmsReportDocument.php';
        $campaignId=Request::query('campaign_id');
        try {
            $r=SmsReportDocument::load((int)Auth::id(),$campaignId?(int)$campaignId:null,(string)Request::query('range','90d'),(string)Request::query('state',''),trim((string)Request::query('search','')));
            SmsReportDocument::download($r,strtolower((string)Request::query('format','csv')));
        }catch(InvalidArgumentException $e){Response::error($e->getMessage(),422);}
        catch(RuntimeException $e){Response::error('Campaign not found',404);}
    }

    public function emailReport():void
    {
        require_once __DIR__.'/../services/SmsReportDocument.php';
        $userId=(int)Auth::id();$user=table('users')->where('id',$userId)->first();if(!$user){Response::error('User not found',404);return;}
        $id=Request::input('campaign_id');
        try{$r=SmsReportDocument::load($userId,$id?(int)$id:null,(string)Request::input('range','7d'),(string)Request::input('state',''),trim((string)Request::input('search','')));}
        catch(InvalidArgumentException $e){Response::error($e->getMessage(),422);return;}
        catch(RuntimeException $e){Response::error('Campaign not found',404);return;}
        $result=SmsReportDocument::send($r,(string)$user['email']);
        if(empty($result['success'])){Response::error('Report email could not be sent',503);return;}
        Response::success(['message'=>'Report and PDF/CSV attachments sent to your registered email address']);
    }

    /**
     * Compare multiple campaigns side by side
     */
    public function compare(): void {
        require_once __DIR__.'/../services/CampaignComparisonService.php';
        try {
            $ids = Request::query('selection') === '1'
                ? null : CampaignComparisonService::ids((string)Request::query('ids', ''));
            Response::success([
                'campaigns' => CampaignComparisonService::campaigns(db(), (int)Auth::id(), $ids),
            ]);
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400);
        } catch (OutOfBoundsException $e) {
            Response::error('Could not find all selected campaigns', 404);
        }
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
        $userId = (int)Auth::id();$range=(string)Request::query('range','7d');try{$sms=SmsReportingService::summary($userId,$range);}catch(InvalidArgumentException){Response::error('Invalid reporting range',422);return;}$averageSeconds=$sms['avg_delivery_seconds'];
        Response::success([
            'summary' => [
                'total_messages' => $sms['total_messages'],
                'delivered' => $sms['delivered'],
                'failed' => $sms['failed'],
                'pending' => $sms['pending'],
                'avg_delivery_time' => $averageSeconds === null ? 'N/A' : round($averageSeconds,1).'s',
                'delivery_rate' => $sms['delivery_rate'],
            ],
            'sms' => [
                'total_sent'=>$sms['total_messages'],'delivered'=>$sms['delivered'],'failed'=>$sms['failed'],'pending'=>$sms['pending'],'awaiting_delivery'=>$sms['awaiting_delivery'],'dlr_unavailable'=>$sms['dlr_unavailable'],'skipped'=>$sms['skipped'],'credits_used'=>$sms['credits_used'],
            ],
            'email' => ['coming_soon'=>true,'total_sent'=>0,'delivered'=>0,'opened'=>0,'clicked'=>0,'bounced'=>0],
        ]);
    }
    
    /**
     * Get chart data for reports
     */
    public function chart(): void {
        $userId=(int)Auth::id();$range=(string)Request::query('range','7d');try{$rows=SmsReportingService::daily($userId,$range);}catch(InvalidArgumentException){Response::error('Invalid reporting range',422);return;}$chart=array_map(static fn($row)=>['date'=>date('M j',strtotime($row['date'])),'sms'=>(int)$row['total_messages'],'email'=>0,'delivered'=>(int)$row['delivered'],'failed'=>(int)$row['failed'],'pending'=>(int)$row['pending']],$rows);
        
        Response::success(['chart' => $chart]);
    }
    
    /**
     * Get delivery breakdown
     */
    public function delivery(): void {
        $userId=(int)Auth::id();$range=(string)Request::query('range','7d');try{$breakdown=SmsReportingService::breakdown($userId,$range);}catch(InvalidArgumentException){Response::error('Invalid reporting range',422);return;}
        
        Response::success(['breakdown' => $breakdown]);
    }
}
