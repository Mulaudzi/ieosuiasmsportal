<?php
/**
 * Google OAuth Controller
 * Handles Google Sign-In authentication flow
 */

require_once __DIR__ . '/../services/GoogleOAuthService.php';

class GoogleAuthController {
    private GoogleOAuthService $googleOAuth;
    
    public function __construct() {
        $this->googleOAuth = new GoogleOAuthService();
    }
    
    /**
     * Get the Google OAuth authorization URL
     */
    public function getAuthUrl(): void {
        if (!$this->googleOAuth->isConfigured()) {
            Response::error('Google OAuth is not configured', 503);
        }
        
        // Generate a random state for CSRF protection
        $state = bin2hex(random_bytes(16));
        
        Response::success([
            'auth_url' => $this->googleOAuth->getAuthUrl($state),
            'state' => $state,
        ]);
    }
    
    /**
     * Handle the OAuth callback - exchange code for tokens and create/login user
     */
    public function callback(): void {
        if (!$this->googleOAuth->isConfigured()) {
            Response::error('Google OAuth is not configured', 503);
        }
        
        $data = Request::all();
        $code = $data['code'] ?? null;
        
        if (!$code) {
            Response::error('Authorization code is required', 400);
        }
        
        // Exchange code for tokens
        $tokens = $this->googleOAuth->exchangeCode($code);
        
        if (!$tokens || !isset($tokens['id_token'])) {
            Response::error('Failed to exchange authorization code', 400);
        }
        
        // Verify and decode the ID token
        $googleUser = $this->googleOAuth->verifyIdToken($tokens['id_token']);
        
        if (!$googleUser) {
            // Fallback to userinfo endpoint
            $googleUser = $this->googleOAuth->getUserInfo($tokens['access_token']);
        }
        
        if (!$googleUser || !isset($googleUser['email'])) {
            Response::error('Failed to get user info from Google', 400);
        }
        
        // Check if user already exists
        $existingUser = table('users')->where('email', $googleUser['email'])->first();
        
        if ($existingUser) {
            // Update Google OAuth fields if needed
            if (empty($existingUser['google_id'])) {
                table('users')->where('id', $existingUser['id'])->update([
                    'google_id' => $googleUser['sub'] ?? $googleUser['id'] ?? null,
                    'avatar_url' => $existingUser['avatar_url'] ?: ($googleUser['picture'] ?? null),
                    'email_verified_at' => $existingUser['email_verified_at'] ?: date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $existingUser = table('users')->where('id', $existingUser['id'])->first();
            }
            
            $token = Auth::generateToken($existingUser);
            
            Response::success([
                'user' => Auth::formatUserForFrontend($existingUser),
                'token' => $token,
                'is_new_user' => false,
                'message' => 'Welcome back!',
            ]);
            return;
        }
        
        // Create new user
        $name = $googleUser['name'] ?? $googleUser['given_name'] ?? explode('@', $googleUser['email'])[0];
        
        $userId = table('users')->insert([
            'name' => $name,
            'email' => $googleUser['email'],
            'password' => Auth::hashPassword(bin2hex(random_bytes(16))), // Random password for OAuth users
            'google_id' => $googleUser['sub'] ?? $googleUser['id'] ?? null,
            'avatar_url' => $googleUser['picture'] ?? null,
            'account_type' => 'standard',
            'email_verified_at' => date('Y-m-d H:i:s'), // Auto-verify for Google users
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Create wallet for new user
        table('wallets')->insert([
            'user_id' => $userId,
            'balance' => 0,
            'reserved' => 0,
            'currency' => 'ZAR',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $newUser = table('users')->where('id', $userId)->first();
        $token = Auth::generateToken($newUser);
        
        Response::success([
            'user' => Auth::formatUserForFrontend($newUser),
            'token' => $token,
            'is_new_user' => true,
            'message' => 'Account created successfully!',
        ]);
    }
    
    /**
     * Handle Google One-Tap / Sign-In with credential (ID token from frontend)
     */
    public function signInWithCredential(): void {
        if (!$this->googleOAuth->isConfigured()) {
            Response::error('Google OAuth is not configured', 503);
        }
        
        $data = Request::all();
        $credential = $data['credential'] ?? null;
        
        if (!$credential) {
            Response::error('Google credential is required', 400);
        }
        
        // Verify the ID token
        $googleUser = $this->googleOAuth->verifyIdToken($credential);
        
        if (!$googleUser || !isset($googleUser['email'])) {
            Response::error('Invalid Google credential', 400);
        }
        
        // Check if user already exists
        $existingUser = table('users')->where('email', $googleUser['email'])->first();
        
        if ($existingUser) {
            // Update Google OAuth fields if needed
            if (empty($existingUser['google_id'])) {
                table('users')->where('id', $existingUser['id'])->update([
                    'google_id' => $googleUser['sub'] ?? null,
                    'avatar_url' => $existingUser['avatar_url'] ?: ($googleUser['picture'] ?? null),
                    'email_verified_at' => $existingUser['email_verified_at'] ?: date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $existingUser = table('users')->where('id', $existingUser['id'])->first();
            }
            
            $token = Auth::generateToken($existingUser);
            
            Response::success([
                'user' => Auth::formatUserForFrontend($existingUser),
                'token' => $token,
                'is_new_user' => false,
                'message' => 'Welcome back!',
            ]);
            return;
        }
        
        // Create new user
        $name = $googleUser['name'] ?? explode('@', $googleUser['email'])[0];
        
        $userId = table('users')->insert([
            'name' => $name,
            'email' => $googleUser['email'],
            'password' => Auth::hashPassword(bin2hex(random_bytes(16))),
            'google_id' => $googleUser['sub'] ?? null,
            'avatar_url' => $googleUser['picture'] ?? null,
            'account_type' => 'standard',
            'email_verified_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Create wallet
        table('wallets')->insert([
            'user_id' => $userId,
            'balance' => 0,
            'reserved' => 0,
            'currency' => 'ZAR',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $newUser = table('users')->where('id', $userId)->first();
        $token = Auth::generateToken($newUser);
        
        Response::success([
            'user' => Auth::formatUserForFrontend($newUser),
            'token' => $token,
            'is_new_user' => true,
            'message' => 'Account created successfully!',
        ]);
    }
    
    /**
     * Check if Google OAuth is available
     */
    public function status(): void {
        Response::success([
            'available' => $this->googleOAuth->isConfigured(),
            'message' => $this->googleOAuth->isConfigured() 
                ? 'Google OAuth is available' 
                : 'Google OAuth is not configured. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to enable.',
        ]);
    }
}
