<?php
/**
 * Simple JWT Implementation
 */

class JWT {
    private static function getSecret(): string {
        return env('JWT_SECRET', env('APP_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VyX2lkIjoxLCJleHAiOjE3NjgwMTE4Mzh9.k_Q1cCZEleniwUFFB2hk6AOrjIi0g-pqHWQS8CWENws'));
    }
    
    public static function encode(array $payload): string {
        $header = self::base64UrlEncode(json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256'
        ]));
        
        // Add expiration if not set (default 24 hours)
        if (!isset($payload['exp'])) {
            $payload['exp'] = time() + (24 * 60 * 60);
        }
        
        // Add issued at
        $payload['iat'] = time();
        
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        
        $signature = self::sign("$header.$payloadEncoded");
        
        return "$header.$payloadEncoded.$signature";
    }
    
    public static function decode(string $token): ?array {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return null;
        }
        
        list($header, $payload, $signature) = $parts;
        
        // Verify signature
        $expectedSignature = self::sign("$header.$payload");
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }
        
        $payloadDecoded = json_decode(self::base64UrlDecode($payload), true);
        
        if (!$payloadDecoded) {
            return null;
        }
        
        // Check expiration
        if (isset($payloadDecoded['exp']) && $payloadDecoded['exp'] < time()) {
            return null;
        }
        
        return $payloadDecoded;
    }
    
    private static function sign(string $data): string {
        $signature = hash_hmac('sha256', $data, self::getSecret(), true);
        return self::base64UrlEncode($signature);
    }
    
    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private static function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
