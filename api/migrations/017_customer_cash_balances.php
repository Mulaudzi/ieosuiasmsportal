<?php
return static function(PDO $pdo):void {
    // Signed integer cents, separate from the SMS wallet's internal valuation.
    // No inferred opening balance or historical repricing.
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_cash_ledger (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL,
        amount_cents BIGINT NOT NULL,
        event_key VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_cash_user(user_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_cash_allocations (
        order_id BIGINT UNSIGNED PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        requested_credits INT UNSIGNED NOT NULL,
        choice VARCHAR(20) NOT NULL,
        applied_cents BIGINT NOT NULL,
        sms_total_cents BIGINT UNSIGNED NOT NULL,
        payment_cents BIGINT UNSIGNED NOT NULL,
        received_cents BIGINT UNSIGNED NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'reserved',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_cash_reserved(user_id,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
