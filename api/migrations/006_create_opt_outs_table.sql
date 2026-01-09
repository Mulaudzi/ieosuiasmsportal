-- IEOSUIA SMS Portal - Opt-Outs Table Migration
-- Migration: 006_create_opt_outs_table.sql

CREATE TABLE IF NOT EXISTS `opt_outs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `recipient` VARCHAR(255) NOT NULL,
    `channel` ENUM('sms', 'email', 'all') DEFAULT 'all',
    `reason` TEXT NULL,
    `source` VARCHAR(50) DEFAULT 'manual',
    `campaign_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `unique_opt_out` (`user_id`, `recipient`, `channel`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_recipient` (`recipient`),
    INDEX `idx_channel` (`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
