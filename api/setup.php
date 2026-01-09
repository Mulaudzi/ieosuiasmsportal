<?php
/**
 * IEOSUIA SMS Portal - Database Setup Script
 * 
 * This script automatically runs all migrations and seeds.
 * Access via: https://sms.ieosuia.com/api/setup.php
 * 
 * IMPORTANT: Delete or protect this file after initial setup!
 */

// Load environment
require_once __DIR__ . '/core/Response.php';

// Simple .env loader
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        putenv("$name=$value");
        $_ENV[$name] = $value;
    }
}

loadEnv(__DIR__ . '/.env');

// Database configuration
$dbConfig = [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'database' => getenv('DB_DATABASE') ?: 'ejetffbz_sms',
    'username' => getenv('DB_USERNAME') ?: 'ejetffbz_ieosuia',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset' => 'utf8mb4'
];

// Security check - only allow from localhost or with setup key
$setupKey = getenv('SETUP_KEY') ?: '';
$providedKey = $_GET['key'] ?? '';

$isLocalhost = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']);
$hasValidKey = !empty($setupKey) && hash_equals($setupKey, $providedKey);

if (!$isLocalhost && !$hasValidKey) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode([
        'success' => false,
        'message' => 'Access denied. Provide setup key or run from localhost.',
        'usage' => 'Add ?key=YOUR_SETUP_KEY to the URL'
    ]);
    exit;
}

// HTML Output for browser
$isCli = php_sapi_name() === 'cli';
$isJson = isset($_GET['format']) && $_GET['format'] === 'json';

if (!$isCli && !$isJson) {
    echo "<!DOCTYPE html><html><head><title>IEOSUIA SMS - Database Setup</title>";
    echo "<style>body{font-family:monospace;background:#1a1a2e;color:#eee;padding:20px;line-height:1.6}";
    echo ".success{color:#4ade80}.error{color:#f87171}.info{color:#60a5fa}.warning{color:#fbbf24}";
    echo "pre{background:#0d0d1a;padding:15px;border-radius:8px;overflow-x:auto}</style></head><body>";
    echo "<h1>🚀 IEOSUIA SMS Portal - Database Setup</h1><pre>";
}

$results = [
    'success' => true,
    'migrations' => [],
    'errors' => []
];

function output($message, $type = 'info') {
    global $isCli, $isJson, $results;
    
    if ($isJson) {
        if ($type === 'error') {
            $results['errors'][] = $message;
        }
        return;
    }
    
    $prefix = match($type) {
        'success' => $isCli ? '✓ ' : '<span class="success">✓ ',
        'error' => $isCli ? '✗ ' : '<span class="error">✗ ',
        'warning' => $isCli ? '⚠ ' : '<span class="warning">⚠ ',
        default => $isCli ? '→ ' : '<span class="info">→ '
    };
    $suffix = $isCli ? "\n" : "</span>\n";
    
    echo $prefix . $message . $suffix;
    if (!$isCli) flush();
}

try {
    // Connect to database
    output("Connecting to database: {$dbConfig['host']}/{$dbConfig['database']}");
    
    $dsn = "mysql:host={$dbConfig['host']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbConfig['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbConfig['database']}`");
    
    output("Connected successfully!", 'success');
    
    // Disable foreign key checks during migration
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Get list of migration files
    $migrationsPath = __DIR__ . '/migrations';
    $migrationFiles = glob($migrationsPath . '/*.sql');
    sort($migrationFiles); // Ensure order by filename
    
    output("Found " . count($migrationFiles) . " migration files");
    
    // Check if migrations table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'migrations'");
    $migrationsTableExists = $stmt->rowCount() > 0;
    
    $executedMigrations = [];
    if ($migrationsTableExists) {
        $stmt = $pdo->query("SELECT migration FROM migrations");
        $executedMigrations = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    // Get current batch number
    $batch = 1;
    if ($migrationsTableExists) {
        $stmt = $pdo->query("SELECT MAX(batch) as max_batch FROM migrations");
        $result = $stmt->fetch();
        $batch = ($result['max_batch'] ?? 0) + 1;
    }
    
    $migrationsRun = 0;
    
    foreach ($migrationFiles as $file) {
        $filename = basename($file);
        
        // Skip if already executed
        if (in_array($filename, $executedMigrations)) {
            output("Skipping (already run): $filename", 'warning');
            continue;
        }
        
        output("Running migration: $filename");
        
        $sql = file_get_contents($file);
        
        // Split by semicolons (handle multiple statements)
        $statements = array_filter(
            array_map('trim', preg_split('/;\s*$/m', $sql)),
            fn($s) => !empty($s) && !preg_match('/^--/', $s)
        );
        
        foreach ($statements as $statement) {
            if (empty(trim($statement))) continue;
            
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                // Ignore "table already exists" and "duplicate column" errors
                if (strpos($e->getMessage(), '1050') === false && 
                    strpos($e->getMessage(), '1060') === false &&
                    strpos($e->getMessage(), '1061') === false) {
                    throw $e;
                }
                output("  Note: " . $e->getMessage(), 'warning');
            }
        }
        
        // Record migration (if migrations table exists now)
        try {
            $stmt = $pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
            $stmt->execute([$filename, $batch]);
        } catch (PDOException $e) {
            // Migrations table might not exist yet
        }
        
        output("Completed: $filename", 'success');
        $results['migrations'][] = $filename;
        $migrationsRun++;
    }
    
    // Re-enable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    output("");
    output("=== Setup Complete ===", 'success');
    output("Migrations run: $migrationsRun");
    output("Total migrations: " . count($migrationFiles));
    
    // Show test user credentials
    output("");
    output("=== Test User Credentials ===", 'info');
    output("Email: test@ieosuia.com");
    output("Password: 123456789");
    
    output("");
    output("⚠️  SECURITY WARNING: Delete or protect this file after setup!", 'warning');
    
} catch (PDOException $e) {
    $results['success'] = false;
    $results['errors'][] = $e->getMessage();
    output("Database Error: " . $e->getMessage(), 'error');
} catch (Exception $e) {
    $results['success'] = false;
    $results['errors'][] = $e->getMessage();
    output("Error: " . $e->getMessage(), 'error');
}

if (!$isCli && !$isJson) {
    echo "</pre>";
    echo "<p><a href='/login' style='color:#60a5fa'>← Go to Login</a></p>";
    echo "</body></html>";
}

if ($isJson) {
    header('Content-Type: application/json');
    echo json_encode($results, JSON_PRETTY_PRINT);
}
