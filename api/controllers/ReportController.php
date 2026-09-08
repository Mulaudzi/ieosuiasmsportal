<?php
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
        $userId = Auth::id();
        $campaignId = Request::query('campaign_id');
        $format = strtolower((string)Request::query('format', 'csv'));
        $reportType = Request::query('type');

        if (!$campaignId && $reportType === 'campaigns') {
            $range=(string)Request::query('range','90d');
            try {$summary=SmsReportingService::summary((int)$userId,$range);$messageRows=SmsReportingService::rows((int)$userId,$range);} catch(InvalidArgumentException){Response::error('Invalid reporting range',422);return;}
            $headers=['Campaign / Metric','Destination / Value','Status','Segments','Charge (ZAR)','Sent At','Delivered At','Failed At','Details','Record Created'];
            $blank=array_fill(0,count($headers),'');
            $rows=[];
            foreach ([
                ['Reporting period',$range],['Period start',$summary['start']],['Period end',$summary['end']],
                ['Total Messages',$summary['total_messages']],['Delivered',$summary['delivered']],
                ['Failed',$summary['failed']],['Pending',$summary['pending']],
            ] as [$label,$value]) {$row=$blank;$row[0]=$label;$row[1]=$value;$rows[]=$row;}
            $rows[]=$blank;
            foreach($messageRows as $row)$rows[]=$row;
            $this->downloadReport($format,$headers,$rows,'sms-campaign-report-'.date('Y-m-d'));
        }
        
        if (!$campaignId) {
            Response::error('Campaign ID required', 400);
        }
        
        $campaign = table('sms_campaigns')
            ->where('id', $campaignId)
            ->where('user_id', $userId)
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        $messages = table('sms_messages')
            ->where('campaign_id', $campaignId)
            ->where('user_id', $userId)
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
                    $message['destination'],
                    $message['state'],
                    $message['sent_at'],
                    $message['delivered_at'],
                    $message['last_error']??null,
                ]);
            }
            
            fclose($output);
            exit;
        }
        
        Response::success(['messages' => $messages]);
    }

    public function emailReport():void
    {
        $userId=(int)Auth::id();$user=table('users')->where('id',$userId)->first();if(!$user)Response::error('User not found',404);
        $range=(string)Request::input('range','7d');try{$summary=SmsReportingService::summary($userId,$range);$rows=SmsReportingService::rows($userId,$range);}catch(InvalidArgumentException){Response::error('Invalid reporting range',422);return;}
        $escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');$body='<h2>IEOSUIA SMS Report</h2><p>Period: '.$escape($range).' ('.$escape($summary['start']).' to '.$escape($summary['end']).')</p><p><strong>Total Messages:</strong> '.$summary['total_messages'].' &nbsp; <strong>Delivered:</strong> '.$summary['delivered'].' &nbsp; <strong>Failed:</strong> '.$summary['failed'].' &nbsp; <strong>Pending:</strong> '.$summary['pending'].'</p><table cellpadding="6" cellspacing="0" border="1"><tr><th>Campaign</th><th>Destination</th><th>Status</th><th>Segments</th><th>Sent</th><th>Delivered</th><th>Failed</th><th>Details</th></tr>';
        foreach($rows as $row)$body.='<tr><td>'.$escape($row[0]).'</td><td>'.$escape($row[1]).'</td><td>'.$escape($row[2]).'</td><td>'.(int)$row[3].'</td><td>'.$escape($row[5]).'</td><td>'.$escape($row[6]).'</td><td>'.$escape($row[7]).'</td><td>'.$escape($row[8]).'</td></tr>';
        $body.='</table><p>This report was generated from your IEOSUIA SMS Portal account.</p>';
        require_once __DIR__.'/../services/EmailService.php';$result=EmailService::send((string)$user['email'],'IEOSUIA SMS report - '.date('Y-m-d'),$body);
        if(empty($result['success']))Response::error('Report email could not be sent',503);Response::success(['message'=>'Report sent to your registered email address']);
    }

    private function downloadReport(string $format,array $headers,array $rows,string $filename):void
    {
        if($format==='csv'){
            header('Content-Type:text/csv;charset=UTF-8');header('Content-Disposition:attachment;filename="'.$filename.'.csv"');$out=fopen('php://output','w');fputcsv($out,$headers);foreach($rows as $row)fputcsv($out,array_map([$this,'spreadsheetSafe'],$row));fclose($out);exit;
        }
        if(in_array($format,['excel','xls'],true)){
            header('Content-Type:application/vnd.ms-excel;charset=UTF-8');header('Content-Disposition:attachment;filename="'.$filename.'.xls"');echo '<html><head><meta charset="UTF-8"></head><body><table border="1"><tr>';foreach($headers as $header)echo '<th>'.htmlspecialchars($header,ENT_QUOTES,'UTF-8').'</th>';echo '</tr>';foreach($rows as $row){echo '<tr>';foreach($row as $value)echo '<td>'.htmlspecialchars((string)$this->spreadsheetSafe($value),ENT_QUOTES,'UTF-8').'</td>';echo '</tr>';}echo '</table></body></html>';exit;
        }
        if($format==='pdf'){$this->outputPdf($headers,$rows,$filename);}
        Response::error('Unsupported export format',422);
    }

    private function spreadsheetSafe(mixed $value):mixed{return is_string($value)&&preg_match('/^[=+\-@]/',$value)?"'".$value:$value;}

    private function outputPdf(array $headers,array $rows,string $filename):never
    {
        $truncate=static fn($value)=>function_exists('mb_substr')?mb_substr((string)$value,0,28):substr((string)$value,0,28);$lines=['IEOSUIA SMS Campaign Report - '.date('Y-m-d'),implode(' | ',$headers)];foreach($rows as $row)$lines[]=implode(' | ',array_map($truncate,$row));$pages=array_chunk($lines,42);$objects=[];$pageIds=[];$fontId=3+count($pages)*2;
        foreach($pages as $index=>$page){$pageId=3+$index*2;$streamId=$pageId+1;$pageIds[]=$pageId;$commands="BT /F1 8 Tf 30 810 Td 11 TL\n";foreach($page as $line){$safe=str_replace(['\\','(',')',"\r","\n"],['\\\\','\\(','\\)',' ',' '],$line);$commands.='('.$safe.") Tj T*\n";}$commands.='ET';$objects[$pageId]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$fontId} 0 R >> >> /Contents {$streamId} 0 R >>";$objects[$streamId]="<< /Length ".strlen($commands)." >>\nstream\n{$commands}\nendstream";}
        $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';$objects[2]='<< /Type /Pages /Kids ['.implode(' ',array_map(static fn($id)=>$id.' 0 R',$pageIds)).'] /Count '.count($pageIds).' >>';$objects[$fontId]='<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';ksort($objects);$pdf="%PDF-1.4\n";$offsets=[0];for($id=1;$id<=$fontId;$id++){$offsets[$id]=strlen($pdf);$pdf.="{$id} 0 obj\n".$objects[$id]."\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 ".($fontId+1)."\n0000000000 65535 f \n";for($id=1;$id<=$fontId;$id++)$pdf.=sprintf('%010d 00000 n ', $offsets[$id])."\n";$pdf.="trailer\n<< /Size ".($fontId+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";header('Content-Type:application/pdf');header('Content-Disposition:attachment;filename="'.$filename.'.pdf"');header('Content-Length:'.strlen($pdf));echo $pdf;exit;
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
                'sms' AS type,
                c.state AS status,
                c.sender_id,
                c.recipient_count AS total_recipients,
                c.actual_cost,
                c.created_at,
                c.queued_at AS started_at,
                c.completed_at,
                COUNT(m.id) as total_messages,
                COUNT(CASE WHEN m.state IN ('sent','delivered') THEN 1 END) as sent_count,
                COUNT(CASE WHEN m.state = 'delivered' THEN 1 END) as delivered_count,
                COUNT(CASE WHEN m.state = 'failed' THEN 1 END) as failed_count,
                COUNT(CASE WHEN m.state IN ('pending','queued','processing') THEN 1 END) as pending_count,
                AVG(CASE WHEN m.delivered_at IS NOT NULL AND m.sent_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(SECOND, m.sent_at, m.delivered_at) END) as avg_delivery_time_seconds
            FROM sms_campaigns c
            LEFT JOIN sms_messages m ON c.id = m.campaign_id AND m.user_id=c.user_id
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
            $campaign['actual_cost'] = (float)($campaign['actual_cost'] ?? 0);
            foreach (['total_recipients','total_messages','sent_count','delivered_count','failed_count','pending_count'] as $field) {
                $campaign[$field] = (int)($campaign[$field] ?? 0);
            }
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
                    SUM(CASE WHEN state = 'delivered' THEN 1 ELSE 0 END) as delivered
                FROM sms_messages
                WHERE campaign_id = ? AND user_id=? AND sent_at IS NOT NULL
                GROUP BY HOUR(sent_at)
                ORDER BY hour
            ");
            $stmtHourly->execute([$campaign['id'],$userId]);
            $campaign['hourly_distribution'] = $stmtHourly->fetchAll();
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
                'total_sent'=>$sms['total_messages'],'delivered'=>$sms['delivered'],'failed'=>$sms['failed'],'pending'=>$sms['pending'],'awaiting_delivery'=>$sms['awaiting_delivery'],'dlr_unavailable'=>$sms['dlr_unavailable'],'credits_used'=>$sms['credits_used'],
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
