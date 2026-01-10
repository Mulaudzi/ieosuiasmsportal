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
            'recaptcha_token' => 'max:2048',
        ]);
        
        // Verify reCAPTCHA
        $recaptchaToken = $data['recaptcha_token'] ?? '';
        RecaptchaValidator::verifyOrFail($recaptchaToken, 'register');
        
        // Rate limit registration by IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        RateLimiter::checkOrFail("register:{$ip}", 5, 60);
        
        // Validate email through security pipeline (disposable, role-based, MX check)
        $emailValidation = EmailValidator::validate($data['email']);
        if (!$emailValidation['valid']) {
            Response::error($emailValidation['error'], 400);
        }
        
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
        
        // Send verification email (wrapped in try/catch to not fail registration)
        $emailSent = false;
        try {
            $result = $this->emailService->sendVerificationEmail(
                $data['email'],
                $data['name'],
                $verificationToken
            );
            $emailSent = $result['success'] ?? false;
        } catch (\Exception $e) {
            error_log('Failed to send verification email: ' . $e->getMessage());
        }
        
        $user = table('users')->where('id', $userId)->first();
        $token = Auth::generateToken($user);
        
        Response::created([
            'user' => Auth::formatUserForFrontend($user),
            'token' => $token,
            'email_sent' => $emailSent,
            'message' => $emailSent 
                ? 'Account created successfully. Please check your email to verify your account.'
                : 'Account created successfully. Verification email could not be sent, please request a new one from your dashboard.',
        ]);
    }
    
    public function login(): void {
        $data = Request::validate([
            'email' => 'required|email',
            'password' => 'required',
            'recaptcha_token' => 'max:2048',
        ]);
        
        // Verify reCAPTCHA
        $recaptchaToken = $data['recaptcha_token'] ?? '';
        RecaptchaValidator::verifyOrFail($recaptchaToken, 'login');
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // Rate limit login attempts by IP (20 attempts per 15 minutes)
        RateLimiter::checkOrFail("login_ip:{$ip}", 20, 15);
        
        // Rate limit login attempts by email (5 attempts per 15 minutes)
        RateLimiter::checkOrFail("login:{$data['email']}", 5, 15);
        
        // First check if user exists
        $user = table('users')->where('email', $data['email'])->first();
        
        if (!$user) {
            Response::error('No account found with this email address', 401);
        }
        
        // Verify password
        if (!password_verify($data['password'], $user['password'])) {
            Response::error('Invalid credentials', 401);
        }
        
        // Generate token
        $token = Auth::generateToken($user);
        
        // Clear rate limits on successful login
        RateLimiter::clear("login:{$data['email']}");
        RateLimiter::clear("login_ip:{$ip}");
        
        Response::success([
            'user' => Auth::formatUserForFrontend($user),
            'token' => $token,
            'message' => 'Login successful',
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
            'user' => Auth::formatUserForFrontend($user),
            'wallet' => $wallet ? [
                'balance' => (float) $wallet['balance'],
                'reserved' => (float) $wallet['reserved'],
                'currency' => $wallet['currency'],
            ] : null,
        ]);
    }
    
    public function updateUser(): void {
        $user = Auth::user();
        
        $data = Request::validate([
            'name' => 'max:100',
            'phone' => 'max:20',
            'current_password' => 'min:1',
            'password' => 'min:8',
            'password_confirmation' => 'min:8',
        ]);
        
        // Handle password change
        if (!empty($data['password'])) {
            if (empty($data['current_password'])) {
                Response::error('Current password is required', 400);
            }
            
            if (!password_verify($data['current_password'], $user['password'])) {
                Response::error('Current password is incorrect', 400);
            }
            
            if ($data['password'] !== ($data['password_confirmation'] ?? '')) {
                Response::error('Passwords do not match', 400);
            }
            
            table('users')->where('id', $user['id'])->update([
                'password' => Auth::hashPassword($data['password']),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        // Handle profile update
        $updateData = [];
        if (isset($data['name']) && !empty($data['name'])) {
            $updateData['name'] = $data['name'];
        }
        if (isset($data['phone'])) {
            $updateData['phone'] = $data['phone'];
        }
        
        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            table('users')->where('id', $user['id'])->update($updateData);
        }
        
        // Fetch updated user
        $updatedUser = table('users')->where('id', $user['id'])->first();
        
        Response::success([
            'user' => Auth::formatUserForFrontend($updatedUser),
            'message' => 'Profile updated successfully',
        ]);
    }
    
    public function uploadAvatar(): void {
        $user = Auth::user();
        
        // Check if base64 image data is provided
        $input = Request::all();
        
        if (isset($input['avatar']) && strpos($input['avatar'], 'data:image') === 0) {
            // Handle base64 image
            $imageData = $input['avatar'];
            
            // Extract base64 data
            if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $matches)) {
                $extension = $matches[1];
                $imageData = substr($imageData, strpos($imageData, ',') + 1);
                $imageData = base64_decode($imageData);
                
                if ($imageData === false) {
                    Response::error('Invalid image data', 400);
                }
                
                // Validate extension
                $allowedExtensions = ['jpeg', 'jpg', 'png', 'gif', 'webp'];
                if (!in_array(strtolower($extension), $allowedExtensions)) {
                    Response::error('Invalid image type. Allowed: JPG, PNG, GIF, WebP', 400);
                }
                
                // Validate size (max 2MB)
                if (strlen($imageData) > 2 * 1024 * 1024) {
                    Response::error('Image too large. Maximum size: 2MB', 400);
                }
                
                // Create uploads directory
                $uploadsDir = __DIR__ . '/../uploads/avatars';
                if (!is_dir($uploadsDir)) {
                    mkdir($uploadsDir, 0755, true);
                }
                
                // Delete old avatar if exists
                if (!empty($user['avatar_url'])) {
                    $oldFilename = basename($user['avatar_url']);
                    $oldPath = $uploadsDir . '/' . $oldFilename;
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }
                
                // Generate unique filename
                $filename = $user['id'] . '_' . time() . '.' . $extension;
                $filepath = $uploadsDir . '/' . $filename;
                
                if (!file_put_contents($filepath, $imageData)) {
                    Response::error('Failed to save image', 500);
                }
                
                // Update user
                $avatarUrl = env('APP_URL') . '/uploads/avatars/' . $filename;
                table('users')->where('id', $user['id'])->update([
                    'avatar_url' => $avatarUrl,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                
                // Fetch updated user
                $updatedUser = table('users')->where('id', $user['id'])->first();
                
                Response::success([
                    'user' => Auth::formatUserForFrontend($updatedUser),
                    'avatar_url' => $avatarUrl,
                    'message' => 'Avatar uploaded successfully',
                ]);
                return;
            }
        }
        
        // Handle file upload
        $file = Request::file('avatar');
        
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            Response::error('No file uploaded', 400);
        }
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            Response::error('Invalid file type. Allowed: JPG, PNG, GIF, WebP', 400);
        }
        
        // Validate file size (max 2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            Response::error('File too large. Maximum size: 2MB', 400);
        }
        
        // Create uploads directory
        $uploadsDir = __DIR__ . '/../uploads/avatars';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        
        // Delete old avatar if exists
        if (!empty($user['avatar_url'])) {
            $oldFilename = basename($user['avatar_url']);
            $oldPath = $uploadsDir . '/' . $oldFilename;
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $user['id'] . '_' . time() . '.' . $extension;
        $filepath = $uploadsDir . '/' . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            Response::error('Failed to save file', 500);
        }
        
        // Update user
        $avatarUrl = env('APP_URL') . '/uploads/avatars/' . $filename;
        table('users')->where('id', $user['id'])->update([
            'avatar_url' => $avatarUrl,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Fetch updated user
        $updatedUser = table('users')->where('id', $user['id'])->first();
        
        Response::success([
            'user' => Auth::formatUserForFrontend($updatedUser),
            'avatar_url' => $avatarUrl,
            'message' => 'Avatar uploaded successfully',
        ]);
    }
    
    /**
     * Refresh JWT token
     */
    public function refreshToken(): void {
        $user = Auth::user();
        $token = Auth::generateToken($user);
        
        Response::success([
            'token' => $token,
            'expires_in' => 24 * 60 * 60, // 24 hours in seconds
        ]);
    }
    
    public function forgotPassword(): void {
        $data = Request::validate([
            'email' => 'required|email',
            'recaptcha_token' => 'max:2048',
        ]);
        
        // Verify reCAPTCHA
        $recaptchaToken = $data['recaptcha_token'] ?? '';
        RecaptchaValidator::verifyOrFail($recaptchaToken, 'forgot_password');
        
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
            'recaptcha_token' => 'max:2048',
        ]);
        
        // Verify reCAPTCHA
        $recaptchaToken = $data['recaptcha_token'] ?? '';
        RecaptchaValidator::verifyOrFail($recaptchaToken, 'reset_password');
        
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
        // Check both JSON body AND query parameters for token
        $data = Request::all();
        $token = $data['token'] ?? $_GET['token'] ?? null;
        
        if (!$token) {
            Response::error('Verification token is required', 400);
        }
        
        // Rate limit verification attempts by IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        RateLimiter::checkOrFail("verify_email:{$ip}", 10, 15);
        
        $user = table('users')
            ->where('email_verification_token', $token)
            ->first();
        
        if (!$user) {
            Response::error('Invalid verification token', 400);
        }
        
        if ($user['email_verified_at']) {
            Response::success([
                'message' => 'Email already verified',
                'status' => 'already-verified'
            ]);
        }
        
        // Check if token is expired (24 hours)
        if (isset($user['email_verification_sent_at'])) {
            $sentAt = strtotime($user['email_verification_sent_at']);
            if (time() - $sentAt > 86400) {
                Response::error('Verification token has expired. Please request a new one.', 400);
            }
        }
        
        table('users')->where('id', $user['id'])->update([
            'email_verified_at' => date('Y-m-d H:i:s'),
            'email_verification_token' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Send welcome email after successful verification
        $this->emailService->sendWelcomeEmail($user['email'], $user['name']);
        
        Response::success([
            'message' => 'Email verified successfully',
            'status' => 'success'
        ]);
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
    
}
