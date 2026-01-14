<?php
/**
 * Cron Controller - API endpoints for cron job management
 */

class CronController {
    
    /**
     * Get cron job status (admin only)
     */
    public function status(): void {
        // Verify admin
        $user = Auth::user();
        // FIXED: Changed from checking non-existent 'role' column to 'account_type'
        if (!$user || ($user['account_type'] ?? 'standard') !== 'admin') {
            Response::error('Unauthorized', 403);
        }
        
        $jobs = table('cron_jobs')->get();
        
        Response::success(['jobs' => $jobs]);
    }
    
    /**
     * Manually trigger scheduled campaign processing (admin only)
     */
    public function runScheduledCampaigns(): void {
        // Verify admin
        $user = Auth::user();
        // FIXED: Changed from checking non-existent 'role' column to 'account_type'
        if (!$user || ($user['account_type'] ?? 'standard') !== 'admin') {
            Response::error('Unauthorized', 403);
        }
        
        // Update job status
        table('cron_jobs')->where('job_name', 'process_scheduled_campaigns')->update([
            'status' => 'running',
            'last_run_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Find scheduled campaigns that are due
        $now = date('Y-m-d H:i:s');
        $campaigns = table('campaigns')
            ->where('status', 'scheduled')
            ->get();
        
        $dueCampaigns = array_filter($campaigns, function($c) use ($now) {
            return !empty($c['scheduled_at']) && $c['scheduled_at'] <= $now;
        });
        
        $results = [
            'total_due' => count($dueCampaigns),
            'processed' => 0,
            'errors' => [],
        ];
        
        foreach ($dueCampaigns as $campaign) {
            try {
                // Mark as processing
                table('campaigns')->where('id', $campaign['id'])->update([
                    'status' => 'processing',
                    'started_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                
                // Dispatch to appropriate handler
                if ($campaign['type'] === 'sms') {
                    $result = $this->processSMSCampaign($campaign);
                } else {
                    $result = $this->processEmailCampaign($campaign);
                }
                
                if ($result['success']) {
                    table('campaigns')->where('id', $campaign['id'])->update([
                        'status' => 'completed',
                        'completed_at' => date('Y-m-d H:i:s'),
                        'actual_cost' => $result['total_cost'] ?? 0,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $results['processed']++;
                    
                    // Log
                    require_once __DIR__ . '/../services/AuditLogService.php';
                    AuditLogService::log('campaign_scheduled_sent', 'campaign', (int) $campaign['id'], null, [
                        'name' => $campaign['name'],
                        'type' => $campaign['type'],
                        'sent' => $result['sent'] ?? 0,
                        'failed' => $result['failed'] ?? 0,
                        'triggered_by' => 'admin_manual',
                    ], Auth::id());
                    
                } else {
                    table('campaigns')->where('id', $campaign['id'])->update([
                        'status' => 'failed',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $results['errors'][] = [
                        'campaign_id' => $campaign['id'],
                        'error' => $result['error'] ?? 'Unknown error',
                    ];
                }
                
            } catch (Exception $e) {
                table('campaigns')->where('id', $campaign['id'])->update([
                    'status' => 'failed',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $results['errors'][] = [
                    'campaign_id' => $campaign['id'],
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        // Update job status
        table('cron_jobs')->where('job_name', 'process_scheduled_campaigns')->update([
            'status' => 'completed',
            'run_count' => table('cron_jobs')
                ->where('job_name', 'process_scheduled_campaigns')
                ->first()['run_count'] + 1,
            'last_result' => json_encode($results),
            'error_count' => count($results['errors']) > 0 
                ? table('cron_jobs')->where('job_name', 'process_scheduled_campaigns')->first()['error_count'] + 1 
                : table('cron_jobs')->where('job_name', 'process_scheduled_campaigns')->first()['error_count'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success([
            'message' => "Processed {$results['processed']} of {$results['total_due']} scheduled campaigns",
            'results' => $results,
        ]);
    }
    
    /**
     * Get pending scheduled campaigns (admin only)
     */
    public function pendingCampaigns(): void {
        $user = Auth::user();
        if (!$user || ($user['account_type'] ?? 'standard') !== 'admin') {
            Response::error('Unauthorized', 403);
        }
        
        $campaigns = table('campaigns')
            ->where('status', 'scheduled')
            ->orderBy('scheduled_at', 'ASC')
            ->limit(50)
            ->get();
        
        // Add user info
        foreach ($campaigns as &$campaign) {
            $campaignUser = table('users')
                ->where('id', $campaign['user_id'])
                ->select(['id', 'name', 'email'])
                ->first();
            $campaign['user'] = $campaignUser;
        }
        
        Response::success(['campaigns' => $campaigns]);
    }
    
    /**
     * Process SMS campaign
     */
    private function processSMSCampaign(array $campaign): array {
        require_once __DIR__ . '/../services/SmsService.php';
        
        $smsService = new SmsService();
        $userId = (int) $campaign['user_id'];
        $wallet = table('wallets')->where('user_id', $userId)->first();
        
        if (!$wallet) {
            return ['success' => false, 'error' => 'Wallet not found'];
        }
        
        $messages = table('messages')
            ->where('campaign_id', $campaign['id'])
            ->where('status', 'pending')
            ->get();
        
        $sent = 0;
        $failed = 0;
        $totalCost = 0;
        
        foreach ($messages as $message) {
            $messageCost = (float) $message['cost'];
            
            if ((float) $wallet['balance'] < $messageCost) {
                table('messages')->where('id', $message['id'])->update([
                    'status' => 'failed',
                    'error_message' => 'Insufficient balance',
                    'failed_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $failed++;
                continue;
            }
            
            $result = $smsService->send(
                $message['recipient'],
                $message['content'],
                $campaign['sender_id']
            );
            
            if ($result['success']) {
                table('messages')->where('id', $message['id'])->update([
                    'status' => 'sent',
                    'external_id' => $result['message_id'] ?? null,
                    'gateway_response' => json_encode($result),
                    'sent_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                
                table('wallets')->where('id', $wallet['id'])->update([
                    'balance' => (float) $wallet['balance'] - $messageCost,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $wallet['balance'] -= $messageCost;
                
                $sent++;
                $totalCost += $messageCost;
            } else {
                table('messages')->where('id', $message['id'])->update([
                    'status' => 'failed',
                    'error_message' => $result['error'] ?? 'Send failed',
                    'failed_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $failed++;
            }
            
            usleep(100000); // 100ms delay
        }
        
        if ($totalCost > 0) {
            table('wallet_transactions')->insert([
                'wallet_id' => $wallet['id'],
                'amount' => -$totalCost,
                'type' => 'debit',
                'description' => "Scheduled SMS Campaign: {$campaign['name']}",
                'reference' => "CAMP-{$campaign['id']}",
                'status' => 'completed',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        return [
            'success' => true,
            'sent' => $sent,
            'failed' => $failed,
            'total_cost' => $totalCost,
        ];
    }
    
    /**
     * Process Email campaign
     */
    private function processEmailCampaign(array $campaign): array {
        require_once __DIR__ . '/../services/BatchEmailService.php';
        
        $userId = (int) $campaign['user_id'];
        
        try {
            $batchService = new BatchEmailService($userId);
            $result = $batchService->sendCampaign((int) $campaign['id']);
            
            if (!$result['success']) {
                return ['success' => false, 'error' => $result['error'] ?? 'Send failed'];
            }
            
            $totalCost = ($result['sent'] ?? 0) * (float) env('EMAIL_PRICE_PER_CREDIT', 0.05);
            
            $wallet = table('wallets')->where('user_id', $userId)->first();
            if ($wallet && $totalCost > 0) {
                table('wallets')->where('id', $wallet['id'])->update([
                    'balance' => max(0, (float) $wallet['balance'] - $totalCost),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                
                table('wallet_transactions')->insert([
                    'wallet_id' => $wallet['id'],
                    'amount' => -$totalCost,
                    'type' => 'debit',
                    'description' => "Scheduled Email Campaign: {$campaign['name']}",
                    'reference' => "CAMP-{$campaign['id']}",
                    'status' => 'completed',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            
            return [
                'success' => true,
                'sent' => $result['sent'] ?? 0,
                'failed' => $result['failed'] ?? 0,
                'total_cost' => $totalCost,
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
