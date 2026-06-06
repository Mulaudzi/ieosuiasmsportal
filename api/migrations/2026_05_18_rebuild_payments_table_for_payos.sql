-- Rebuild the payments table for the PayOS/EFT-only flow.
-- This intentionally deletes old payment history.

DROP TABLE IF EXISTS payments;

CREATE TABLE payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    wallet_id INT UNSIGNED NOT NULL,
    transaction_id INT UNSIGNED NULL,
    gateway VARCHAR(50) NOT NULL,
    gateway_reference VARCHAR(100) NULL,
    merchant_reference VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'ZAR',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    gateway_status VARCHAR(50) NULL,
    payment_method VARCHAR(50) NULL,
    payer_email VARCHAR(255) NULL,
    payer_name VARCHAR(255) NULL,
    credits_added INT NOT NULL DEFAULT 0,
    metadata LONGTEXT NULL,
    webhook_received_at TIMESTAMP NULL DEFAULT NULL,
    processed_at TIMESTAMP NULL DEFAULT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_payments_gateway_reference (gateway, gateway_reference),
    KEY idx_payments_user_id (user_id),
    KEY idx_payments_wallet_id (wallet_id),
    KEY idx_payments_transaction_id (transaction_id),
    KEY idx_payments_merchant_reference (merchant_reference),
    KEY idx_payments_user_status (user_id, status),
    KEY idx_payments_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;