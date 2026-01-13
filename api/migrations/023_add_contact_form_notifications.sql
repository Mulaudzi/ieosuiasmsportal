-- Migration: Add contact form notification settings and alert recipients
-- Adds ability to configure which email addresses receive instant alerts for new contact submissions

-- Add contact form submission to notification settings
INSERT IGNORE INTO `admin_notification_settings` (`event_type`, `event_label`, `event_description`, `is_enabled`, `notify_email`, `notify_inapp`) VALUES
    ('new_contact_submission', 'New Contact Form Submission', 'When a visitor submits the contact form', 1, 1, 1);

-- Create table for contact form alert recipients (additional emails besides admins)
CREATE TABLE IF NOT EXISTS `contact_alert_recipients` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255) NULL,
    `purpose` ENUM('all', 'general', 'support', 'sales') NOT NULL DEFAULT 'all',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_email_purpose` (`email`, `purpose`),
    INDEX `idx_active_purpose` (`is_active`, `purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create table for real-time notification queue (for SSE/polling)
CREATE TABLE IF NOT EXISTS `realtime_notifications` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel` VARCHAR(100) NOT NULL DEFAULT 'admin',
    `type` VARCHAR(100) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NULL,
    `data` JSON NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_channel_created` (`channel`, `created_at`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auto-cleanup old realtime notifications (keep last 24 hours)
-- This should be run by cron periodically
-- DELETE FROM realtime_notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
