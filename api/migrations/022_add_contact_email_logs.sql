-- Migration: Add contact form email logs table
-- Tracks all emails sent via the contact form for admin review

CREATE TABLE IF NOT EXISTS `contact_email_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sender_name` VARCHAR(255) NOT NULL,
    `sender_email` VARCHAR(255) NOT NULL,
    `recipient_email` VARCHAR(255) NOT NULL,
    `purpose` ENUM('general', 'support', 'sales') NOT NULL,
    `subject` VARCHAR(500) NOT NULL,
    `message` TEXT NOT NULL,
    `status` ENUM('sent', 'failed', 'bounced') DEFAULT 'sent',
    `error_message` TEXT NULL,
    `origin_url` VARCHAR(500) NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `confirmation_sent` TINYINT(1) DEFAULT 0,
    `read_by_admin` TINYINT(1) DEFAULT 0,
    `read_at` TIMESTAMP NULL,
    `replied` TINYINT(1) DEFAULT 0,
    `replied_at` TIMESTAMP NULL,
    `replied_by` BIGINT UNSIGNED NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_sender_email` (`sender_email`),
    INDEX `idx_recipient_email` (`recipient_email`),
    INDEX `idx_purpose` (`purpose`),
    INDEX `idx_status` (`status`),
    INDEX `idx_read_by_admin` (`read_by_admin`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
