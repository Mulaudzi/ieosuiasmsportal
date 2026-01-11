<?php
/**
 * SMS Gateway Service - Multi-Gateway Support
 * Supports Telnyx (primary), LogicSMS (fallback)
 */

require_once __DIR__ . '/TelnyxService.php';

class SmsService {
    private $gateway;
    private $telnyxService;
    private $logicSmsUrl;
    private $logicSmsUsername;
    private $logicSmsPassword;
    
    public function __construct() {
        $this->gateway = env('SMS_GATEWAY', 'telnyx');
        $this->telnyxService = new TelnyxService();
        
        // LogicSMS fallback config
        $this->logicSmsUrl = env('LOGICSMS_API_URL', 'https://www.logicsms.co.za/postmsg2.aspx');
        $this->logicSmsUsername = env('LOGICSMS_USERNAME');
        $this->logicSmsPassword = env('LOGICSMS_PASSWORD');
    }
    
    public function send(string $phone, string $message, string $senderId = null): array {
        $senderId = $senderId ?? env('SMS_DEFAULT_SENDER', 'IEOSUIA');
        
        // Try primary gateway (Telnyx)
        if ($this->gateway === 'telnyx' || !$this->logicSmsUsername) {
            $result = $this->sendViaTelnyx($phone, $message, $senderId);
            
            // Fallback to LogicSMS if Telnyx fails and LogicSMS is configured
            if (!$result['success'] && $this->logicSmsUsername) {
                error_log("Telnyx failed, falling back to LogicSMS: " . ($result['error'] ?? 'Unknown'));
                return $this->sendViaLogicSms($phone, $message, $senderId);
            }
            
            return $result;
        }
        
        return $this->sendViaLogicSms($phone, $message, $senderId);
    }
    
    /**
     * Send via Telnyx
     */
    private function sendViaTelnyx(string $phone, string $message, string $senderId): array {
        return $this->telnyxService->send($phone, $message, $senderId);
    }
    
    /**
     * Send via LogicSMS (fallback)
     */
    private function sendViaLogicSms(string $phone, string $message, string $senderId): array {
        $params = [
            'username' => $this->logicSmsUsername,
            'password' => $this->logicSmsPassword,
            'mobile' => $this->formatPhone($phone),
            'message' => $message,
            'Originator' => $senderId,
            'Unique' => uniqid('sms_'),
            'DCheck' => '1',
        ];
        
        $url = $this->logicSmsUrl . '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => $error, 'gateway' => 'logicsms'];
        }
        
        $xml = @simplexml_load_string($response);
        if ($xml && isset($xml->Id)) {
            return [
                'success' => true,
                'message_id' => (string) $xml->Id,
                'status' => (string) ($xml->Status ?? 'Sent'),
                'gateway' => 'logicsms',
            ];
        }
        
        return ['success' => false, 'error' => 'Invalid gateway response', 'raw' => $response, 'gateway' => 'logicsms'];
    }
    
    /**
     * Send bulk SMS (more efficient for campaigns)
     */
    public function sendBulk(array $messages): array {
        if ($this->gateway === 'telnyx') {
            return $this->telnyxService->sendBulk($messages);
        }
        
        // Fallback: send one by one
        $results = [];
        foreach ($messages as $msg) {
            $results[] = $this->send(
                $msg['phone'],
                $msg['message'],
                $msg['sender_id'] ?? null
            );
        }
        return $results;
    }
    
    /**
     * Get message status
     */
    public function getStatus(string $messageId): array {
        if ($this->gateway === 'telnyx') {
            return $this->telnyxService->getMessageStatus($messageId);
        }
        return ['success' => false, 'error' => 'Status check not supported for this gateway'];
    }
    
    /**
     * Get current gateway name
     */
    public function getGateway(): string {
        return $this->gateway;
    }
    
    /**
     * Format phone number to E.164
     */
    private function formatPhone(string $phone): string {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (substr($phone, 0, 1) === '0') {
            $phone = '27' . substr($phone, 1);
        }
        return $phone;
    }
}
