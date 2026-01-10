<?php
/**
 * Database Configuration
 * Loads environment variables from .env ONLY
 */

// Load .env file
function loadEnv(string $path): bool {
    if (!file_exists($path)) {
        error_log("ENV file not found: $path");
        return false;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comments and empty lines
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        
        // Only set if not already defined
        if (!getenv($name)) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }
    return true;
}

// Load .env file from api directory
$envPath = __DIR__ . '/../.env';
if (!loadEnv($envPath)) {
    // Fatal error - .env is required
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server configuration error: .env file missing']);
    exit;
}

/**
 * Get environment variable with default
 */
function env(string $key, $default = null) {
    // Check $_ENV first, then getenv
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

// Database configuration array
$dbConfig = [
    'host' => env('DB_HOST', 'localhost'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'charset' => 'utf8mb4'
];

/**
 * Get PDO database connection (singleton)
 */
function getDatabase(): PDO {
    global $dbConfig;
    static $pdo = null;
    
    if ($pdo === null) {
        if (empty($dbConfig['database']) || empty($dbConfig['username'])) {
            throw new Exception("Database not configured in .env");
        }
        
        try {
            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['database'],
                $dbConfig['charset']
            );
            
            $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }
    
    return $pdo;
}

/**
 * Shorthand for getting database
 */
function db(): PDO {
    return getDatabase();
}
