-- IEOSUIA SMS Portal - Wallets Table Migration
-- Migration: 001_create_wallets_table.sql

CREATE TABLE IF NOT EXISTS `wallets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL UNIQUE,
    `balance` DECIMAL(12, 2) DEFAULT 0.00,
    `currency` VARCHAR(3) DEFAULT 'USD',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wallet_transactions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `wallet_id` BIGINT UNSIGNED NOT NULL,
    `amount` DECIMAL(12, 2) NOT NULL,
    `type` ENUM('credit', 'debit', 'refund') DEFAULT 'credit',
    `description` TEXT NULL,
    `reference` VARCHAR(255) NULL,
    `status` ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    `payment_method` VARCHAR(50) NULL,
    `payment_data` JSON NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`wallet_id`) REFERENCES `wallets`(`id`) ON DELETE CASCADE,
    INDEX `idx_wallet_id` (`wallet_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_type` (`type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
