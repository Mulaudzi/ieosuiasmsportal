<?php

class PayOSService {
    public function createCheckout(array $payload): array {
        $url = $this->getCreateUrl();
        $publicKey = env('PAYOS_PUBLIC_KEY', '');
        $secret = env('PAYOS_SECRET', '');

        if ($url === '' || $publicKey === '' || $secret === '') {
            throw new Exception('PayOS is not configured');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new Exception('Unable to encode PayOS checkout payload');
        }

        $signature = hash_hmac('sha256', $body, $secret);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Payos-Key: ' . $publicKey,
                'X-Payos-Signature: ' . $signature,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('PayOS request failed: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new Exception('Invalid PayOS response');
        }

        if ($httpCode >= 400 || empty($decoded['success'])) {
            $message = $decoded['message'] ?? $decoded['error'] ?? 'PayOS checkout creation failed';
            throw new Exception($message);
        }

        return $decoded;
    }

    public function verifyCallbackSignature(string $rawBody, string $signature): bool {
        $secret = env('PAYOS_CALLBACK_SECRET', '');
        if ($secret === '' || $signature === '') {
            return false;
        }

        $calculated = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($calculated, $signature);
    }

    public function getCheckoutUrl(array $response): ?string {
        $candidates = [
            $response['data']['checkout_url'] ?? null,
            $response['data']['payment_url'] ?? null,
            $response['checkout_url'] ?? null,
            $response['payment_url'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    public function getInternalReference(array $payload): ?string {
        $candidates = [
            $payload['internal_reference'] ?? null,
            $payload['reference'] ?? null,
            $payload['data']['internal_reference'] ?? null,
            $payload['data']['reference'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    public function mapStatus(?string $status): string {
        $normalized = strtolower((string) $status);

        return match ($normalized) {
            'paid', 'complete', 'completed', 'success', 'successful' => 'completed',
            'pending', 'processing', 'initiated' => 'pending',
            'cancelled', 'canceled' => 'cancelled',
            'refunded' => 'refunded',
            default => 'failed',
        };
    }

    private function getCreateUrl(): string {
        $explicit = env('PAYOS_CREATE_URL', '');
        if ($explicit !== '') {
            return $explicit;
        }

        $baseUrl = rtrim(env('PAYOS_BASE_URL', 'https://payos.ieosuia.com'), '/');
        if ($baseUrl === '') {
            return '';
        }

        return $baseUrl . '/api/v1/payments/create';
    }
}