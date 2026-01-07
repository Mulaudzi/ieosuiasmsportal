<?php
/**
 * SMS Gateway Service - LogicSMS Integration
 */

class SmsService {
    private $apiUrl;
    private $username;
    private $password;
    
    public function __construct() {
        $this->apiUrl = env('LOGICSMS_API_URL', 'https://www.logicsms.co.za/postmsg2.aspx');
        $this->username = env('LOGICSMS_USERNAME');
        $this->password = env('LOGICSMS_PASSWORD');
    }
    
    public function send(string $phone, string $message, string $senderId = null): array {
        $senderId = $senderId ?? env('LOGICSMS_DEFAULT_SENDER', 'IEOSUIA');
        
        $params = [
            'username' => $this->username,
            'password' => $this->password,
            'mobile' => $this->formatPhone($phone),
            'message' => $message,
            'Originator' => $senderId,
            'Unique' => uniqid('sms_'),
            'DCheck' => '1',
        ];
        
        $url = $this->apiUrl . '?' . http_build_query($params);
        
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
            return ['success' => false, 'error' => $error];
        }
        
        // Parse XML response
        $xml = @simplexml_load_string($response);
        if ($xml && isset($xml->Id)) {
            return [
                'success' => true,
                'message_id' => (string) $xml->Id,
                'status' => (string) ($xml->Status ?? 'Sent'),
            ];
        }
        
        return ['success' => false, 'error' => 'Invalid gateway response', 'raw' => $response];
    }
    
    private function formatPhone(string $phone): string {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (substr($phone, 0, 1) === '0') {
            $phone = '27' . substr($phone, 1);
        }
        return $phone;
    }
}
