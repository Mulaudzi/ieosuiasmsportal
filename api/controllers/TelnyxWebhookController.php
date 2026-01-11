<?php
/**
 * Telnyx Webhook Controller
 * Handles DLR (Delivery Reports), Inbound SMS, and Failover
 */

require_once __DIR__ . '/../services/TelnyxService.php';

class TelnyxWebhookController {
    private $telnyxService;
    
    // Multi-language keyword mapping for opt-out/opt-in
    private $optOutKeywords = ['STOP', 'STAP', 'CANCEL', 'UNSUBSCRIBE', 'QUIT', 'END', 'ARRET', 'PARAR'];
    private $optInKeywords = ['START', 'BEGIN', 'SUBSCRIBE', 'YES', 'UNSTOP', 'OPTIN', 'CONTINUER', 'INICIAR'];
    private $helpKeywords = ['HELP', 'HULP', 'INFO', 'AIDE', 'AYUDA'];
    
    public function __construct() {
        $this->telnyxService = new TelnyxService();
    }
    
    /**
     * Primary DLR webhook endpoint
     * POST /api/webhooks/telnyx/dlr
     */
    public function dlr(): void {
        $rawPayload = file_get_contents('php://input');
        $payload = json_decode($rawPayload, true);
        
        // Verify webhook signature
        if (!$this->telnyxService->verifyWebhookSignature($_SERVER, $rawPayload)) {
            error_log('Telnyx webhook: Invalid signature');
            Response::error('Unauthorized', 401);
        }
        
        $this->processDeliveryReport($payload);
        
        Response::success(['message' => 'DLR processed']);
    }
    
    /**
     * Failover DLR webhook endpoint
     * POST /api/webhooks/telnyx/dlr-failover
     */
    public function dlrFailover(): void {
        $rawPayload = file_get_contents('php://input');
        $payload = json_decode($rawPayload, true);
        
        if (!$this->telnyxService->verifyWebhookSignature($_SERVER, $rawPayload)) {
            Response::error('Unauthorized', 401);
        }
        
        // Log failover event
        error_log('Telnyx webhook failover triggered: ' . substr($rawPayload, 0, 500));
        
        $this->processDeliveryReport($payload);
        
        Response::success(['message' => 'Failover DLR processed']);
    }
    
    /**
     * Inbound SMS webhook endpoint
     * POST /api/webhooks/telnyx/inbound
     */
    public function inbound(): void {
        $rawPayload = file_get_contents('php://input');
        $payload = json_decode($rawPayload, true);
        
        if (!$this->telnyxService->verifyWebhookSignature($_SERVER, $rawPayload)) {
            Response::error('Unauthorized', 401);
        }
        
        $event = $this->telnyxService->parseWebhookEvent($payload);
        
        if ($event['direction'] !== 'inbound') {
            Response::success(['message' => 'Not an inbound message']);
            return;
        }
        
        $from = $event['from'];
        $text = strtoupper(trim($event['text'] ?? ''));
        $to = $event['to'];
        
        // Log inbound message
        error_log("Telnyx inbound from $from: $text");
        
        // Store inbound message
        $this->storeInboundMessage($event);
        
        // Process keywords
        $this->processKeyword($from, $text, $to);
        
        Response::success(['message' => 'Inbound processed']);
    }
    
