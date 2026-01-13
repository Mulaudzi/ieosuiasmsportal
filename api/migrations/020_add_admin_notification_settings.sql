-- IEOSUIA SMS Portal - Admin Notification Settings Migration
-- Migration: 020_add_admin_notification_settings.sql
-- Adds table for configuring which events trigger admin email notifications

CREATE TABLE IF NOT EXISTS `admin_notification_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_type` VARCHAR(100) NOT NULL UNIQUE,
    `event_label` VARCHAR(255) NOT NULL,
    `event_description` TEXT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `notify_email` TINYINT(1) NOT NULL DEFAULT 1,
    `notify_inapp` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default notification settings
INSERT IGNORE INTO `admin_notification_settings` (`event_type`, `event_label`, `event_description`, `is_enabled`, `notify_email`, `notify_inapp`) VALUES
    ('new_user', 'New User Registration', 'When a new user registers on the platform', 1, 1, 1),
    ('new_sender_id', 'New Sender ID Request', 'When a user requests a new sender ID approval', 1, 1, 1),
    ('campaign_failed', 'Campaign Failed', 'When a campaign has failed messages', 1, 1, 1),
    ('high_failure_rate', 'High Failure Rate', 'When a campaign has a high message failure rate', 1, 1, 1),
    ('low_credits', 'Low User Credits', 'When a user\'s credit balance falls below threshold', 0, 0, 1),
    ('large_campaign', 'Large Campaign Sent', 'When a campaign with 1000+ recipients is sent', 0, 0, 1),
    ('scheduled_campaign_sent', 'Scheduled Campaign Processed', 'When a scheduled campaign is processed', 0, 0, 1),
    ('user_deactivated', 'User Deactivated', 'When an admin deactivates a user account', 0, 0, 1);

-- Add index for faster lookups
CREATE INDEX IF NOT EXISTS `idx_admin_notification_event` ON `admin_notification_settings` (`event_type`, `is_enabled`);
