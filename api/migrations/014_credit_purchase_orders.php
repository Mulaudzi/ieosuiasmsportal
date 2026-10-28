<?php

return static function(PDO $pdo): void {
    // Older installations sent receipts directly and may lack the durable outbox.
    // Match the actual legacy INT payment ID, rather than the obsolete BIGINT definition.
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_receipt_outbox (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        payment_id INT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME NOT NULL,
        locked_at DATETIME NULL,
        sent_at DATETIME NULL,
        last_error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uniq_payment_receipt_payment (payment_id),
        KEY idx_payment_receipt_due (status,available_at),
        CONSTRAINT fk_payment_receipt_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
        CONSTRAINT fk_payment_receipt_user FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_purchase_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        payment_id INT UNSIGNED NOT NULL,
        reference VARCHAR(100) NOT NULL,
        idempotency_key VARCHAR(80) NOT NULL,
        pricing_version VARCHAR(60) NOT NULL,
        credits INT UNSIGNED NOT NULL,
        unit_cents INT UNSIGNED NOT NULL,
        subtotal_cents BIGINT UNSIGNED NOT NULL,
        discount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
        total_cents BIGINT UNSIGNED NOT NULL,
        wallet_amount DECIMAL(14,4) NOT NULL,
        payment_method VARCHAR(20) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'awaiting_payment',
        bank_details_json TEXT NULL,
        checkout_url TEXT NULL,
        bank_reference VARCHAR(120) NULL,
        reviewed_by BIGINT UNSIGNED NULL,
        review_note VARCHAR(1000) NULL,
        reviewed_at DATETIME NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uniq_credit_order_reference(reference),
        UNIQUE KEY uniq_credit_order_payment(payment_id),
        UNIQUE KEY uniq_credit_order_request(user_id,idempotency_key),
        UNIQUE KEY uniq_credit_order_bank_reference(bank_reference),
        KEY idx_credit_order_review(payment_method,status,created_at),
        KEY idx_credit_order_user(user_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Documents stay private in the database, never in a public uploads directory.
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_payment_proofs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        mime_type VARCHAR(40) NOT NULL,
        byte_size INT UNSIGNED NOT NULL,
        sha256 CHAR(64) NOT NULL,
        document MEDIUMBLOB NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_credit_proof(order_id,sha256)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_purchase_mail_outbox (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_key VARCHAR(160) NOT NULL UNIQUE,
        recipient VARCHAR(254) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        html TEXT NOT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME NOT NULL,
        locked_at DATETIME NULL,
        lock_token CHAR(32) NULL,
        sent_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        KEY idx_credit_mail_due(sent_at,available_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
