<?php
/**
 * Scheduled Campaign Processor
 * Run via cron: * * * * * php /path/to/api/cron/process_scheduled_campaigns.php
 * 
 * This script runs every minute and processes campaigns that are due to be sent.
 */

// Set working directory
chdir(dirname(__DIR__));

// Load environment
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/QueryBuilder.php';
require_once __DIR__ . '/../services/SmsService.php';
require_once __DIR__ . '/../services/BatchEmailService.php';
require_once __DIR__ . '/../services/AuditLogService.php';
require_once __DIR__ . '/../services/AdminNotificationService.php';

// Lock file to prevent overlapping runs
$lockFile = sys_get_temp_dir() . '/campaign_processor.lock';

// Check if already running
if (file_exists($lockFile)) {
    $lockTime = (int) file_get_contents($lockFile);
    if (time() - $lockTime < 300) { // 5 minute timeout
        echo "Another instance is already running.\n";
        exit(0);
    }
}

// Create lock
file_put_contents($lockFile, time());

try {
    echo "[" . date('Y-m-d H:i:s') . "] Starting scheduled campaign processor...\n";
    
    // Find campaigns due for sending
    $now = date('Y-m-d H:i:s');
    $campaigns = table('campaigns')
        ->where('status', 'scheduled')
        ->get();
    
    // Filter to campaigns where scheduled_at <= now
    $dueCampaigns = array_filter($campaigns, function($campaign) use ($now) {
        return !empty($campaign['scheduled_at']) && $campaign['scheduled_at'] <= $now;
    });
    
    $processed = 0;
    $errors = [];
    
    foreach ($dueCampaigns as $campaign) {
        try {
            echo "[" . date('Y-m-d H:i:s') . "] Processing campaign {$campaign['id']}: {$campaign['name']}\n";
            
            // Update status to processing
            table('campaigns')->where('id', $campaign['id'])->update([
                'status' => 'processing',
                'started_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            // Process based on type
            if ($campaign['type'] === 'sms') {
                $result = processSMSCampaign($campaign);
            } else {
                $result = processEmailCampaign($campaign);
            }
            
            if ($result['success']) {
                // Update campaign to completed
                table('campaigns')->where('id', $campaign['id'])->update([
                    'status' => 'completed',
                    'completed_at' => date('Y-m-d H:i:s'),
                    'actual_cost' => $result['total_cost'] ?? 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                
                // Log successful processing
                AuditLogService::log('campaign_scheduled_sent', 'campaign', (int) $campaign['id'], null, [
                    'name' => $campaign['name'],
                    'type' => $campaign['type'],
                    'sent' => $result['sent'] ?? 0,
                    'failed' => $result['failed'] ?? 0,
                    'total_cost' => $result['total_cost'] ?? 0,
                ], (int) $campaign['user_id']);
                
                echo "[" . date('Y-m-d H:i:s') . "] Campaign {$campaign['id']} completed. Sent: {$result['sent']}, Failed: {$result['failed']}\n";
                
                // Check for failures and notify admin
                if (!empty($result['failed']) && $result['failed'] > 0) {
                    $totalMessages = ($result['sent'] ?? 0) + $result['failed'];
                    $failureRate = $totalMessages > 0 ? $result['failed'] / $totalMessages : 0;
                    
                    if ($failureRate > 0.2 || $result['failed'] >= 10) {
                        AdminNotificationService::notifyHighFailureRate(
                            (int) $campaign['id'],
                            $campaign['name'],
                            $failureRate,
                            (int) $campaign['user_id']
                        );
                    }
                }
                
                $processed++;
            } else {
                // Mark as failed
                table('campaigns')->where('id', $campaign['id'])->update([
                    'status' => 'failed',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                
                $errors[] = "Campaign {$campaign['id']}: " . ($result['error'] ?? 'Unknown error');
                echo "[" . date('Y-m-d H:i:s') . "] Campaign {$campaign['id']} failed: " . ($result['error'] ?? 'Unknown') . "\n";
                
                // Notify admin of failure
                AdminNotificationService::notifyCampaignFailed(
                    (int) $campaign['id'],
                    $campaign['name'],
                    0,
                    (int) $campaign['user_id']
                );
            }
            
        } catch (Exception $e) {
            $errors[] = "Campaign {$campaign['id']}: " . $e->getMessage();
            error_log("Scheduled campaign error: " . $e->getMessage());
            
            table('campaigns')->where('id', $campaign['id'])->update([
                'status' => 'failed',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Finished. Processed: $processed, Errors: " . count($errors) . "\n";
    
    // Log cron execution
    if ($processed > 0 || count($errors) > 0) {
        AuditLogService::log('cron_executed', 'system', null, null, [
            'job' => 'process_scheduled_campaigns',
            'processed' => $processed,
            'errors' => count($errors),
            'error_details' => $errors,
        ]);
    }
    
} catch (Exception $e) {
    error_log("Cron job error: " . $e->getMessage());
    echo "[" . date('Y-m-d H:i:s') . "] Fatal error: " . $e->getMessage() . "\n";
} finally {
    // Remove lock
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

/**
 * Process SMS Campaign
 */
function processSMSCampaign(array $campaign): array {
    $smsService = new SmsService();
    $userId = (int) $campaign['user_id'];
    
    // Get user wallet
    $wallet = table('wallets')->where('user_id', $userId)->first();
    if (!$wallet) {
        return ['success' => false, 'error' => 'User wallet not found'];
    }
    
    // Get pending messages
    $messages = table('messages')
        ->where('campaign_id', $campaign['id'])
        ->where('status', 'pending')
        ->get();
    
    $sent = 0;
    $failed = 0;
    $totalCost = 0;
    
    foreach ($messages as $message) {
        // Check opt-out
        $optedOut = table('opt_outs')
            ->where('user_id', $userId)
            ->where('recipient', $message['recipient'])
            ->first();
        
        if ($optedOut) {
            table('messages')->where('id', $message['id'])->update([
                'status' => 'opted_out',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            continue;
        }
        
        // Check wallet balance
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
        
        // Send SMS
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
            
            // Debit wallet
            table('wallets')->where('id', $wallet['id'])->update([
                'balance' => (float) $wallet['balance'] - $messageCost,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $wallet['balance'] = (float) $wallet['balance'] - $messageCost;
            
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
        
        // Rate limiting - small delay between sends
        usleep(100000); // 100ms delay
    }
    
    // Create wallet transaction for the campaign
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
 * Process Email Campaign
 */
function processEmailCampaign(array $campaign): array {
    $userId = (int) $campaign['user_id'];
    
    try {
        $batchService = new BatchEmailService($userId);
        
        // Check sending limits
        $messageCount = table('messages')
            ->where('campaign_id', $campaign['id'])
            ->where('status', 'pending')
            ->count();
        
        $limitCheck = $batchService->checkSendingLimits($messageCount);
        if (!$limitCheck['can_send'] && $limitCheck['hourly_remaining'] <= 0) {
            return ['success' => false, 'error' => 'Hourly sending limit exceeded'];
        }
        
        // Send campaign
        $result = $batchService->sendCampaign((int) $campaign['id']);
        
        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'Send failed'];
        }
        
        // Calculate actual cost
        $totalCost = ($result['sent'] ?? 0) * (float) env('EMAIL_PRICE_PER_CREDIT', 0.05);
        
        // Debit wallet
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
