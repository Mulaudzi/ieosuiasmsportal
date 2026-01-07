<?php
/**
 * Campaign Controller
 */

require_once __DIR__ . '/../services/SmsService.php';
require_once __DIR__ . '/../services/EmailService.php';

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
        
        table('campaigns')->where('id', $campaign['id'])->update([
            'status' => 'Sending',
            'started_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $emailService = new EmailService();
        $messages = table('messages')
            ->where('campaign_id', $campaign['id'])
            ->where('status', 'Pending')
            ->get();
        
        $userId = Auth::id();
        $wallet = table('wallets')->where('user_id', $userId)->first();
        $totalCost = 0;
        
        foreach ($messages as $message) {
            $result = $emailService->send(
                $message['recipient'],
                $message['subject'],
                $message['content']
            );
            
            if ($result['success']) {
                table('messages')->where('id', $message['id'])->update([
                    'status' => 'Delivered',
                    'external_id' => $result['message_id'] ?? null,
                    'sent_at' => date('Y-m-d H:i:s'),
                    'delivered_at' => date('Y-m-d H:i:s'),
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
                'description' => "Email Campaign: {$campaign['name']}",
                'reference' => "CAMP-{$campaign['id']}",
                'status' => 'completed',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        table('campaigns')->where('id', $campaign['id'])->update([
            'status' => 'Sent',
            'completed_at' => date('Y-m-d H:i:s'),
            'actual_cost' => $totalCost,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $campaign = table('campaigns')->where('id', $campaign['id'])->first();
        $this->addMessageCounts($campaign);
        
        Response::success(['campaign' => $campaign, 'message' => 'Campaign sent successfully']);
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
    }
}
