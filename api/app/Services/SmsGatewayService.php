<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsGatewayService
{
    private string $apiUrl;
    private string $username;
    private string $password;
    private string $defaultSender;

    public function __construct()
    {
        $this->apiUrl = config('sms.api_url', 'https://www.aborinteractive.co.za/AboriGateway/rest/sms');
        $this->username = config('sms.username', '');
        $this->password = config('sms.password', '');
        $this->defaultSender = config('sms.default_sender', 'IEOSUIA');
    }

    /**
     * Send a single SMS
     */
    public function send(string $recipient, string $message, ?string $senderId = null): array
    {
        $senderId = $senderId ?? $this->defaultSender;

        // Normalize phone number
        $recipient = $this->normalizePhoneNumber($recipient);

        if (!$this->isValidPhoneNumber($recipient)) {
            return [
                'success' => false,
                'error' => 'Invalid phone number format',
            ];
        }

        try {
            $response = Http::timeout(30)
                ->withBasicAuth($this->username, $this->password)
                ->post($this->apiUrl, [
                    'to' => $recipient,
                    'message' => $message,
                    'from' => $senderId,
                    'dlr' => 1, // Request delivery report
                    'dlr_url' => config('app.url') . '/api/dlr/webhook',
                ]);

            Log::info("SMS Gateway Response", [
                'recipient' => $recipient,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Parse response based on gateway format
                // LogicSMS/Abori typically returns XML or JSON with message ID
                if (isset($data['messageId']) || isset($data['id'])) {
                    return [
                        'success' => true,
                        'messageId' => $data['messageId'] ?? $data['id'],
                        'status' => 'accepted',
                        'credits' => $data['credits'] ?? 1,
                    ];
                }

                // Handle XML response
                if (str_contains($response->body(), '<')) {
                    return $this->parseXmlResponse($response->body());
                }

                // Assume success if 200 status
                return [
                    'success' => true,
                    'messageId' => uniqid('sms_'),
                    'status' => 'accepted',
                ];
            }

            return [
                'success' => false,
                'error' => "Gateway error: {$response->status()} - {$response->body()}",
            ];

        } catch (\Exception $e) {
            Log::error("SMS Gateway Exception", [
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send bulk SMS (batch)
     */
    public function sendBulk(array $messages, ?string $senderId = null): array
    {
        $results = [];

        foreach ($messages as $msg) {
            $recipient = $msg['recipient'] ?? $msg['to'];
            $content = $msg['message'] ?? $msg['content'];
            
            $results[] = [
                'recipient' => $recipient,
                'result' => $this->send($recipient, $content, $senderId),
            ];

            // Small delay to avoid rate limiting
            usleep(100000); // 100ms
        }

        return $results;
    }

    /**
     * Check account balance
     */
    public function getBalance(): array
    {
        try {
            $response = Http::timeout(15)
                ->withBasicAuth($this->username, $this->password)
                ->get(str_replace('/sms', '/balance', $this->apiUrl));

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'balance' => $data['balance'] ?? $data['credits'] ?? 0,
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to fetch balance',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Normalize phone number to international format
     */
    private function normalizePhoneNumber(string $phone): string
    {
        // Remove all non-digit characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // South African numbers
        if (strlen($phone) === 9 && !str_starts_with($phone, '0')) {
            $phone = '27' . $phone;
        } elseif (strlen($phone) === 10 && str_starts_with($phone, '0')) {
            $phone = '27' . substr($phone, 1);
        } elseif (!str_starts_with($phone, '27') && strlen($phone) < 12) {
            $phone = '27' . $phone;
        }

        return $phone;
    }

    /**
     * Validate phone number
     */
    private function isValidPhoneNumber(string $phone): bool
    {
        // Must be 11-15 digits for international format
        return preg_match('/^[0-9]{11,15}$/', $phone) === 1;
    }

    /**
     * Parse XML response from gateway
     */
    private function parseXmlResponse(string $xml): array
    {
        try {
            $doc = new \SimpleXMLElement($xml);
            
            // Common XML response formats
            if (isset($doc->messageId)) {
                return [
                    'success' => true,
                    'messageId' => (string) $doc->messageId,
                    'status' => 'accepted',
                ];
            }

            if (isset($doc->result) && strtolower((string) $doc->result) === 'success') {
                return [
                    'success' => true,
                    'messageId' => (string) ($doc->id ?? uniqid('sms_')),
                    'status' => 'accepted',
                ];
            }

            if (isset($doc->error)) {
                return [
                    'success' => false,
                    'error' => (string) $doc->error,
                ];
            }

            // Assume success if no error
            return [
                'success' => true,
                'messageId' => uniqid('sms_'),
                'status' => 'accepted',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to parse gateway response',
            ];
        }
    }

    /**
     * Calculate message parts
     */
    public static function calculateParts(string $message): int
    {
        $length = mb_strlen($message);
        
        // Check for GSM-7 vs Unicode
        $isGsm7 = preg_match('/^[\x20-\x7E\n\r]+$/', $message);
        
        if ($isGsm7) {
            // GSM-7: 160 chars for single, 153 for multipart
            return $length <= 160 ? 1 : (int) ceil($length / 153);
        } else {
            // Unicode: 70 chars for single, 67 for multipart
            return $length <= 70 ? 1 : (int) ceil($length / 67);
        }
    }
}
