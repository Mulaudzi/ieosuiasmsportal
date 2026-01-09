-- IEOSUIA SMS Portal - Messages Table Migration
-- Migration: 005_create_messages_table.sql

CREATE TABLE IF NOT EXISTS `messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `campaign_id` BIGINT UNSIGNED NOT NULL,
    `recipient` VARCHAR(255) NOT NULL,
    `content` TEXT NOT NULL,
    `subject` VARCHAR(255) NULL,
    `status` ENUM('pending', 'queued', 'sent', 'delivered', 'failed', 'opted_out') DEFAULT 'pending',
    `external_id` VARCHAR(255) NULL,
    `gateway_response` JSON NULL,
    `cost` DECIMAL(10, 4) DEFAULT 0.0000,
    `parts` TINYINT UNSIGNED DEFAULT 1,
    `sent_at` TIMESTAMP NULL,
    `delivered_at` TIMESTAMP NULL,
    `failed_at` TIMESTAMP NULL,
    `error_message` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_external_id` (`external_id`),
    INDEX `idx_recipient` (`recipient`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dlr_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` BIGINT UNSIGNED NOT NULL,
    `external_id` VARCHAR(255) NULL,
    `status` VARCHAR(50) NOT NULL,
    `raw_payload` JSON NULL,
    `received_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
    INDEX `idx_message_id` (`message_id`),
    INDEX `idx_external_id` (`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
