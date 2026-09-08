<?php

class PayOSService {
    public function createCheckout(array $payload): array {
        $url = $this->getCreateUrl();
        $publicKey = trim((string) env('PAYOS_PUBLIC_KEY', ''));
        $secret = trim((string) env('PAYOS_API_SECRET', ''));

        if ($url === '' || $publicKey === '' || $secret === '') {
            throw new Exception('PayOS is not configured');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new Exception('Unable to encode PayOS checkout payload');
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $canonical = implode("\n", ['POST', $path, $timestamp, $nonce, $body]);
        $signature = hash_hmac('sha256', $canonical, $secret);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Payos-Key: ' . $publicKey,
                'X-Payos-Timestamp: ' . $timestamp,
                'X-Payos-Nonce: ' . $nonce,
                'X-Payos-Signature: ' . $signature,
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $caBundle = trim((string) env('PAYOS_CA_BUNDLE', ''));
        if ($caBundle !== '') {
            if (!is_file($caBundle) || !is_readable($caBundle)) {
                curl_close($ch);
                throw new RuntimeException('Configured PayOS CA bundle is not readable');
            }
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }

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

        if ($httpCode >= 400 || (array_key_exists('success', $decoded) && !$decoded['success'])) {
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
        $explicit = trim((string) env('PAYOS_CREATE_URL', ''));
        if ($explicit !== '') {
            return $this->validateHttpsUrl($explicit);
        }

        $baseUrl = rtrim(env('PAYOS_BASE_URL', 'https://payos.ieosuia.com'), '/');
        if ($baseUrl === '') {
            return '';
        }

        return $this->validateHttpsUrl($baseUrl . '/api/v1/payments/create');
    }

    private function validateHttpsUrl(string $url): string {
        if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('PayOS checkout URL must be a valid HTTPS URL');
        }
        return $url;
    }
}
