<?php
/**
 * Authentication Helper
 */

class Auth {
    private static $user = null;
    private static $tokenChecked = false;

    public static function ensureRevocationTable(): void {
        db()->exec("CREATE TABLE IF NOT EXISTS revoked_auth_tokens (token_hash CHAR(64) PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, token_type ENUM('customer','admin') NOT NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_revoked_auth_tokens_user (user_id,token_type), INDEX idx_revoked_auth_tokens_expiry (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    
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
        
        if (!$user || !(bool) ($user['is_active'] ?? true)) {
            Response::error('User not found', 401);
        }

        self::ensureRevocationTable();
        $revoked = db()->prepare('SELECT 1 FROM revoked_auth_tokens WHERE token_hash = ? AND expires_at >= NOW() LIMIT 1');
        $revoked->execute([hash('sha256', $token)]);
        if ($revoked->fetchColumn()) {
            Response::error('Session has been invalidated', 401);
        }

        if ((int) ($payload['ver'] ?? 1) !== (int) ($user['auth_version'] ?? 1)) {
            Response::error('Session has been invalidated', 401);
        }
        
        self::$user = $user;
        self::$tokenChecked = true;
    }
    
    /**
     * Get the authenticated user, auto-loading from token if needed
     */
    public static function user(): ?array {
        // If user already loaded, return it
        if (self::$user !== null) {
            return self::$user;
        }
        
        // Try to load user from token if not yet checked
        if (!self::$tokenChecked) {
            $token = Request::bearerToken();
            if ($token) {
                $payload = JWT::decode($token);
                if ($payload && isset($payload['sub'])) {
                    self::ensureRevocationTable();
                    $revoked = db()->prepare('SELECT 1 FROM revoked_auth_tokens WHERE token_hash = ? AND expires_at >= NOW() LIMIT 1');
                    $revoked->execute([hash('sha256', $token)]);
                    if ($revoked->fetchColumn()) {
                        self::$tokenChecked = true;
                        return null;
                    }
                    $user = table('users')->where('id', $payload['sub'])->first();
                    if ($user && (bool) ($user['is_active'] ?? true) && (int) ($payload['ver'] ?? 1) === (int) ($user['auth_version'] ?? 1)) {
                        self::$user = $user;
                    }
                    self::$tokenChecked = true;
                }
            }
        }
        
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
            'ver' => (int) ($user['auth_version'] ?? 1),
        ]);
    }
    
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    public static function hasRole(string $role): bool {
        if (!self::$user) {
            return false;
        }
        
        return strtolower((string) (self::$user['role'] ?? 'user')) === strtolower($role);
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
            'avatar_url' => !empty($user['avatar_url']) ? '/api/uploads/avatars/' . basename($user['avatar_url']) : null,
            'account_type' => $user['account_type'] ?? 'standard',
            'role' => $user['role'] ?? 'user',
            'email_verified' => !empty($user['email_verified_at']),
            'email_verified_at' => $user['email_verified_at'] ?? null,
            'created_at' => $user['created_at'] ?? null,
        ];
    }
}
