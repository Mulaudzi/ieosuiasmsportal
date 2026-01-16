<?php
/**
 * PHPUnit Bootstrap File
 * Sets up the testing environment
 */

// Define testing mode
define('TESTING', true);

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('UTC');

// Autoload dependencies
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/QueryBuilder.php';
require_once __DIR__ . '/../core/Router.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/JWT.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/RateLimiter.php';
require_once __DIR__ . '/../core/EmailValidator.php';

// Mock Response class for testing
class TestResponse {
    public static array $lastResponse = [];
    public static int $lastStatusCode = 200;
    
    public static function success(array $data, int $code = 200): void {
        self::$lastResponse = $data;
        self::$lastStatusCode = $code;
    }
    
    public static function error(string $message, int $code = 400): void {
        self::$lastResponse = ['error' => $message];
        self::$lastStatusCode = $code;
    }
    
    public static function created(array $data): void {
        self::$lastResponse = $data;
        self::$lastStatusCode = 201;
    }
    
    public static function reset(): void {
        self::$lastResponse = [];
        self::$lastStatusCode = 200;
    }
}

// Mock Request class for testing
class TestRequest {
    private static array $inputData = [];
    private static array $queryData = [];
    private static ?string $bearerToken = null;
    
    public static function setInput(array $data): void {
        self::$inputData = $data;
    }
    
    public static function setQuery(array $data): void {
        self::$queryData = $data;
    }
    
    public static function setBearerToken(?string $token): void {
        self::$bearerToken = $token;
    }
    
    public static function input(): array {
        return self::$inputData;
    }
    
    public static function query(string $key = null, $default = null) {
        if ($key === null) {
            return self::$queryData;
        }
        return self::$queryData[$key] ?? $default;
    }
    
    public static function bearerToken(): ?string {
        return self::$bearerToken;
    }
    
    public static function reset(): void {
        self::$inputData = [];
        self::$queryData = [];
        self::$bearerToken = null;
    }
}

// Mock Auth class for testing
class TestAuth {
    private static ?array $user = null;
    private static ?int $userId = null;
    
    public static function setUser(array $user): void {
        self::$user = $user;
        self::$userId = (int)$user['id'];
    }
    
    public static function user(): ?array {
        return self::$user;
    }
    
    public static function id(): ?int {
        return self::$userId;
    }
    
    public static function check(): bool {
        return self::$user !== null;
    }
    
    public static function reset(): void {
        self::$user = null;
        self::$userId = null;
    }
}

/**
 * Helper function to create test user
 */
function createTestUser(PDO $pdo): array {
    $email = 'phpunit_test_' . time() . '@test.com';
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, email_verified_at, created_at) VALUES (?, ?, ?, NOW(), NOW())");
    $stmt->execute(['PHPUnit Test User', $email, password_hash('test123', PASSWORD_DEFAULT)]);
    $userId = $pdo->lastInsertId();
    
    // Create wallet for user
    $stmt = $pdo->prepare("INSERT INTO wallets (user_id, balance, created_at) VALUES (?, 100.00, NOW())");
    $stmt->execute([$userId]);
    
    return [
        'id' => (int)$userId,
        'name' => 'PHPUnit Test User',
        'email' => $email,
    ];
}

/**
 * Helper function to cleanup test user
 */
function cleanupTestUser(PDO $pdo, int $userId): void {
    // Delete in correct order due to foreign keys
    $tables = ['messages', 'campaigns', 'contacts', 'contact_groups', 'templates', 'wallet_transactions', 'wallets', 'notifications'];
    foreach ($tables as $table) {
        try {
            $pdo->exec("DELETE FROM {$table} WHERE user_id = {$userId}");
        } catch (Exception $e) {
            // Ignore if table doesn't exist or other errors
        }
    }
    $pdo->exec("DELETE FROM users WHERE id = {$userId}");
}

/**
 * Get test database connection
 */
function getTestDatabase(): PDO {
    return db();
}
