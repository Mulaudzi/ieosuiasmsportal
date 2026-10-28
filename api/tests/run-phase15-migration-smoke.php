<?php

declare(strict_types=1);

/**
 * Phase 1.5 migration smoke test.
 *
 * This script only targets an operator-supplied disposable database. It does
 * not create or drop databases and refuses non-local hosts unless the caller
 * explicitly opts in. Secret values are never printed.
 *
 * Required environment variables:
 *   PHASE15_DB_HOST, PHASE15_DB_PORT, PHASE15_DB_NAME,
 *   PHASE15_DB_USER, PHASE15_DB_PASSWORD
 */

$host = trim((string) getenv('PHASE15_DB_HOST'));
$port = (int) (getenv('PHASE15_DB_PORT') ?: 3306);
$database = trim((string) getenv('PHASE15_DB_NAME'));
$username = (string) getenv('PHASE15_DB_USER');
$password = (string) getenv('PHASE15_DB_PASSWORD');
$allowRemote = getenv('PHASE15_ALLOW_REMOTE_FIXTURE') === '1';

if ($host === '' || $database === '' || $username === '') {
    fwrite(STDERR, "Missing Phase 1.5 fixture database configuration.\n");
    exit(64);
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
    fwrite(STDERR, "Fixture database name is invalid.\n");
    exit(64);
}
if (!$allowRemote && !in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing a non-local fixture database without PHASE15_ALLOW_REMOTE_FIXTURE=1.\n");
    exit(64);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

require_once dirname(__DIR__) . '/migrations/Migration.php';
$migration = require dirname(__DIR__) . '/migrations/018_phase1_sms_integrity.php';

// Applying twice proves the PHP migration helper keeps this migration
// idempotent on the disposable schema.
$migration($pdo);
$migration($pdo);

$columnStatement = $pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE()
       AND TABLE_NAME='sms_campaigns'
       AND COLUMN_NAME IN ('request_fingerprint','quota_reserved_at')"
);
$tableStatement = $pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sms_daily_quota_usage'"
);
$foreignKeyStatement = $pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA=DATABASE()
       AND TABLE_NAME='sms_daily_quota_usage'
       AND CONSTRAINT_NAME='fk_sms_daily_quota_user'"
);

if ((int) $columnStatement->fetchColumn() !== 2
    || (int) $tableStatement->fetchColumn() !== 1
    || (int) $foreignKeyStatement->fetchColumn() !== 1) {
    fwrite(STDERR, "SMS Phase 1.5 migration smoke test failed.\n");
    exit(1);
}

echo "SMS Phase 1.5 migration smoke test passed (MariaDB/MySQL fixture; replay safe).\n";
