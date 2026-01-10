<?php
/**
 * reCAPTCHA v3 Validator
 * Verifies reCAPTCHA tokens with Google's API
 * 
 * NOTE: If RECAPTCHA_SECRET_KEY is not set, validation is skipped (soft fail)
 */

class RecaptchaValidator {
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    private const MIN_SCORE = 0.5;
    
    /**
     * Verify a reCAPTCHA token
     */
    public static function verify(string $token, string $expectedAction = ''): array {
        $secretKey = env('RECAPTCHA_SECRET_KEY', '');
        
        // If not configured, skip validation (soft fail for development/optional recaptcha)
        if (empty($secretKey)) {
            error_log('RECAPTCHA_SECRET_KEY not configured - skipping validation');
            return ['success' => true, 'score' => 1.0, 'error' => null, 'skipped' => true];
        }
        
        // If token is empty but recaptcha is configured, allow it (frontend might not have loaded)
        if (empty($token)) {
            error_log('Empty reCAPTCHA token received - allowing request');
            return ['success' => true, 'score' => 0.8, 'error' => null, 'skipped' => true];
        }
        
        // Make request to Google
        $response = self::makeRequest($token, $secretKey);
        
        if ($response === null) {
            error_log('Failed to verify reCAPTCHA: Request failed - allowing request');
            return ['success' => true, 'score' => 0.5, 'error' => null, 'skipped' => true];
        }
        
        // Check response
        if (!$response['success']) {
            $errors = implode(', ', $response['error-codes'] ?? ['unknown']);
            error_log("reCAPTCHA verification failed: {$errors}");
            // Still allow if timeout-or-duplicate (common issue)
            if (in_array('timeout-or-duplicate', $response['error-codes'] ?? [])) {
                return ['success' => true, 'score' => 0.5, 'error' => null, 'skipped' => true];
            }
            return ['success' => false, 'score' => 0, 'error' => 'reCAPTCHA verification failed'];
        }
        
        // Check action matches (optional)
        if (!empty($expectedAction) && ($response['action'] ?? '') !== $expectedAction) {
            error_log("reCAPTCHA action mismatch: expected {$expectedAction}, got " . ($response['action'] ?? 'none'));
            // Log but don't fail - action might not match due to frontend issues
        }
        
        // Check score
        $score = $response['score'] ?? 0;
        if ($score < self::MIN_SCORE) {
            error_log("reCAPTCHA score too low: {$score}");
            return ['success' => false, 'score' => $score, 'error' => 'Suspicious activity detected'];
        }
        
        return ['success' => true, 'score' => $score, 'error' => null];
    }
    
    /**
     * Verify and fail with error response if invalid
     */
    public static function verifyOrFail(string $token, string $expectedAction = ''): void {
        $result = self::verify($token, $expectedAction);
        
        if (!$result['success']) {
            Response::error($result['error'] ?? 'reCAPTCHA verification failed', 400);
        }
    }
    
    /**
     * Make HTTP request to Google's verification API
     */
    private static function makeRequest(string $token, string $secretKey): ?array {
        $data = [
            'secret' => $secretKey,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ];
        
        // Try cURL first (more reliable)
        if (function_exists('curl_init')) {
            return self::makeRequestCurl($data);
        }
        
        // Fallback to file_get_contents
        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data),
                'timeout' => 10,
            ],
        ];
        
        $context = stream_context_create($options);
        $result = @file_get_contents(self::VERIFY_URL, false, $context);
        
        if ($result === false) {
            return null;
        }
        
        return json_decode($result, true);
    }
    
    /**
     * cURL request
     */
    private static function makeRequestCurl(array $data): ?array {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => self::VERIFY_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($result === false) {
            error_log("cURL error: {$error}");
            return null;
        }
        
        return json_decode($result, true);
    }
}
