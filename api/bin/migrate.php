<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../migrations/Migration.php';

$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(__DIR__ . '/../migrations/[0-9][0-9][0-9]_*.php');
sort($files, SORT_STRING);

foreach ($files as $file) {
    $version = basename($file, '.php');
    if (in_array($version, $applied, true)) {
        echo "skip {$version}\n";
        continue;
    }
    $migration = require $file;
    $transactional = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql';
    if ($transactional) $pdo->beginTransaction();
    try {
        $migration($pdo);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, NOW())');
        $stmt->execute([$version]);
        if ($transactional && $pdo->inTransaction()) $pdo->commit();
        echo "applied {$version}\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "failed {$version}: {$e->getMessage()}\n");
        exit(1);
    }
}
