<?php
/**
 * Standalone Database Schema Dump
 * Access: https://sms.ieosuia.com/schema_dump.php
 * DELETE THIS FILE AFTER USE!
 */

header('Content-Type: application/json; charset=utf-8');

// Hardcoded database credentials
$host = 'localhost';
$port = '3306';
$database = 'ejetffbz_sms';
$username = 'ejetffbz_ieosuia';
$password = 'I Am Ieosuia';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Get all tables
    $tables = $pdo->query("
        SELECT TABLE_NAME 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = '$database' 
        AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    $schema = ['tables' => []];
    
    foreach ($tables as $table) {
        $columns = $pdo->query("
            SELECT 
                COLUMN_NAME,
                COLUMN_TYPE,
                IS_NULLABLE,
                COLUMN_KEY,
                COLUMN_DEFAULT,
                EXTRA
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = '$database'
            AND TABLE_NAME = '$table'
            ORDER BY ORDINAL_POSITION
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $schema['tables'][$table] = ['columns' => []];
        
        foreach ($columns as $col) {
            $schema['tables'][$table]['columns'][$col['COLUMN_NAME']] = [
                'type' => $col['COLUMN_TYPE'],
                'nullable' => $col['IS_NULLABLE'] === 'YES',
                'primary' => $col['COLUMN_KEY'] === 'PRI',
                'default' => $col['COLUMN_DEFAULT'],
                'auto_increment' => strpos($col['EXTRA'], 'auto_increment') !== false
            ];
        }
    }
    
    echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
