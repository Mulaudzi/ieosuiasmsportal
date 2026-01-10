<?php
/**
 * Google OAuth Service
 * Handles Google Sign-In authentication
 */

class GoogleOAuthService {
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    
    public function __construct() {
        $this->clientId = env('GOOGLE_CLIENT_ID', '');
        $this->clientSecret = env('GOOGLE_CLIENT_SECRET', '');
        $this->redirectUri = env('FRONTEND_URL', 'https://sms.ieosuia.com') . '/auth/google/callback';
    }
    
    /**
     * Get the Google OAuth authorization URL
     */
    public function getAuthUrl(string $state = ''): string {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];
        
        if ($state) {
            $params['state'] = $state;
        }
        
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
    
    /**
     * Exchange authorization code for tokens
     */
    public function exchangeCode(string $code): ?array {
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        
        $postData = [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $tokenUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log('Google token exchange failed: ' . $response);
            return null;
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Verify and decode an ID token
     */
    public function verifyIdToken(string $idToken): ?array {
        // Use Google's tokeninfo endpoint for verification
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log('Google token verification failed: ' . $response);
            return null;
        }
        
        $payload = json_decode($response, true);
        
        // Verify the token is for our app
        if (($payload['aud'] ?? '') !== $this->clientId) {
            error_log('Google token audience mismatch');
            return null;
        }
        
        return $payload;
    }
    
    /**
     * Get user info using access token
     */
    public function getUserInfo(string $accessToken): ?array {
        $url = 'https://www.googleapis.com/oauth2/v2/userinfo';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log('Google userinfo fetch failed: ' . $response);
            return null;
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Check if Google OAuth is configured
     */
    public function isConfigured(): bool {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }
}
