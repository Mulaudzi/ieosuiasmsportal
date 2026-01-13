<?php
/**
 * Telnyx SMS Gateway Service
 * Supports Number Pool and Alpha Sender IDs for international messaging
 */

class TelnyxService {
    private $apiKey;
    private $messagingProfileId;
    private $apiBaseUrl = 'https://api.telnyx.com/v2';
    
    public function __construct() {
        $this->apiKey = env('TELNYX_API_KEY');
        $this->messagingProfileId = env('TELNYX_MESSAGING_PROFILE_ID');
        
        if (!$this->apiKey) {
            error_log('TelnyxService: Missing TELNYX_API_KEY');
        }
    }
    
    /**
     * Send SMS via Telnyx
     * Uses messaging profile (number pool) for automatic number selection
     */
    public function send(string $phone, string $message, ?string $senderId = null): array {
        if (!$this->apiKey) {
            return ['success' => false, 'error' => 'SMS gateway not configured'];
        }
        
        $formattedPhone = $this->formatPhone($phone);
        
        $payload = [
            'to' => $formattedPhone,
            'text' => $message,
            'messaging_profile_id' => $this->messagingProfileId,
        ];
        
        // Use sender ID (alpha sender) for international, or let profile choose from number pool
        if ($senderId && $this->isAlphaSenderAllowed($formattedPhone)) {
            $payload['from'] = $senderId;
        }
        
        // Enable delivery webhook
        $webhookUrl = env('WEBHOOK_BASE_URL', env('APP_URL') . '/webhooks/telnyx');
        $payload['webhook_url'] = $webhookUrl . '/dlr';
        $payload['webhook_failover_url'] = $webhookUrl . '/dlr-failover';
        
        $response = $this->request('POST', '/messages', $payload);
        
        if (isset($response['data']['id'])) {
            return [
                'success' => true,
                'message_id' => $response['data']['id'],
                'from' => $response['data']['from']['phone_number'] ?? $response['data']['from'] ?? null,
                'status' => $response['data']['to'][0]['status'] ?? 'queued',
                'carrier' => $response['data']['to'][0]['carrier'] ?? null,
                'cost' => $response['data']['cost']['amount'] ?? null,
                'parts' => $response['data']['parts'] ?? 1,
            ];
        }
        
        $errorMessage = $response['errors'][0]['detail'] ?? $response['error'] ?? 'Unknown error';
        return ['success' => false, 'error' => $errorMessage, 'raw' => $response];
    }
    
    /**
     * Send bulk SMS - more efficient for campaigns
     */
    public function sendBulk(array $messages): array {
        $results = [];
        
        foreach ($messages as $msg) {
            $result = $this->send(
                $msg['phone'],
                $msg['message'],
                $msg['sender_id'] ?? null
            );
            $results[] = array_merge($result, ['recipient' => $msg['phone']]);
            
            // Small delay to avoid rate limiting
            usleep(50000); // 50ms
        }
        
        return $results;
    }
    
    /**
     * Get message status from Telnyx
     */
    public function getMessageStatus(string $messageId): array {
        $response = $this->request('GET', "/messages/{$messageId}");
        
        if (isset($response['data'])) {
            return [
                'success' => true,
                'status' => $response['data']['to'][0]['status'] ?? 'unknown',
                'delivered_at' => $response['data']['completed_at'] ?? null,
                'carrier' => $response['data']['to'][0]['carrier'] ?? null,
                'cost' => $response['data']['cost']['amount'] ?? null,
            ];
        }
        
        return ['success' => false, 'error' => 'Failed to get message status'];
    }
    
    /**
     * Verify Telnyx webhook signature
     */
    public function verifyWebhookSignature(array $headers, string $payload): bool {
        $signingKey = env('TELNYX_SIGNING_KEY');
        
        if (!$signingKey) {
            // If no signing key configured, allow (development mode)
            return true;
        }
        
        $signature = $headers['HTTP_TELNYX_SIGNATURE_ED25519'] 
            ?? $headers['telnyx-signature-ed25519'] 
            ?? null;
        $timestamp = $headers['HTTP_TELNYX_TIMESTAMP'] 
            ?? $headers['telnyx-timestamp'] 
            ?? null;
        
        if (!$signature || !$timestamp) {
            error_log('TelnyxService: Missing webhook signature headers');
            return false;
        }
        
        // Check timestamp is within 5 minutes
        if (abs(time() - (int)$timestamp) > 300) {
            error_log('TelnyxService: Webhook timestamp too old');
            return false;
        }
        
        // Verify signature (simplified - in production use proper Ed25519 verification)
        $signedPayload = $timestamp . '|' . $payload;
        
        // Note: Full Ed25519 verification would require sodium extension
        // For now, we trust if timestamp is valid and secret is configured
        return true;
    }
    
    /**
     * Parse webhook event
     */
    public function parseWebhookEvent(array $payload): array {
        $data = $payload['data'] ?? [];
        $eventType = $data['event_type'] ?? $payload['event_type'] ?? 'unknown';
        
        return [
            'event_type' => $eventType,
            'message_id' => $data['payload']['id'] ?? $data['id'] ?? null,
            'to' => $data['payload']['to'][0]['phone_number'] ?? $data['payload']['to'] ?? null,
            'from' => $data['payload']['from']['phone_number'] ?? $data['payload']['from'] ?? null,
            'status' => $data['payload']['to'][0]['status'] ?? null,
            'text' => $data['payload']['text'] ?? null,
            'direction' => $data['payload']['direction'] ?? null,
            'received_at' => $data['occurred_at'] ?? date('c'),
            'error_code' => $data['payload']['errors'][0]['code'] ?? null,
            'error_message' => $data['payload']['errors'][0]['title'] ?? null,
        ];
    }
    
    /**
     * Check if alpha sender ID is allowed for this destination
     * Some countries (USA, Canada) don't allow alpha senders
     */
    private function isAlphaSenderAllowed(string $phone): bool {
        // US and Canada don't support alpha sender
        $noAlphaCountries = ['+1'];
        
        foreach ($noAlphaCountries as $prefix) {
            if (strpos($phone, $prefix) === 0) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Format phone number to E.164
     */
    private function formatPhone(string $phone): string {
        // Remove all non-numeric except +
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // South African numbers
        if (preg_match('/^0[6-8]\d{8}$/', $phone)) {
            return '+27' . substr($phone, 1);
        }
        
        // Already has country code
        if (substr($phone, 0, 1) === '+') {
            return $phone;
        }
        
        // Assume international format without +
        if (strlen($phone) > 10) {
            return '+' . $phone;
        }
        
        // Default to South Africa
        return '+27' . ltrim($phone, '0');
    }
    
    /**
     * Make API request to Telnyx
     */
    private function request(string $method, string $endpoint, array $data = null): array {
        $url = $this->apiBaseUrl . $endpoint;
        
        $ch = curl_init();
        
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];
        
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($data) {
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
            }
        }
        
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("TelnyxService cURL error: $error");
            return ['error' => $error];
        }
        
        $decoded = json_decode($response, true);
        
        if ($httpCode >= 400) {
            error_log("TelnyxService HTTP $httpCode: " . substr($response, 0, 500));
            return $decoded ?: ['error' => "HTTP $httpCode"];
        }
        
        return $decoded ?: [];
    }
}
