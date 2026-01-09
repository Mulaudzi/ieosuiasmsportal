-- IEOSUIA SMS Portal - User Settings Table Migration
-- Migration: 007_create_settings_table.sql

CREATE TABLE IF NOT EXISTS `user_settings` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL UNIQUE,
    `default_sender_id` VARCHAR(20) NULL,
    `default_sender_email` VARCHAR(255) NULL,
    `timezone` VARCHAR(50) DEFAULT 'UTC',
    `notification_email` TINYINT(1) DEFAULT 1,
    `notification_sms` TINYINT(1) DEFAULT 0,
    `api_key` VARCHAR(64) NULL UNIQUE,
    `webhook_url` VARCHAR(500) NULL,
    `sms_gateway` VARCHAR(50) DEFAULT 'twilio',
    `gateway_config` JSON NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_api_key` (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
