<?php

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_approval_security (
        id TINYINT UNSIGNED PRIMARY KEY,
        password_hash VARCHAR(255) NULL,
        version INT UNSIGNED NOT NULL DEFAULT 0,
        updated_by BIGINT UNSIGNED NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec('INSERT IGNORE INTO credit_approval_security(id,version) VALUES(1,0)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_approval_attempts (
        user_id BIGINT UNSIGNED PRIMARY KEY,
        failures INT UNSIGNED NOT NULL DEFAULT 0,
        window_started BIGINT UNSIGNED NOT NULL,
        blocked_until BIGINT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_approval_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        event VARCHAR(40) NOT NULL,
        order_id BIGINT UNSIGNED NULL,
        security_version INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        KEY idx_credit_approval_actor(user_id,created_at),
        KEY idx_credit_approval_order(order_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
