-- Migration: Create accounts table for business profile data
-- Required by: SettingsController.php

CREATE TABLE IF NOT EXISTS `accounts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL UNIQUE,
    `company_name` VARCHAR(100) NULL,
    `address` VARCHAR(255) NULL,
    `city` VARCHAR(100) NULL,
    `province` VARCHAR(100) NULL,
    `postal_code` VARCHAR(20) NULL,
    `country` VARCHAR(100) NULL DEFAULT 'South Africa',
    `vat_number` VARCHAR(50) NULL,
    `website` VARCHAR(255) NULL,
    `logo_url` VARCHAR(500) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_accounts_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Record migration
INSERT INTO `migrations` (`migration`, `batch`, `executed_at`) 
VALUES ('027_create_accounts_table', 27, NOW())
ON DUPLICATE KEY UPDATE `executed_at` = NOW();
