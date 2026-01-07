<?php
/**
 * Rate Limiter - Prevents abuse of sensitive endpoints
 */

class RateLimiter {
    private static string $cacheDir = '/tmp/rate_limits';
    
    /**
     * Check if the action is rate limited
     * @param string $key Unique identifier (e.g., "forgot_password:user@email.com")
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $decayMinutes Time window in minutes
     * @return bool True if allowed, false if rate limited
     */
    public static function attempt(string $key, int $maxAttempts = 5, int $decayMinutes = 15): bool {
        self::ensureCacheDir();
        
        $hash = md5($key);
        $file = self::$cacheDir . '/' . $hash;
        
        $data = self::getData($file);
        $now = time();
        
        // Clean up old attempts
        $data['attempts'] = array_filter($data['attempts'], function($timestamp) use ($now, $decayMinutes) {
            return ($now - $timestamp) < ($decayMinutes * 60);
        });
        
        // Check if rate limited
        if (count($data['attempts']) >= $maxAttempts) {
            return false;
        }
        
        // Record this attempt
        $data['attempts'][] = $now;
        self::saveData($file, $data);
        
        return true;
    }
    
    /**
     * Get remaining attempts for a key
     */
    public static function remaining(string $key, int $maxAttempts = 5, int $decayMinutes = 15): int {
        self::ensureCacheDir();
        
        $hash = md5($key);
        $file = self::$cacheDir . '/' . $hash;
        
        $data = self::getData($file);
        $now = time();
        
        // Clean up old attempts
        $validAttempts = array_filter($data['attempts'], function($timestamp) use ($now, $decayMinutes) {
            return ($now - $timestamp) < ($decayMinutes * 60);
        });
        
        return max(0, $maxAttempts - count($validAttempts));
    }
    
    /**
     * Get seconds until rate limit resets
     */
    public static function availableIn(string $key, int $decayMinutes = 15): int {
        self::ensureCacheDir();
        
        $hash = md5($key);
        $file = self::$cacheDir . '/' . $hash;
        
        $data = self::getData($file);
        
        if (empty($data['attempts'])) {
            return 0;
        }
        
        $oldestAttempt = min($data['attempts']);
        $expiresAt = $oldestAttempt + ($decayMinutes * 60);
        
        return max(0, $expiresAt - time());
    }
    
    /**
     * Clear rate limit for a key
     */
    public static function clear(string $key): void {
        $hash = md5($key);
        $file = self::$cacheDir . '/' . $hash;
        
        if (file_exists($file)) {
            unlink($file);
        }
    }
    
    /**
     * Rate limit check that returns an error response if exceeded
     */
    public static function checkOrFail(string $key, int $maxAttempts = 5, int $decayMinutes = 15): void {
        if (!self::attempt($key, $maxAttempts, $decayMinutes)) {
            $seconds = self::availableIn($key, $decayMinutes);
            $minutes = ceil($seconds / 60);
            Response::error("Too many attempts. Please try again in {$minutes} minute(s).", 429);
        }
    }
    
    private static function ensureCacheDir(): void {
        if (!is_dir(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
        }
    }
    
    private static function getData(string $file): array {
        if (!file_exists($file)) {
            return ['attempts' => []];
        }
        
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        
        return $data ?: ['attempts' => []];
    }
    
    private static function saveData(string $file, array $data): void {
        file_put_contents($file, json_encode($data), LOCK_EX);
    }
}
