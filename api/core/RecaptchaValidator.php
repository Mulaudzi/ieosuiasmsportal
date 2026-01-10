<?php
/**
 * reCAPTCHA v3 Validator
 * Verifies reCAPTCHA tokens with Google's API
 */

class RecaptchaValidator {
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    private const MIN_SCORE = 0.5; // Minimum score to pass (0.0 - 1.0)
    
    /**
     * Verify a reCAPTCHA token
     * @param string $token The token from the frontend
     * @param string $expectedAction The expected action name
     * @return array ['success' => bool, 'score' => float, 'error' => ?string]
     */
    public static function verify(string $token, string $expectedAction = ''): array {
        $secretKey = $_ENV['RECAPTCHA_SECRET_KEY'] ?? getenv('RECAPTCHA_SECRET_KEY');
        
        if (empty($secretKey)) {
            error_log('RECAPTCHA_SECRET_KEY not configured');
            // Fail open in development, fail closed in production
            if (self::isDevelopment()) {
                return ['success' => true, 'score' => 1.0, 'error' => null, 'skipped' => true];
            }
            return ['success' => false, 'score' => 0, 'error' => 'reCAPTCHA not configured'];
        }
        
        if (empty($token)) {
            return ['success' => false, 'score' => 0, 'error' => 'reCAPTCHA token is required'];
        }
        
        // Make request to Google
        $response = self::makeRequest($token, $secretKey);
        
        if ($response === null) {
            error_log('Failed to verify reCAPTCHA: Request failed');
            return ['success' => false, 'score' => 0, 'error' => 'Failed to verify reCAPTCHA'];
        }
        
        // Check response
        if (!$response['success']) {
            $errors = implode(', ', $response['error-codes'] ?? ['unknown']);
            error_log("reCAPTCHA verification failed: {$errors}");
            return ['success' => false, 'score' => 0, 'error' => 'reCAPTCHA verification failed'];
        }
        
        // Check action matches (optional but recommended)
        if (!empty($expectedAction) && ($response['action'] ?? '') !== $expectedAction) {
            error_log("reCAPTCHA action mismatch: expected {$expectedAction}, got {$response['action']}");
            return ['success' => false, 'score' => 0, 'error' => 'reCAPTCHA action mismatch'];
        }
        
        // Check score
        $score = $response['score'] ?? 0;
        if ($score < self::MIN_SCORE) {
            error_log("reCAPTCHA score too low: {$score}");
            return ['success' => false, 'score' => $score, 'error' => 'Suspicious activity detected. Please try again.'];
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
            // Fallback to cURL if file_get_contents fails
            if (function_exists('curl_init')) {
                return self::makeRequestCurl($token, $secretKey);
            }
            return null;
        }
        
        return json_decode($result, true);
    }
    
    /**
     * cURL fallback for making requests
     */
    private static function makeRequestCurl(string $token, string $secretKey): ?array {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => self::VERIFY_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => $secretKey,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]),
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
    
    /**
     * Check if running in development mode
     */
    private static function isDevelopment(): bool {
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production';
        return in_array($env, ['development', 'dev', 'local'], true);
    }
}
