-- Migration: Add email-specific features for batch sending
-- Run this migration to add email limits, retry tracking, and attachment support

-- Email sending limits per user
CREATE TABLE IF NOT EXISTS `email_limits` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL UNIQUE,
    `sent_last_hour` INT UNSIGNED DEFAULT 0,
    `sent_today` INT UNSIGNED DEFAULT 0,
    `hourly_limit` INT UNSIGNED DEFAULT 100,
    `daily_limit` INT UNSIGNED DEFAULT 1000,
    `last_reset_hour` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `last_reset_day` DATE DEFAULT (CURRENT_DATE),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sender IDs management table
CREATE TABLE IF NOT EXISTS `sender_ids` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `type` ENUM('sms', 'email') NOT NULL,
    `sender_id` VARCHAR(20) NULL COMMENT 'For SMS: alphanumeric sender ID',
    `sender_email` VARCHAR(255) NULL COMMENT 'For Email: reply-to email',
    `sender_name` VARCHAR(100) NULL COMMENT 'Display name',
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `is_default` TINYINT(1) DEFAULT 0,
    `verified_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_status` (`status`),
    UNIQUE KEY `unique_sender` (`user_id`, `type`, `sender_id`, `sender_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email attachments storage
CREATE TABLE IF NOT EXISTS `email_attachments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `campaign_id` BIGINT UNSIGNED NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_size` INT UNSIGNED NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add retry_count and error_log to messages table
ALTER TABLE `messages` 
ADD COLUMN IF NOT EXISTS `retry_count` TINYINT UNSIGNED DEFAULT 0 AFTER `error_message`,
ADD COLUMN IF NOT EXISTS `attachments` JSON DEFAULT NULL AFTER `content`;

-- Add attachments column to campaigns
ALTER TABLE `campaigns`
ADD COLUMN IF NOT EXISTS `attachments` JSON DEFAULT NULL AFTER `message`;

-- Inbound messages table (for SMS replies)
CREATE TABLE IF NOT EXISTS `inbound_messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `from_number` VARCHAR(20) NOT NULL,
    `to_number` VARCHAR(20) NOT NULL,
    `message` TEXT NOT NULL,
    `carrier` VARCHAR(50) NULL,
    `external_id` VARCHAR(255) NULL,
    `processed` TINYINT(1) DEFAULT 0,
    `raw_payload` JSON NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_from_number` (`from_number`),
    INDEX `idx_external_id` (`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default sender IDs for existing admin
INSERT IGNORE INTO `sender_ids` (`user_id`, `type`, `sender_id`, `sender_name`, `status`, `is_default`, `verified_at`)
SELECT id, 'sms', 'IEOSUIA', 'IEOSUIA Portal', 'approved', 1, NOW() FROM users WHERE role = 'admin' LIMIT 1;

INSERT IGNORE INTO `sender_ids` (`user_id`, `type`, `sender_email`, `sender_name`, `status`, `is_default`, `verified_at`)
SELECT id, 'email', 'noreply@sms.ieosuia.com', 'IEOSUIA Portal', 'approved', 1, NOW() FROM users WHERE role = 'admin' LIMIT 1;
