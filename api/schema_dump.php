<?php
/**
 * Database Schema Introspection Utility
 * 
 * Outputs a complete, structured JSON representation of all database tables
 * and their columns for use with Lovable AI to guide code updates.
 * 
 * ACCESS CONTROL:
 * - Requires secret key via query param: ?key=YOUR_SECRET
 * - OR environment APP_ENV=local
 * - OR authenticated admin session
 * 
 * USAGE:
 * - Visit: /api/schema_dump.php?key=schema_dump_2024
 * - Copy the JSON output
 * - Paste into Lovable chat for schema analysis
 * 
 * SECURITY:
 * - Delete this file after use in production
 * - Change the secret key before deploying
 */

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Load database configuration
require_once __DIR__ . '/config/database.php';

// ============================================
// ACCESS CONTROL - Configure as needed
// ============================================

$SECRET_KEY = 'schema_dump_2024'; // Change this before deploying!
$ALLOW_LOCAL_ENV = true;          // Allow access when APP_ENV=local
$REQUIRE_AUTH = false;            // Set true to require admin auth

// Check access
$hasAccess = false;

// Method 1: Secret key in query string
if (isset($_GET['key']) && $_GET['key'] === $SECRET_KEY) {
    $hasAccess = true;
}

// Method 2: Local environment
if ($ALLOW_LOCAL_ENV && env('APP_ENV') === 'local') {
    $hasAccess = true;
}

// Method 3: Admin authentication (optional)
if ($REQUIRE_AUTH && !$hasAccess) {
    require_once __DIR__ . '/core/JWT.php';
    require_once __DIR__ . '/core/Auth.php';
    
    $user = Auth::user();
    if ($user && isset($user['role']) && $user['role'] === 'admin') {
        $hasAccess = true;
    }
}

if (!$hasAccess) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Access denied',
        'message' => 'Provide valid key via ?key=YOUR_SECRET or set APP_ENV=local',
        'hint' => 'See file header for access control options'
    ], JSON_PRETTY_PRINT);
    exit;
}

// ============================================
// SCHEMA INTROSPECTION
// ============================================

try {
    $pdo = getDatabase();
    $dbName = env('DB_DATABASE');
    
    if (!$dbName) {
        throw new Exception('Database name not configured');
    }
    
    // Get all tables
    $tablesStmt = $pdo->prepare("
        SELECT TABLE_NAME, TABLE_COMMENT, ENGINE, TABLE_ROWS
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = ? 
        AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME
    ");
    $tablesStmt->execute([$dbName]);
    $tables = $tablesStmt->fetchAll();
    
    // Get all columns with details
    $columnsStmt = $pdo->prepare("
        SELECT 
            TABLE_NAME,
            COLUMN_NAME,
            COLUMN_TYPE,
            IS_NULLABLE,
            COLUMN_KEY,
            COLUMN_DEFAULT,
            EXTRA,
            DATA_TYPE,
            CHARACTER_MAXIMUM_LENGTH,
            NUMERIC_PRECISION,
            NUMERIC_SCALE
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = ?
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ");
    $columnsStmt->execute([$dbName]);
    $allColumns = $columnsStmt->fetchAll();
    
    // Group columns by table
    $columnsByTable = [];
    foreach ($allColumns as $col) {
        $tableName = $col['TABLE_NAME'];
        if (!isset($columnsByTable[$tableName])) {
            $columnsByTable[$tableName] = [];
        }
        $columnsByTable[$tableName][] = $col;
    }
    
    // Build output structure
    $output = [
        'database' => $dbName,
        'generated_at' => date('Y-m-d H:i:s T'),
        'table_count' => count($tables),
        'tables' => []
    ];
    
    foreach ($tables as $table) {
        $tableName = $table['TABLE_NAME'];
        $tableColumns = $columnsByTable[$tableName] ?? [];
        
        $columnsOutput = [];
        foreach ($tableColumns as $col) {
            $columnsOutput[$col['COLUMN_NAME']] = [
                'type' => $col['COLUMN_TYPE'],
                'nullable' => $col['IS_NULLABLE'] === 'YES',
                'primary' => $col['COLUMN_KEY'] === 'PRI',
                'unique' => $col['COLUMN_KEY'] === 'UNI',
                'default' => $col['COLUMN_DEFAULT'],
                'auto_increment' => strpos($col['EXTRA'], 'auto_increment') !== false,
                'extra' => $col['EXTRA'] ?: null
            ];
        }
        
        $output['tables'][$tableName] = [
            'columns' => $columnsOutput,
            'column_count' => count($columnsOutput)
        ];
    }
    
    // Output JSON
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Schema introspection failed',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}

exit;
