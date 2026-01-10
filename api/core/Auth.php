<?php
/**
 * Authentication Helper
 */

class Auth {
    private static $user = null;
    
    public static function check(): void {
        $token = Request::bearerToken();
        
        if (!$token) {
            Response::error('Unauthorized', 401);
        }
        
        $payload = JWT::decode($token);
        
        if (!$payload || !isset($payload['sub'])) {
            Response::error('Invalid or expired token', 401);
        }
        
        $user = table('users')->where('id', $payload['sub'])->first();
        
        if (!$user) {
            Response::error('User not found', 401);
        }
        
        self::$user = $user;
    }
    
    public static function user(): ?array {
        return self::$user;
    }
    
    public static function id(): ?int {
        return self::$user['id'] ?? null;
    }
    
    public static function attempt(string $email, string $password): ?string {
        $user = table('users')->where('email', $email)->first();
        
        if (!$user) {
            return null;
        }
        
        if (!password_verify($password, $user['password'])) {
            return null;
        }
        
        return self::generateToken($user);
    }
    
    public static function generateToken(array $user): string {
        return JWT::encode([
            'sub' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
        ]);
    }
    
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    public static function hasRole(string $role): bool {
        if (!self::$user) {
            return false;
        }
        
        return (bool) table('user_roles')
            ->where('user_id', self::$user['id'])
            ->where('role', $role)
            ->first();
    }
    
    public static function isAdmin(): bool {
        return self::hasRole('admin');
    }
    
    /**
     * Format user data for frontend (keep snake_case, add computed fields)
     */
    public static function formatUserForFrontend(?array $user): ?array {
        if ($user === null) return null;
        
        return [
            'id' => (string)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'] ?? null,
            'avatar_url' => $user['avatar_url'] ?? null,
            'account_type' => $user['account_type'] ?? 'standard',
            'email_verified' => !empty($user['email_verified_at']),
            'email_verified_at' => $user['email_verified_at'] ?? null,
            'created_at' => $user['created_at'] ?? null,
        ];
    }
}