    /**
     * Process delivery report
     */
    private function processDeliveryReport(array $payload): void {
        $event = $this->telnyxService->parseWebhookEvent($payload);
        $messageId = $event['message_id'];
        
        if (!$messageId) {
            error_log('Telnyx DLR: Missing message ID');
            return;
        }
        
        // Find message by external_id
        $message = table('messages')->where('external_id', $messageId)->first();
        
        if (!$message) {
            error_log("Telnyx DLR: Message not found for ID: $messageId");
            return;
        }
        
        // Log DLR
        table('dlr_logs')->insert([
            'message_id' => $message['id'],
            'external_id' => $messageId,
            'status' => $event['status'] ?? $event['event_type'],
            'raw_payload' => json_encode($payload),
            'received_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Map Telnyx status to our status
        $mappedStatus = $this->mapStatus($event['status'] ?? $event['event_type']);
        
        $updateData = [
            'status' => $mappedStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        
        if ($mappedStatus === 'Delivered') {
            $updateData['delivered_at'] = date('Y-m-d H:i:s');
        } elseif ($mappedStatus === 'Failed') {
            $updateData['failed_at'] = date('Y-m-d H:i:s');
            $updateData['error_message'] = $event['error_message'] ?? 'Delivery failed';
        }
        
        table('messages')->where('id', $message['id'])->update($updateData);
        
        // Update campaign counts
        $this->updateCampaignCounts($message['campaign_id']);
    }
    
    /**
     * Store inbound message
     */
    private function storeInboundMessage(array $event): void {
        // Store in a dedicated inbound_messages table if needed
        table('audit_logs')->insert([
            'user_id' => null,
            'action' => 'inbound_sms',
            'entity_type' => 'sms',
            'entity_id' => null,
            'old_values' => null,
            'new_values' => json_encode($event),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => 'Telnyx Webhook',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    
    /**
     * Process inbound keyword
     */
    private function processKeyword(string $from, string $keyword, string $to): void {
        // Find which user owns this sender
        // For now, process globally or find by matching recent campaigns
        
        // Check for opt-out
        if (in_array($keyword, $this->optOutKeywords)) {
            $this->handleOptOut($from, $keyword);
            return;
        }
        
        // Check for opt-in
        if (in_array($keyword, $this->optInKeywords)) {
            $this->handleOptIn($from, $keyword);
            return;
        }
        
        // Check for help
        if (in_array($keyword, $this->helpKeywords)) {
            $this->handleHelp($from);
            return;
        }
    }
    
    /**
     * Handle opt-out request
     */
    private function handleOptOut(string $phone, string $keyword): void {
        // Format phone for consistency
        $formattedPhone = $this->formatPhone($phone);
        
        // Find users who have messaged this phone
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.user_id 
            FROM campaigns c 
            JOIN messages m ON c.id = m.campaign_id 
            WHERE m.recipient = ? OR m.recipient = ?
        ");
        $stmt->execute([$phone, $formattedPhone]);
        $users = $stmt->fetchAll();
        
        foreach ($users as $userRow) {
            $userId = $userRow['user_id'];
            
            // Check if already opted out
            $existing = table('opt_outs')
                ->where('user_id', $userId)
                ->where('recipient', $formattedPhone)
                ->first();
            
            if (!$existing) {
                table('opt_outs')->insert([
                    'user_id' => $userId,
                    'recipient' => $formattedPhone,
                    'channel' => 'sms',
                    'reason' => "Keyword: $keyword",
                    'source' => 'sms_keyword',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
        
        // Also update contact subscription status
        $pdo->prepare("
            UPDATE contacts SET subscription_status = 'unsubscribed', updated_at = NOW()
            WHERE phone = ? OR phone = ?
        ")->execute([$phone, $formattedPhone]);
        
        // Send confirmation (optional)
        $this->sendAutoResponse($phone, "You have been unsubscribed. Reply START to resubscribe.");
    }
    
    /**
     * Handle opt-in request
     */
    private function handleOptIn(string $phone, string $keyword): void {
        $formattedPhone = $this->formatPhone($phone);
        
        // Remove from opt-outs
        $pdo = db();
        $pdo->prepare("
            DELETE FROM opt_outs WHERE recipient = ? OR recipient = ?
        ")->execute([$phone, $formattedPhone]);
        
        // Update contact subscription status
        $pdo->prepare("
            UPDATE contacts SET subscription_status = 'subscribed', updated_at = NOW()
            WHERE phone = ? OR phone = ?
        ")->execute([$phone, $formattedPhone]);
        
        // Send confirmation
        $this->sendAutoResponse($phone, "You have been resubscribed to messages.");
    }
    
    /**
     * Handle help request
     */
    private function handleHelp(string $phone): void {
        $helpMessage = "Reply STOP to unsubscribe, START to resubscribe. For assistance, contact support@ieosuia.com";
        $this->sendAutoResponse($phone, $helpMessage);
    }
    
    /**
     * Send auto-response
     */
    private function sendAutoResponse(string $phone, string $message): void {
        $autoResponseEnabled = env('SMS_AUTO_RESPONSE_ENABLED', 'true') === 'true';
        
        if (!$autoResponseEnabled) {
            return;
        }
        
        try {
            $this->telnyxService->send($phone, $message);
        } catch (Exception $e) {
            error_log("Auto-response failed: " . $e->getMessage());
        }
    }
    
    /**
     * Update campaign message counts
     */
    private function updateCampaignCounts(int $campaignId): void {
        $pdo = db();
        
        $stmt = $pdo->prepare("
            UPDATE campaigns SET
                sent_count = (SELECT COUNT(*) FROM messages WHERE campaign_id = ? AND status IN ('Sent', 'Awaiting DLR', 'Delivered')),
                delivered_count = (SELECT COUNT(*) FROM messages WHERE campaign_id = ? AND status = 'Delivered'),
                failed_count = (SELECT COUNT(*) FROM messages WHERE campaign_id = ? AND status = 'Failed'),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$campaignId, $campaignId, $campaignId, $campaignId]);
    }
    
    /**
     * Map Telnyx status to our status
     */
    private function mapStatus(string $status): string {
        $statusMap = [
            // Telnyx statuses
            'queued' => 'Queued',
            'sending' => 'Sending',
            'sent' => 'Sent',
            'delivered' => 'Delivered',
            'delivery_unconfirmed' => 'Awaiting DLR',
            'sending_failed' => 'Failed',
            'delivery_failed' => 'Failed',
            'expired' => 'Failed',
            'carrier_unreachable' => 'Failed',
            'network_failure' => 'Failed',
            
            // Event types
            'message.sent' => 'Sent',
            'message.finalized' => 'Delivered',
            'message.failed' => 'Failed',
        ];
        
        return $statusMap[strtolower($status)] ?? 'Awaiting DLR';
    }
    
    /**
     * Format phone number consistently
     */
    private function formatPhone(string $phone): string {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        if (substr($phone, 0, 1) !== '+') {
            if (substr($phone, 0, 2) === '27') {
                return '+' . $phone;
            }
            if (substr($phone, 0, 1) === '0') {
                return '+27' . substr($phone, 1);
            }
        }
        
        return $phone;
    }
}
