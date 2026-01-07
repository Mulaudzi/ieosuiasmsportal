<?php
/**
 * Authentication Controller
 */

class AuthController {
    public function register(): void {
        $data = Request::validate([
            'name' => 'required|min:2|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'phone' => 'max:20',
        ]);
        
        $userId = table('users')->insert([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Auth::hashPassword($data['password']),
            'phone' => $data['phone'] ?? null,
            'account_type' => 'standard',
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
        
        $token = Auth::attempt($data['email'], $data['password']);
        
        if (!$token) {
            Response::error('Invalid credentials', 401);
        }
        
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
        
        $user = table('users')->where('email', $data['email'])->first();
        
        // Always return success to prevent email enumeration
        if ($user) {
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            
            table('users')->where('id', $user['id'])->update([
                'otp_code' => $otp,
                'otp_expires_at' => date('Y-m-d H:i:s', strtotime('+15 minutes')),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            // TODO: Send email with OTP
        }
        
        Response::success(['message' => 'If the email exists, a reset code has been sent']);
    }
    
    public function resetPassword(): void {
        $data = Request::validate([
            'email' => 'required|email',
            'otp' => 'required|min:6|max:6',
            'password' => 'required|min:8|confirmed',
        ]);
        
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
        
        Response::success(['message' => 'Password reset successfully']);
    }
    
    private static function formatUser(array $user): array {
        return [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'] ?? null,
            'account_type' => $user['account_type'],
            'created_at' => $user['created_at'],
        ];
    }
}
