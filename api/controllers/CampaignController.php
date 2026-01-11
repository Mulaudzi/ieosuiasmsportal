<?php
/**
 * Campaign Controller
 */

require_once __DIR__ . '/../services/SmsService.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../services/BatchEmailService.php';
require_once __DIR__ . '/../services/AuditLogService.php';
require_once __DIR__ . '/../services/AdminNotificationService.php';

class CampaignController {
    // SMS Campaigns
    public function smsIndex(): void {
        $userId = Auth::id();
        $page = (int) Request::query('page', 1);
        $perPage = (int) Request::query('per_page', 20);
        
        $total = table('campaigns')
            ->where('user_id', $userId)
            ->where('type', 'sms')
            ->count();
        
        $campaigns = table('campaigns')
            ->where('user_id', $userId)
            ->where('type', 'sms')
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();
        
        // Add message counts
        foreach ($campaigns as &$campaign) {
            $this->addMessageCounts($campaign);
        }
        
        Response::paginate($campaigns, $total, $page, $perPage);
    }
    
    public function smsStore(): void {
        $data = Request::validate([
            'name' => 'required|max:100',
            'message' => 'required|max:918', // 6 SMS parts max
            'sender_id' => 'max:11',
            'recipients' => 'required|array',
            'scheduled_at' => '',
        ]);
        
        $userId = Auth::id();
        $recipients = $data['recipients'];
        $cost = count($recipients) * (float) env('SMS_PRICE_PER_CREDIT', 0.38);
        
        // Check wallet balance
        $wallet = table('wallets')->where('user_id', $userId)->first();
        if (!$wallet || (float) $wallet['balance'] < $cost) {
            Response::error('Insufficient balance', 400);
        }
        
        // Create campaign
        $campaignId = table('campaigns')->insert([
            'user_id' => $userId,
            'type' => 'sms',
            'name' => $data['name'],
            'message' => $data['message'],
            'sender_id' => $data['sender_id'] ?? env('LOGICSMS_DEFAULT_SENDER', 'IEOSUIA'),
            'status' => isset($data['scheduled_at']) ? 'Scheduled' : 'Draft',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'total_recipients' => count($recipients),
            'estimated_cost' => $cost,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Create messages
        foreach ($recipients as $recipient) {
            $phone = is_array($recipient) ? $recipient['phone'] : $recipient;
            $name = is_array($recipient) ? ($recipient['name'] ?? '') : '';
            
            // Replace placeholders
            $content = str_replace(
                ['{name}', '{phone}'],
                [$name, $phone],
                $data['message']
            );
            
            table('messages')->insert([
                'campaign_id' => $campaignId,
                'recipient' => $phone,
                'content' => $content,
                'status' => 'Pending',
                'cost' => (float) env('SMS_PRICE_PER_CREDIT', 0.38),
                'parts' => ceil(strlen($content) / 160),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        // Reserve funds
        table('wallets')->where('id', $wallet['id'])->update([
            'reserved' => (float) $wallet['reserved'] + $cost,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $campaign = table('campaigns')->where('id', $campaignId)->first();
        $this->addMessageCounts($campaign);
        
        // Log campaign creation
        AuditLogService::log('campaign_created', 'campaign', $campaignId, null, [
            'name' => $data['name'],
            'type' => 'sms',
            'recipients' => count($recipients),
        ]);
        
        Response::created(['campaign' => $campaign]);
    }
    
    public function smsShow(array $params): void {
        $campaign = table('campaigns')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->where('type', 'sms')
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        $this->addMessageCounts($campaign);
        
        // Get messages
        $messages = table('messages')
            ->where('campaign_id', $campaign['id'])
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get();
        
        $campaign['messages'] = $messages;
        
        Response::success(['campaign' => $campaign]);
    }
    
    public function smsSend(array $params): void {
        $campaign = table('campaigns')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->where('type', 'sms')
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        if (!in_array($campaign['status'], ['Draft', 'Scheduled'])) {
            Response::error('Campaign cannot be sent', 400);
        }
        
        // Update status
        table('campaigns')->where('id', $campaign['id'])->update([
            'status' => 'Sending',
            'started_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Send messages
        $smsService = new SmsService();
        $messages = table('messages')
            ->where('campaign_id', $campaign['id'])
            ->where('status', 'Pending')
            ->get();
        
        $userId = Auth::id();
        $wallet = table('wallets')->where('user_id', $userId)->first();
        $totalCost = 0;
        
        foreach ($messages as $message) {
            // Check opt-out
            $optedOut = table('opt_outs')
                ->where('user_id', $userId)
                ->where('phone', $message['recipient'])
                ->first();
            
            if ($optedOut) {
                table('messages')->where('id', $message['id'])->update([
                    'status' => 'Opted-Out',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                continue;
            }
            
            $result = $smsService->send(
                $message['recipient'],
                $message['content'],
                $campaign['sender_id']
            );
            
            if ($result['success']) {
                table('messages')->where('id', $message['id'])->update([
                    'status' => 'Awaiting DLR',
                    'external_id' => $result['message_id'],
                    'gateway_response' => json_encode($result),
                    'sent_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $totalCost += (float) $message['cost'];
            } else {
                table('messages')->where('id', $message['id'])->update([
                    'status' => 'Failed',
                    'error_message' => $result['error'],
                    'failed_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
        
        // Debit wallet
        if ($totalCost > 0) {
            table('wallets')->where('id', $wallet['id'])->update([
                'balance' => (float) $wallet['balance'] - $totalCost,
                'reserved' => max(0, (float) $wallet['reserved'] - (float) $campaign['estimated_cost']),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            table('wallet_transactions')->insert([
                'wallet_id' => $wallet['id'],
                'amount' => -$totalCost,
                'type' => 'debit',
                'description' => "SMS Campaign: {$campaign['name']}",
                'reference' => "CAMP-{$campaign['id']}",
                'status' => 'completed',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        // Update campaign status
        table('campaigns')->where('id', $campaign['id'])->update([
            'status' => 'Sent',
            'completed_at' => date('Y-m-d H:i:s'),
            'actual_cost' => $totalCost,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $campaign = table('campaigns')->where('id', $campaign['id'])->first();
        $this->addMessageCounts($campaign);
        
        // Log campaign sending
        AuditLogService::log('campaign_sent', 'campaign', (int) $campaign['id'], null, [
            'name' => $campaign['name'],
            'type' => 'sms',
            'total_cost' => $totalCost,
        ]);
        
        // Check for high failure rate and notify admins
        $failedCount = table('messages')
            ->where('campaign_id', $campaign['id'])
            ->where('status', 'Failed')
            ->count();
        
        if ($failedCount > 0) {
            $totalMessages = (int) $campaign['total_recipients'];
            $failureRate = $totalMessages > 0 ? $failedCount / $totalMessages : 0;
            
            if ($failureRate > 0.2) { // More than 20% failure
                AdminNotificationService::notifyHighFailureRate(
                    (int) $campaign['id'],
                    $campaign['name'],
                    $failureRate,
                    $userId
                );
            }
            
            if ($failedCount >= 10) {
                AdminNotificationService::notifyCampaignFailed(
                    (int) $campaign['id'],
                    $campaign['name'],
                    $failedCount,
                    $userId
                );
            }
        }
        
        Response::success(['campaign' => $campaign, 'message' => 'Campaign sent successfully']);
    }
    
    // Email Campaigns
    public function emailIndex(): void {
        $userId = Auth::id();
        $page = (int) Request::query('page', 1);
        $perPage = (int) Request::query('per_page', 20);
        
        $total = table('campaigns')
            ->where('user_id', $userId)
            ->where('type', 'email')
            ->count();
        
        $campaigns = table('campaigns')
            ->where('user_id', $userId)
            ->where('type', 'email')
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();
        
        foreach ($campaigns as &$campaign) {
            $this->addMessageCounts($campaign);
        }
        
        Response::paginate($campaigns, $total, $page, $perPage);
    }
    
    public function emailStore(): void {
        $data = Request::validate([
            'name' => 'required|max:100',
            'subject' => 'required|max:255',
            'message' => 'required',
            'recipients' => 'required|array',
            'scheduled_at' => '',
        ]);
        
        $userId = Auth::id();
        $recipients = $data['recipients'];
        $cost = count($recipients) * (float) env('EMAIL_PRICE_PER_CREDIT', 0.05);
        
        $wallet = table('wallets')->where('user_id', $userId)->first();
        if (!$wallet || (float) $wallet['balance'] < $cost) {
            Response::error('Insufficient balance', 400);
        }
        
        $campaignId = table('campaigns')->insert([
            'user_id' => $userId,
            'type' => 'email',
            'name' => $data['name'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'status' => isset($data['scheduled_at']) ? 'Scheduled' : 'Draft',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'total_recipients' => count($recipients),
            'estimated_cost' => $cost,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        foreach ($recipients as $recipient) {
            $email = is_array($recipient) ? $recipient['email'] : $recipient;
            $name = is_array($recipient) ? ($recipient['name'] ?? '') : '';
            
            $content = str_replace(['{name}', '{email}'], [$name, $email], $data['message']);
            
            table('messages')->insert([
                'campaign_id' => $campaignId,
                'recipient' => $email,
                'subject' => $data['subject'],
                'content' => $content,
                'status' => 'Pending',
                'cost' => (float) env('EMAIL_PRICE_PER_CREDIT', 0.05),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        table('wallets')->where('id', $wallet['id'])->update([
            'reserved' => (float) $wallet['reserved'] + $cost,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $campaign = table('campaigns')->where('id', $campaignId)->first();
        $this->addMessageCounts($campaign);
        
        // Log campaign creation
        AuditLogService::log('campaign_created', 'campaign', $campaignId, null, [
            'name' => $data['name'],
            'type' => 'email',
            'recipients' => count($recipients),
        ]);
        
        Response::created(['campaign' => $campaign]);
    }
    
    public function emailShow(array $params): void {
        $campaign = table('campaigns')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->where('type', 'email')
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        $this->addMessageCounts($campaign);
        
        $messages = table('messages')
            ->where('campaign_id', $campaign['id'])
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get();
        
        $campaign['messages'] = $messages;
        
        Response::success(['campaign' => $campaign]);
    }
    
    public function emailSend(array $params): void {
        $campaign = table('campaigns')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->where('type', 'email')
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        if (!in_array($campaign['status'], ['Draft', 'Scheduled'])) {
            Response::error('Campaign cannot be sent', 400);
        }
        
        // Use BatchEmailService for production sending with retries
        $batchService = new BatchEmailService(Auth::id());
        
        // Check sending limits
        $messageCount = table('messages')
            ->where('campaign_id', $campaign['id'])
            ->whereIn('status', ['Pending', 'queued'])
            ->count();
        
        $limitCheck = $batchService->checkSendingLimits($messageCount);
        if (!$limitCheck['can_send']) {
            Response::error('Sending limit exceeded. Hourly remaining: ' . $limitCheck['hourly_remaining'] . ', Daily remaining: ' . $limitCheck['daily_remaining'], 429);
        }
        
        // Debit wallet before sending
        $userId = Auth::id();
        $wallet = table('wallets')->where('user_id', $userId)->first();
        $estimatedCost = (float) $campaign['estimated_cost'];
        
        if ($wallet && $estimatedCost > 0) {
            if ((float) $wallet['balance'] < $estimatedCost) {
                Response::error('Insufficient balance', 400);
            }
        }
        
        // Send campaign
        $result = $batchService->sendCampaign($campaign['id']);
        
        if (!$result['success']) {
            Response::error($result['error'], 500);
        }
        
        // Calculate actual cost based on sent messages
        $actualCost = $result['sent'] * (float) env('EMAIL_PRICE_PER_CREDIT', 0.05);
        
        // Debit wallet
        if ($wallet && $actualCost > 0) {
            table('wallets')->where('id', $wallet['id'])->update([
                'balance' => (float) $wallet['balance'] - $actualCost,
                'reserved' => max(0, (float) $wallet['reserved'] - $estimatedCost),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            table('wallet_transactions')->insert([
                'wallet_id' => $wallet['id'],
                'amount' => -$actualCost,
                'type' => 'debit',
                'description' => "Email Campaign: {$campaign['name']}",
                'reference' => "CAMP-{$campaign['id']}",
                'status' => 'completed',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        // Update campaign with actual cost
        table('campaigns')->where('id', $campaign['id'])->update([
            'actual_cost' => $actualCost,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $campaign = table('campaigns')->where('id', $campaign['id'])->first();
        $this->addMessageCounts($campaign);
        
        // Log campaign sending
        AuditLogService::log('campaign_sent', 'campaign', (int) $campaign['id'], null, [
            'name' => $campaign['name'],
            'type' => 'email',
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'actual_cost' => $actualCost,
        ]);
        
        // Check for high failure rate and notify admins
        if ($result['failed'] > 0) {
            $totalMessages = $result['sent'] + $result['failed'];
            $failureRate = $totalMessages > 0 ? $result['failed'] / $totalMessages : 0;
            
            if ($failureRate > 0.2) { // More than 20% failure
                AdminNotificationService::notifyHighFailureRate(
                    (int) $campaign['id'],
                    $campaign['name'],
                    $failureRate,
                    Auth::id()
                );
            }
            
            if ($result['failed'] >= 10) {
                AdminNotificationService::notifyCampaignFailed(
                    (int) $campaign['id'],
                    $campaign['name'],
                    $result['failed'],
                    Auth::id()
                );
            }
        }
        
        Response::success([
            'campaign' => $campaign,
            'message' => 'Campaign sent successfully',
            'sent' => $result['sent'],
            'failed' => $result['failed'],
        ]);
    }
    
    /**
     * Retry failed messages in a campaign
     */
    public function retryFailed(array $params): void {
        $campaign = table('campaigns')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        if ($campaign['type'] === 'email') {
            $batchService = new BatchEmailService(Auth::id());
            $result = $batchService->retryFailedMessages($campaign['id']);
            
            Response::success($result);
        } else {
            // SMS retry logic
            $messages = table('messages')
                ->where('campaign_id', $campaign['id'])
                ->where('status', 'Failed')
                ->where('retry_count', '<', 3)
                ->get();
            
            if (empty($messages)) {
                Response::success(['message' => 'No messages to retry', 'retried' => 0]);
            }
            
            $smsService = new SmsService();
            $retried = 0;
            $succeeded = 0;
            
            foreach ($messages as $message) {
                $result = $smsService->send(
                    $message['recipient'],
                    $message['content'],
                    $campaign['sender_id']
                );
                
                $retried++;
                
                if ($result['success']) {
                    table('messages')->where('id', $message['id'])->update([
                        'status' => 'Awaiting DLR',
                        'external_id' => $result['message_id'],
                        'retry_count' => (int) $message['retry_count'] + 1,
                        'sent_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $succeeded++;
                } else {
                    table('messages')->where('id', $message['id'])->update([
                        'retry_count' => (int) $message['retry_count'] + 1,
                        'error_message' => $result['error'],
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
            
            Response::success([
                'retried' => $retried,
                'succeeded' => $succeeded,
                'still_failed' => $retried - $succeeded,
            ]);
        }
    }
    
    /**
     * Upload attachment for email campaign
     */
    public function uploadAttachment(): void {
        $file = Request::file('file');
        $campaignId = Request::query('campaign_id');
        
        if (!$file) {
            Response::error('No file uploaded', 400);
        }
        
        $result = BatchEmailService::uploadAttachment($file, Auth::id(), $campaignId ? (int) $campaignId : null);
        
        if (!$result['success']) {
            Response::error($result['error'], 400);
        }
        
        Response::created($result);
    }
    
    /**
     * Delete an attachment
     */
    public function deleteAttachment(array $params): void {
        $attachment = table('email_attachments')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$attachment) {
            Response::error('Attachment not found', 404);
        }
        
        // Delete file
        if (file_exists($attachment['file_path'])) {
            unlink($attachment['file_path']);
        }
        
        table('email_attachments')->where('id', $params['id'])->delete();
        
        Response::noContent();
    }
    
    /**
     * Check email sending limits
     */
    public function emailLimits(): void {
        $batchService = new BatchEmailService(Auth::id());
        $limits = $batchService->checkSendingLimits(0);
        
        Response::success(['limits' => $limits]);
    }
    
    public function cancel(array $params): void {
        $campaign = table('campaigns')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        if (!in_array($campaign['status'], ['Draft', 'Scheduled'])) {
            Response::error('Campaign cannot be cancelled', 400);
        }
        
        // Release reserved funds
        $wallet = table('wallets')->where('user_id', Auth::id())->first();
        if ($wallet && (float) $campaign['estimated_cost'] > 0) {
            $newReserved = max(0, (float) $wallet['reserved'] - (float) $campaign['estimated_cost']);
            table('wallets')->where('id', $wallet['id'])->update([
                'reserved' => $newReserved,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        table('campaigns')->where('id', $campaign['id'])->update([
            'status' => 'Cancelled',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success(['message' => 'Campaign cancelled']);
    }
    
    /**
     * Duplicate a campaign
     */
    public function duplicate(array $params): void {
        $original = table('campaigns')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$original) {
            Response::error('Campaign not found', 404);
        }
        
        // Create copy
        $newCampaignId = table('campaigns')->insert([
            'user_id' => Auth::id(),
            'type' => $original['type'],
            'name' => $original['name'] . ' (Copy)',
            'message' => $original['message'],
            'subject' => $original['subject'],
            'sender_id' => $original['sender_id'],
            'template_id' => $original['template_id'],
            'status' => 'Draft',
            'total_recipients' => 0,
            'estimated_cost' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $campaign = table('campaigns')->where('id', $newCampaignId)->first();
        $this->addMessageCounts($campaign);
        
        Response::created(['campaign' => $campaign, 'message' => 'Campaign duplicated']);
    }
    
    /**
     * Delete a campaign
     */
    public function destroy(array $params): void {
        $campaign = table('campaigns')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        if (!in_array($campaign['status'], ['Draft', 'Cancelled'])) {
            Response::error('Only draft or cancelled campaigns can be deleted', 400);
        }
        
        // Release reserved funds
        $wallet = table('wallets')->where('user_id', Auth::id())->first();
        if ($wallet && (float) $campaign['estimated_cost'] > 0) {
            $newReserved = max(0, (float) $wallet['reserved'] - (float) $campaign['estimated_cost']);
            table('wallets')->where('id', $wallet['id'])->update([
                'reserved' => $newReserved,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        // Log before deletion
        AuditLogService::log('campaign_deleted', 'campaign', (int) $params['id'], [
            'name' => $campaign['name'],
            'type' => $campaign['type'],
            'status' => $campaign['status'],
        ], null);
        
        // Delete messages first
        table('messages')->where('campaign_id', $campaign['id'])->delete();
        
        // Delete campaign
        table('campaigns')->where('id', $campaign['id'])->delete();
        
        Response::noContent();
    }
    
    /**
     * Export campaign messages as CSV
     */
    public function exportMessages(array $params): void {
        $campaign = table('campaigns')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$campaign) {
            Response::error('Campaign not found', 404);
        }
        
        $messages = table('messages')
            ->where('campaign_id', $campaign['id'])
            ->orderBy('id', 'ASC')
            ->get();
        
        // Generate CSV
        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['Recipient', 'Status', 'Sent At', 'Delivered At', 'Failed At', 'Error', 'Cost', 'Parts']);
        
        foreach ($messages as $msg) {
            fputcsv($output, [
                $msg['recipient'],
                $msg['status'],
                $msg['sent_at'] ?? '',
                $msg['delivered_at'] ?? '',
                $msg['failed_at'] ?? '',
                $msg['error_message'] ?? '',
                $msg['cost'],
                $msg['parts'],
            ]);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="campaign_' . $campaign['id'] . '_messages.csv"');
        echo $csv;
        exit;
    }
    
    /**
     * Check credits before campaign creation
     */
    public function checkCredits(): void {
        $data = Request::input();
        $recipientCount = (int) ($data['recipient_count'] ?? 0);
        $type = $data['type'] ?? 'sms';
        
        $pricePerUnit = $type === 'sms' 
            ? (float) env('SMS_PRICE_PER_CREDIT', 0.38)
            : (float) env('EMAIL_PRICE_PER_CREDIT', 0.05);
        
        $estimatedCost = $recipientCount * $pricePerUnit;
        
        $wallet = table('wallets')->where('user_id', Auth::id())->first();
        $availableBalance = $wallet ? (float) $wallet['balance'] - (float) $wallet['reserved'] : 0;
        
        Response::success([
            'estimated_cost' => $estimatedCost,
            'available_balance' => $availableBalance,
            'sufficient_credits' => $availableBalance >= $estimatedCost,
            'price_per_unit' => $pricePerUnit,
        ]);
    }
    
    private function addMessageCounts(array &$campaign): void {
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
        
        $campaign['pending_count'] = table('messages')
            ->where('campaign_id', $campaign['id'])
            ->where('status', 'Pending')
            ->count();
        
        $campaign['opted_out_count'] = table('messages')
            ->where('campaign_id', $campaign['id'])
            ->where('status', 'Opted-Out')
            ->count();
    }
}
