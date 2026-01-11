-- Migration: Add SMTP settings management for admin
-- Allows configuring SMTP settings from dashboard without editing .env

CREATE TABLE IF NOT EXISTS `smtp_settings` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_type` ENUM('system', 'campaign') NOT NULL COMMENT 'system = noreply for verification/reset, campaign = email campaigns',
    `host` VARCHAR(255) NOT NULL,
    `port` INT UNSIGNED NOT NULL DEFAULT 465,
    `encryption` ENUM('none', 'ssl', 'tls') DEFAULT 'ssl',
    `username` VARCHAR(255) NOT NULL,
    `password` VARCHAR(500) NOT NULL COMMENT 'Encrypted password',
    `from_email` VARCHAR(255) NOT NULL,
    `from_name` VARCHAR(100) NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `last_tested_at` TIMESTAMP NULL,
    `last_test_result` ENUM('success', 'failed') NULL,
    `last_test_error` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_setting_type` (`setting_type`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings from environment (will use env values as fallback)
-- System email: for verification, password reset
INSERT IGNORE INTO `smtp_settings` (`setting_type`, `host`, `port`, `encryption`, `username`, `password`, `from_email`, `from_name`)
VALUES ('system', 'sms.ieosuia.com', 465, 'ssl', 'noreply@sms.ieosuia.com', '', 'noreply@sms.ieosuia.com', 'IEOSUIA SMS Portal');

-- Campaign email: for email campaigns
INSERT IGNORE INTO `smtp_settings` (`setting_type`, `host`, `port`, `encryption`, `username`, `password`, `from_email`, `from_name`)
VALUES ('campaign', 'sms.ieosuia.com', 465, 'ssl', 'email@sms.ieosuia.com', '', 'email@sms.ieosuia.com', 'IEOSUIA Portal');
