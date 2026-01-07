<?php
/**
 * Authentication Controller
 */

class AuthController {
    private EmailService $emailService;
    
    public function __construct() {
        $this->emailService = new EmailService();
    }
    
    public function register(): void {
        $data = Request::validate([
            'name' => 'required|min:2|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'phone' => 'max:20',
        ]);
        
        // Rate limit registration by IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        RateLimiter::checkOrFail("register:{$ip}", 5, 60);
        
        // Generate verification token
        $verificationToken = bin2hex(random_bytes(32));
        
        $userId = table('users')->insert([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Auth::hashPassword($data['password']),
            'phone' => $data['phone'] ?? null,
            'account_type' => 'standard',
            'email_verification_token' => $verificationToken,
            'email_verification_sent_at' => date('Y-m-d H:i:s'),
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
        
        // Send verification email
        $this->emailService->sendVerificationEmail(
            $data['email'],
            $data['name'],
            $verificationToken
        );
        
        $user = table('users')->where('id', $userId)->first();
        $token = Auth::generateToken($user);
        
        Response::created([
            'user' => self::formatUser($user),
            'token' => $token,
        ]);
    }
    
    public function login(): void {
        $data = Request::validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);
        
        // Rate limit login attempts by email
        RateLimiter::checkOrFail("login:{$data['email']}", 5, 15);
        
        $token = Auth::attempt($data['email'], $data['password']);
        
        if (!$token) {
            Response::error('Invalid credentials', 401);
        }
        
        // Clear rate limit on successful login
        RateLimiter::clear("login:{$data['email']}");
        
        $user = table('users')->where('email', $data['email'])->first();
        
        Response::success([
            'user' => self::formatUser($user),
            'token' => $token,
        ]);
    }
    
    public function logout(): void {
        // With JWT, we just tell the client to discard the token
        Response::success(['message' => 'Logged out successfully']);
    }
    
    public function user(): void {
        $user = Auth::user();
        $wallet = table('wallets')->where('user_id', $user['id'])->first();
        
        Response::success([
            'user' => self::formatUser($user),
            'wallet' => $wallet ? [
                'balance' => (float) $wallet['balance'],
                'reserved' => (float) $wallet['reserved'],
                'currency' => $wallet['currency'],
            ] : null,
        ]);
    }
    
    public function forgotPassword(): void {
        $data = Request::validate([
            'email' => 'required|email',
        ]);
        
        // Rate limit: 3 attempts per email per 15 minutes
        RateLimiter::checkOrFail("forgot_password:{$data['email']}", 3, 15);
        
        // Rate limit: 10 attempts per IP per hour
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        RateLimiter::checkOrFail("forgot_password_ip:{$ip}", 10, 60);
        
        $user = table('users')->where('email', $data['email'])->first();
        
        // Always return success to prevent email enumeration
        if ($user) {
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            
            table('users')->where('id', $user['id'])->update([
                'otp_code' => $otp,
                'otp_expires_at' => date('Y-m-d H:i:s', strtotime('+15 minutes')),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            // Send password reset email
            $this->emailService->sendPasswordResetEmail(
                $user['email'],
                $user['name'],
                $otp
            );
        }
        
        Response::success(['message' => 'If the email exists, a reset code has been sent']);
    }
    
    public function resetPassword(): void {
        $data = Request::validate([
            'email' => 'required|email',
            'otp' => 'required|min:6|max:6',
            'password' => 'required|min:8|confirmed',
        ]);
        
        // Rate limit: 5 attempts per email per 15 minutes
        RateLimiter::checkOrFail("reset_password:{$data['email']}", 5, 15);
        
        $user = table('users')
            ->where('email', $data['email'])
            ->where('otp_code', $data['otp'])
            ->first();
        
        if (!$user) {
            Response::error('Invalid reset code', 400);
        }
        
        if (strtotime($user['otp_expires_at']) < time()) {
            Response::error('Reset code has expired', 400);
        }
        
        table('users')->where('id', $user['id'])->update([
            'password' => Auth::hashPassword($data['password']),
            'otp_code' => null,
            'otp_expires_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Clear rate limits on successful reset
        RateLimiter::clear("reset_password:{$data['email']}");
        RateLimiter::clear("forgot_password:{$data['email']}");
        
        Response::success(['message' => 'Password reset successfully']);
    }
    
    public function verifyEmail(): void {
        $data = Request::validate([
            'token' => 'required',
        ]);
        
        // Rate limit verification attempts by IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        RateLimiter::checkOrFail("verify_email:{$ip}", 10, 15);
        
        $user = table('users')
            ->where('email_verification_token', $data['token'])
            ->first();
        
        if (!$user) {
            Response::error('Invalid verification token', 400);
        }
        
        if ($user['email_verified_at']) {
            Response::success(['message' => 'Email already verified']);
        }
        
        // Check if token is expired (24 hours)
        if (isset($user['email_verification_sent_at'])) {
            $sentAt = strtotime($user['email_verification_sent_at']);
            if (time() - $sentAt > 86400) {
                Response::error('Verification token has expired', 400);
            }
        }
        
        table('users')->where('id', $user['id'])->update([
            'email_verified_at' => date('Y-m-d H:i:s'),
            'email_verification_token' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success(['message' => 'Email verified successfully']);
    }
    
    public function resendVerification(): void {
        $user = Auth::user();
        
        // Rate limit: 3 resends per 15 minutes
        RateLimiter::checkOrFail("resend_verification:{$user['id']}", 3, 15);
        
        if ($user['email_verified_at']) {
            Response::error('Email already verified', 400);
        }
        
        $token = bin2hex(random_bytes(32));
        
        table('users')->where('id', $user['id'])->update([
            'email_verification_token' => $token,
            'email_verification_sent_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Send verification email
        $this->emailService->sendVerificationEmail(
            $user['email'],
            $user['name'],
            $token
        );
        
        Response::success(['message' => 'Verification email sent']);
    }
    
    private static function formatUser(array $user): array {
        return [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'] ?? null,
            'account_type' => $user['account_type'],
            'email_verified_at' => $user['email_verified_at'] ?? null,
            'created_at' => $user['created_at'],
        ];
    }
}
