-- IEOSUIA SMS Portal - MySQL Schema
-- Raw PHP Backend

SET FOREIGN_KEY_CHECKS = 0;

-- Users table
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(20) NULL,
    `company` VARCHAR(255) NULL,
    `avatar_url` VARCHAR(500) NULL,
    `logo_url` VARCHAR(500) NULL,
    `role` ENUM('admin', 'user', 'moderator') DEFAULT 'user',
    `account_type` ENUM('individual', 'business', 'organization', 'standard') DEFAULT 'standard',
    `email_verified_at` TIMESTAMP NULL,
    `email_verification_token` VARCHAR(64) NULL,
    `email_verification_sent_at` TIMESTAMP NULL,
    `otp_code` VARCHAR(6) NULL,
    `otp_expires_at` TIMESTAMP NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `last_login_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`),
    INDEX `idx_role` (`role`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_email_verification_token` (`email_verification_token`),
    INDEX `idx_otp_code` (`otp_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wallets table
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

-- Wallet transactions table
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

-- Contact groups table
CREATE TABLE IF NOT EXISTS `contact_groups` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contacts table
CREATE TABLE IF NOT EXISTS `contacts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `group_id` BIGINT UNSIGNED NULL,
    `name` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(20) NULL,
    `email` VARCHAR(255) NULL,
    `custom_fields` JSON NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`group_id`) REFERENCES `contact_groups`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_group_id` (`group_id`),
    INDEX `idx_phone` (`phone`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Templates table
CREATE TABLE IF NOT EXISTS `templates` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `type` ENUM('sms', 'email') DEFAULT 'sms',
    `content` TEXT NOT NULL,
    `subject` VARCHAR(255) NULL,
    `variables` JSON NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Campaigns table
CREATE TABLE IF NOT EXISTS `campaigns` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `type` ENUM('sms', 'email') DEFAULT 'sms',
    `status` ENUM('draft', 'scheduled', 'processing', 'sending', 'completed', 'paused', 'failed') DEFAULT 'draft',
    `message` TEXT NOT NULL,
    `subject` VARCHAR(255) NULL,
    `sender_id` VARCHAR(20) NULL,
    `sender_email` VARCHAR(255) NULL,
    `template_id` BIGINT UNSIGNED NULL,
    `group_id` BIGINT UNSIGNED NULL,
    `recipients` JSON NULL,
    `total_recipients` INT UNSIGNED DEFAULT 0,
    `sent_count` INT UNSIGNED DEFAULT 0,
    `delivered_count` INT UNSIGNED DEFAULT 0,
    `failed_count` INT UNSIGNED DEFAULT 0,
    `total_cost` DECIMAL(12, 2) DEFAULT 0.00,
    `scheduled_at` TIMESTAMP NULL,
    `started_at` TIMESTAMP NULL,
    `completed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`template_id`) REFERENCES `templates`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`group_id`) REFERENCES `contact_groups`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_type` (`type`),
    INDEX `idx_scheduled_at` (`scheduled_at`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages table
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

-- DLR logs table (Delivery Reports)
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

-- Opt-outs table
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

-- User settings table
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

-- Audit logs table
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NULL,
    `action` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` BIGINT UNSIGNED NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Default admin user (password: admin123)
INSERT INTO `users` (`name`, `email`, `password`, `role`, `is_active`, `email_verified_at`) VALUES
('Admin', 'admin@ieosuia.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW());

-- Create wallet for admin
INSERT INTO `wallets` (`user_id`, `balance`, `currency`) VALUES
(1, 100.00, 'USD');

-- Create settings for admin
INSERT INTO `user_settings` (`user_id`, `default_sender_id`, `timezone`) VALUES
(1, 'IEOSUIA', 'UTC');
