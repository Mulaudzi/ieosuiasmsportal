-- Add subscription status to contacts table for opt-in/opt-out tracking
-- Also adds custom fields for enhanced personalization

ALTER TABLE contacts 
ADD COLUMN subscription_status ENUM('subscribed', 'unsubscribed', 'pending') DEFAULT 'subscribed' AFTER is_active,
ADD COLUMN surname VARCHAR(255) NULL AFTER name,
ADD COLUMN country_code VARCHAR(5) DEFAULT '+27' AFTER email,
ADD COLUMN subscribed_at TIMESTAMP NULL AFTER subscription_status,
ADD COLUMN unsubscribed_at TIMESTAMP NULL AFTER subscribed_at;

-- Add index for filtering by subscription status
ALTER TABLE contacts ADD INDEX idx_subscription_status (subscription_status);

-- Create inbound_messages table for storing incoming SMS
CREATE TABLE IF NOT EXISTS `inbound_messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `from_number` VARCHAR(20) NOT NULL,
    `to_number` VARCHAR(20) NOT NULL,
    `message` TEXT NOT NULL,
    `external_id` VARCHAR(255) NULL,
    `keyword` VARCHAR(50) NULL,
    `processed` TINYINT(1) DEFAULT 0,
    `raw_payload` JSON NULL,
    `received_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_from_number (`from_number`),
    INDEX idx_keyword (`keyword`),
    INDEX idx_received_at (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create sender_ids table for managing sender IDs
CREATE TABLE IF NOT EXISTS `sender_ids` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `sender_id` VARCHAR(20) NOT NULL,
    `type` ENUM('alpha', 'numeric', 'shortcode') DEFAULT 'alpha',
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'approved',
    `is_default` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_user_sender` (`user_id`, `sender_id`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default sender ID for existing users
INSERT INTO sender_ids (user_id, sender_id, type, is_default)
SELECT id, 'IEOSUIA', 'alpha', 1 FROM users WHERE NOT EXISTS (
    SELECT 1 FROM sender_ids WHERE user_id = users.id
);
