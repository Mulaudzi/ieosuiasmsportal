<?php
/**
 * Authentication Controller
 * Handles user registration, login, password reset, and email verification
 */

require_once __DIR__ . '/../services/AdminNotificationService.php';
require_once __DIR__ . '/../services/AuditLogService.php';

class AuthController {
    
    // Valid account types matching database enum
    private const VALID_ACCOUNT_TYPES = ['individual', 'business', 'organization', 'standard'];
    
    /**
     * Validate and sanitize account type
     */
    private function validateAccountType(?string $type): string {
        if ($type === null || !in_array($type, self::VALID_ACCOUNT_TYPES, true)) {
            return 'standard'; // Default fallback
        }
        return $type;
    }
    
    /**
     * Register a new user
     */
    public function register(): void {
        $data = Request::validate([
            'name' => 'required|min:2|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'phone' => 'max:20',
            'account_type' => 'max:20',
            'recaptcha_token' => 'max:2048',
        ]);
        
        // Verify reCAPTCHA (soft fail if not configured)
        RecaptchaValidator::verifyOrFail($data['recaptcha_token'] ?? '', 'register');
        
        // Rate limit registration by IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        RateLimiter::checkOrFail("register:{$ip}", 5, 60);
        
        // Validate email (disposable, role-based, MX check)
        $emailValidation = EmailValidator::validate($data['email']);
        if (!$emailValidation['valid']) {
            Response::error($emailValidation['error'], 400);
            return;
        }
        
        // Generate verification token
        $verificationToken = bin2hex(random_bytes(32));
        
        // Validate account type
        $accountType = $this->validateAccountType($data['account_type'] ?? null);
        
        // Create user
        $userId = table('users')->insert([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Auth::hashPassword($data['password']),
            'phone' => $data['phone'] ?? null,
            'account_type' => $accountType,
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
        
        // Send verification email (don't fail registration if email fails)
        $emailSent = false;
        try {
            $result = EmailService::sendVerificationEmail(
                $data['email'],
                $data['name'],
                $verificationToken
            );
            $emailSent = $result['success'] ?? false;
        } catch (\Exception $e) {
            error_log('Failed to send verification email: ' . $e->getMessage());
        }
        
        // Generate token and return
        $user = table('users')->where('id', $userId)->first();
        $token = Auth::generateToken($user);
        
        // Notify admins about new registration
        AdminNotificationService::notifyNewUser($userId, $data['name'], $data['email']);
        
        // Log registration
        AuditLogService::log('user_registered', 'user', $userId, null, [
            'name' => $data['name'],
            'email' => $data['email'],
        ], $userId);
        
        Response::created([
            'user' => Auth::formatUserForFrontend($user),
            'token' => $token,
            'email_sent' => $emailSent,
            'message' => 'Account created successfully. Please check your email to verify your account.',
        ]);
    }
    
    /**
     * Login user
     */
    public function login(): void {
        $data = Request::validate([
            'email' => 'required|email',
            'password' => 'required',
            'password_2' => 'max:255',
            'password_3' => 'max:255',
            'recaptcha_token' => 'max:2048',
        ]);
        
        // Verify reCAPTCHA (soft fail if not configured)
        RecaptchaValidator::verifyOrFail($data['recaptcha_token'] ?? '', 'login');
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // Rate limit login attempts
        RateLimiter::checkOrFail("login_ip:{$ip}", 20, 15);
        RateLimiter::checkOrFail("login:{$data['email']}", 5, 15);
        
        // Check if this is an admin email (requires 3 passwords)
        require_once __DIR__ . '/AdminUserController.php';
        $isAdminEmail = AdminUserController::isAdminEmail($data['email']);
        
        if ($isAdminEmail) {
            // Admin login requires all 3 passwords
            if (empty($data['password_2']) || empty($data['password_3'])) {
                Response::success([
                    'requires_admin_auth' => true,
                    'message' => 'Admin authentication requires 3 passwords',
                ]);
                return;
            }
            
            $authResult = AdminUserController::authenticate(
                $data['email'], 
                $data['password'], 
                $data['password_2'], 
                $data['password_3']
            );
            
            if ($authResult['success'] && isset($authResult['admin'])) {
                $adminUser = $authResult['admin'];
                
                // Admin login successful - find or create user record
                $userRecord = table('users')->where('email', $data['email'])->first();
                
                if (!$userRecord) {
                    // Create user record for admin
                    $userId = table('users')->insert([
                        'name' => $adminUser['name'],
                        'email' => $adminUser['email'],
                        'password' => $adminUser['password_1'],
                        'account_type' => 'admin',
                        'email_verified_at' => date('Y-m-d H:i:s'),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $userRecord = table('users')->where('id', $userId)->first();
                } else {
                    // Update account type to admin if needed
                    if ($userRecord['account_type'] !== 'admin') {
                        table('users')->where('id', $userRecord['id'])->update([
                            'account_type' => 'admin',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                        $userRecord['account_type'] = 'admin';
                    }
                }
                
                // Generate token
                $token = Auth::generateToken($userRecord);
                
                // Clear rate limits
                RateLimiter::clear("login:{$data['email']}");
                RateLimiter::clear("login_ip:{$ip}");
                
                // Log admin login
                AuditLogService::log('admin_login', 'user', $userRecord['id'], null, [
                    'ip_address' => $ip,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'admin_id' => $adminUser['id'],
                ], $userRecord['id']);
                
                Response::success([
                    'user' => Auth::formatUserForFrontend($userRecord),
                    'token' => $token,
                    'message' => 'Admin login successful',
                ]);
                return;
            }
            
            // Admin authentication failed
            // Log failed admin attempt
            AuditLogService::log('admin_login_failed', 'security', null, null, [
                'ip_address' => $ip,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'attempted_email' => $data['email'],
            ], null);
            
            // Send security alert email
            $this->sendAdminLoginAlert($ip, $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
            
            // Return generic error with remaining attempts (don't reveal which password failed)
            Response::json([
                'success' => false,
                'message' => 'Authentication failed',
                'data' => [
                    'remaining_attempts' => $authResult['remaining_attempts'] ?? null,
                    'locked_until' => $authResult['locked_until'] ?? null,
                ],
            ], 401);
            return;
        }
        
        // Find regular user
        $user = table('users')->where('email', $data['email'])->first();
        
        if (!$user) {
            Response::error('No account found with this email address', 404);
            return;
        }
        
        // Verify password
        if (!password_verify($data['password'], $user['password'])) {
            Response::error('Invalid password', 401);
            return;
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
    
    /**
     * Logout user
     */
    public function logout(): void {
        Response::success(['message' => 'Logged out successfully']);
    }
    
    /**
     * Get current user
     */
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
    
    /**
     * Update user profile
     */
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
                return;
            }
            
            if (!password_verify($data['current_password'], $user['password'])) {
                Response::error('Current password is incorrect', 400);
                return;
            }
            
            if ($data['password'] !== ($data['password_confirmation'] ?? '')) {
                Response::error('Passwords do not match', 400);
                return;
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
        
        $updatedUser = table('users')->where('id', $user['id'])->first();
        
        Response::success([
            'user' => Auth::formatUserForFrontend($updatedUser),
            'message' => 'Profile updated successfully',
        ]);
    }
    
    /**
     * Upload avatar
     */
    public function uploadAvatar(): void {
        $user = Auth::user();
        
        $input = Request::all();
        
        if (isset($input['avatar']) && strpos($input['avatar'], 'data:image') === 0) {
            // Handle base64 image
            if (preg_match('/^data:image\/(\w+);base64,/', $input['avatar'], $matches)) {
                $extension = $matches[1];
                $imageData = substr($input['avatar'], strpos($input['avatar'], ',') + 1);
                $imageData = base64_decode($imageData);
                
                if ($imageData === false) {
                    Response::error('Invalid image data', 400);
                    return;
                }
                
                $allowedExtensions = ['jpeg', 'jpg', 'png', 'gif', 'webp'];
                if (!in_array(strtolower($extension), $allowedExtensions)) {
                    Response::error('Invalid image type', 400);
                    return;
                }
                
                if (strlen($imageData) > 2 * 1024 * 1024) {
                    Response::error('Image too large (max 2MB)', 400);
                    return;
                }
                
                $uploadsDir = __DIR__ . '/../uploads/avatars';
                if (!is_dir($uploadsDir)) {
                    mkdir($uploadsDir, 0755, true);
                }
                
                // Delete old avatar
                if (!empty($user['avatar_url'])) {
                    $oldPath = $uploadsDir . '/' . basename($user['avatar_url']);
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }
                
                $filename = $user['id'] . '_' . time() . '.' . $extension;
                $filepath = $uploadsDir . '/' . $filename;
                
                if (!file_put_contents($filepath, $imageData)) {
                    Response::error('Failed to save image', 500);
                    return;
                }
                
                $avatarUrl = env('APP_URL') . '/uploads/avatars/' . $filename;
                table('users')->where('id', $user['id'])->update([
                    'avatar_url' => $avatarUrl,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                
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
            return;
        }
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            Response::error('Invalid file type', 400);
            return;
        }
        
        if ($file['size'] > 2 * 1024 * 1024) {
            Response::error('File too large (max 2MB)', 400);
            return;
        }
        
        $uploadsDir = __DIR__ . '/../uploads/avatars';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        
        // Delete old avatar
        if (!empty($user['avatar_url'])) {
            $oldPath = $uploadsDir . '/' . basename($user['avatar_url']);
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $user['id'] . '_' . time() . '.' . $extension;
        $filepath = $uploadsDir . '/' . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            Response::error('Failed to save file', 500);
            return;
        }
        
        $avatarUrl = env('APP_URL') . '/uploads/avatars/' . $filename;
        table('users')->where('id', $user['id'])->update([
            'avatar_url' => $avatarUrl,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
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
            'expires_in' => 24 * 60 * 60,
        ]);
    }
    
    /**
     * Forgot password - send reset code
     */
    public function forgotPassword(): void {
        $data = Request::validate([
            'email' => 'required|email',
            'recaptcha_token' => 'max:2048',
        ]);
        
        RecaptchaValidator::verifyOrFail($data['recaptcha_token'] ?? '', 'forgot_password');
        
        RateLimiter::checkOrFail("forgot_password:{$data['email']}", 3, 15);
        
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
            
            try {
                EmailService::sendPasswordResetEmail($user['email'], $user['name'], $otp);
            } catch (\Exception $e) {
                error_log('Failed to send password reset email: ' . $e->getMessage());
            }
        }
        
        Response::success(['message' => 'If the email exists, a reset code has been sent']);
    }
    
    /**
     * Reset password with OTP
     */
    public function resetPassword(): void {
        $data = Request::validate([
            'email' => 'required|email',
            'otp' => 'required|min:6|max:6',
            'password' => 'required|min:8|confirmed',
            'recaptcha_token' => 'max:2048',
        ]);
        
        RecaptchaValidator::verifyOrFail($data['recaptcha_token'] ?? '', 'reset_password');
        
        RateLimiter::checkOrFail("reset_password:{$data['email']}", 5, 15);
        
        $user = table('users')
            ->where('email', $data['email'])
            ->where('otp_code', $data['otp'])
            ->first();
        
        if (!$user) {
            Response::error('Invalid reset code', 400);
            return;
        }
        
        if (strtotime($user['otp_expires_at']) < time()) {
            Response::error('Reset code has expired', 400);
            return;
        }
        
        table('users')->where('id', $user['id'])->update([
            'password' => Auth::hashPassword($data['password']),
            'otp_code' => null,
            'otp_expires_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        RateLimiter::clear("reset_password:{$data['email']}");
        RateLimiter::clear("forgot_password:{$data['email']}");
        
        Response::success(['message' => 'Password reset successfully']);
    }
    
    /**
     * Verify email
     */
    public function verifyEmail(): void {
        $data = Request::input();
        $token = $data['token'] ?? $_GET['token'] ?? null;
        
        if (!$token) {
            Response::error('Verification token is required', 400);
            return;
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        RateLimiter::checkOrFail("verify_email:{$ip}", 10, 15);
        
        $user = table('users')
            ->where('email_verification_token', $token)
            ->first();
        
        if (!$user) {
            Response::error('Invalid verification token', 400);
            return;
        }
        
        if ($user['email_verified_at']) {
            Response::success([
                'message' => 'Email already verified',
                'status' => 'already-verified'
            ]);
            return;
        }
        
        // Check if token expired (24 hours)
        if (isset($user['email_verification_sent_at'])) {
            $sentAt = strtotime($user['email_verification_sent_at']);
            if (time() - $sentAt > 86400) {
                Response::error('Verification token has expired', 400);
                return;
            }
        }
        
        table('users')->where('id', $user['id'])->update([
            'email_verified_at' => date('Y-m-d H:i:s'),
            'email_verification_token' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Send welcome email
        try {
            EmailService::sendWelcomeEmail($user['email'], $user['name']);
        } catch (\Exception $e) {
            error_log('Failed to send welcome email: ' . $e->getMessage());
        }
        
        Response::success([
            'message' => 'Email verified successfully',
            'status' => 'success'
        ]);
    }
    
    /**
     * Resend verification email
     */
    public function resendVerification(): void {
        $user = Auth::user();
        
        if (!empty($user['email_verified_at'])) {
            Response::success([
                'message' => 'Email is already verified',
                'already_verified' => true
            ]);
            return;
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        RateLimiter::checkOrFail("resend_verification:{$user['id']}", 3, 15);
        RateLimiter::checkOrFail("resend_verification_ip:{$ip}", 10, 60);
        
        // Generate new token
        $verificationToken = bin2hex(random_bytes(32));
        
        table('users')->where('id', $user['id'])->update([
            'email_verification_token' => $verificationToken,
            'email_verification_sent_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $emailSent = false;
        try {
            $result = EmailService::sendVerificationEmail(
                $user['email'],
                $user['name'],
                $verificationToken
            );
            $emailSent = $result['success'] ?? false;
        } catch (\Exception $e) {
            error_log('Failed to resend verification email: ' . $e->getMessage());
        }
        
        if ($emailSent) {
            Response::success(['message' => 'Verification email sent']);
        } else {
            Response::error('Failed to send verification email', 500);
        }
    }
    
    /**
     * Send security alert for failed admin login attempts
     */
    private function sendAdminLoginAlert(string $ip, string $userAgent): void {
        try {
            $adminEmail = 'godtheson@ieosuia.com';
            $timestamp = date('Y-m-d H:i:s');
            
            $subject = '⚠️ Security Alert: Failed Admin Login Attempt';
            $body = "
                <h2 style='color: #dc2626;'>Security Alert</h2>
                <p>A failed admin login attempt was detected on your IEOSUIA SMS Portal.</p>
                <table style='border-collapse: collapse; margin: 20px 0;'>
                    <tr>
                        <td style='padding: 8px; border: 1px solid #e5e7eb; font-weight: bold;'>Time:</td>
                        <td style='padding: 8px; border: 1px solid #e5e7eb;'>{$timestamp}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px; border: 1px solid #e5e7eb; font-weight: bold;'>IP Address:</td>
                        <td style='padding: 8px; border: 1px solid #e5e7eb;'>{$ip}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px; border: 1px solid #e5e7eb; font-weight: bold;'>User Agent:</td>
                        <td style='padding: 8px; border: 1px solid #e5e7eb;'>{$userAgent}</td>
                    </tr>
                </table>
                <p style='color: #6b7280;'>If this was you, you can ignore this message. Otherwise, please review your security settings.</p>
                <p style='color: #6b7280; font-size: 12px;'>This is an automated security notification from IEOSUIA SMS Portal.</p>
            ";
            
            EmailService::sendRawEmail($adminEmail, $subject, $body);
            
            error_log("Admin login security alert sent for IP: {$ip}");
        } catch (\Exception $e) {
            error_log("Failed to send admin login security alert: " . $e->getMessage());
        }
    }
}
