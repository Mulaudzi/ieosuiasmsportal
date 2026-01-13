-- Migration: Create payments table for tracking all payment transactions
-- This table handles PayFast, Paystack, and Ozow webhooks

CREATE TABLE IF NOT EXISTS `payments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `wallet_id` BIGINT UNSIGNED NOT NULL,
    `transaction_id` BIGINT UNSIGNED NULL COMMENT 'Reference to wallet_transactions',
    `gateway` ENUM('payfast', 'paystack', 'ozow', 'eft') NOT NULL,
    `gateway_reference` VARCHAR(255) NULL COMMENT 'Payment ID from gateway',
    `merchant_reference` VARCHAR(100) NOT NULL COMMENT 'Our internal reference',
    `amount` DECIMAL(10, 2) NOT NULL,
    `currency` VARCHAR(3) DEFAULT 'ZAR',
    `status` ENUM('pending', 'completed', 'failed', 'cancelled', 'refunded') DEFAULT 'pending',
    `gateway_status` VARCHAR(50) NULL COMMENT 'Raw status from gateway',
    `payment_method` VARCHAR(50) NULL COMMENT 'Card, EFT, etc.',
    `payer_email` VARCHAR(255) NULL,
    `payer_name` VARCHAR(255) NULL,
    `credits_added` INT UNSIGNED DEFAULT 0 COMMENT 'SMS credits added from this payment',
    `metadata` JSON NULL COMMENT 'Additional gateway response data',
    `webhook_received_at` TIMESTAMP NULL,
    `processed_at` TIMESTAMP NULL,
    `error_message` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`wallet_id`) REFERENCES `wallets`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`transaction_id`) REFERENCES `wallet_transactions`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_gateway` (`gateway`),
    INDEX `idx_status` (`status`),
    INDEX `idx_merchant_reference` (`merchant_reference`),
    INDEX `idx_gateway_reference` (`gateway_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
