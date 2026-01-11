-- Migration: Add scheduled campaign processing support
-- Run via cron: * * * * * php /path/to/api/cron/process_scheduled_campaigns.php

-- Add columns to campaigns for better tracking
ALTER TABLE `campaigns`
ADD COLUMN IF NOT EXISTS `processing_started_at` TIMESTAMP NULL AFTER `scheduled_at`,
ADD COLUMN IF NOT EXISTS `actual_cost` DECIMAL(12, 2) DEFAULT 0.00 AFTER `total_cost`,
ADD COLUMN IF NOT EXISTS `estimated_cost` DECIMAL(12, 2) DEFAULT 0.00 AFTER `actual_cost`;

-- Add reserved balance to wallets for scheduled campaigns
ALTER TABLE `wallets`
ADD COLUMN IF NOT EXISTS `reserved` DECIMAL(12, 2) DEFAULT 0.00 AFTER `balance`;

-- Create cron job tracking table
CREATE TABLE IF NOT EXISTS `cron_jobs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `job_name` VARCHAR(100) NOT NULL,
    `last_run_at` TIMESTAMP NULL,
    `next_run_at` TIMESTAMP NULL,
    `status` ENUM('idle', 'running', 'completed', 'failed') DEFAULT 'idle',
    `last_result` JSON NULL,
    `run_count` INT UNSIGNED DEFAULT 0,
    `error_count` INT UNSIGNED DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_job` (`job_name`),
    INDEX `idx_status` (`status`),
    INDEX `idx_next_run` (`next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default cron job record
INSERT IGNORE INTO `cron_jobs` (`job_name`, `status`) VALUES 
('process_scheduled_campaigns', 'idle');

-- Add index for faster scheduled campaign queries
CREATE INDEX IF NOT EXISTS `idx_scheduled_status` ON `campaigns` (`status`, `scheduled_at`);

-- Fix sender_ids table - add sender_name column if not exists
-- This fixes the migration error for 015_add_email_features.sql
ALTER TABLE `sender_ids`
ADD COLUMN IF NOT EXISTS `sender_name` VARCHAR(100) NULL COMMENT 'Display name' AFTER `sender_email`;

-- Now insert default sender IDs (will work after sender_name column exists)
INSERT IGNORE INTO `sender_ids` (`user_id`, `type`, `sender_id`, `sender_name`, `status`, `is_default`, `verified_at`)
SELECT id, 'sms', 'IEOSUIA', 'IEOSUIA Portal', 'approved', 1, NOW() FROM users WHERE role = 'admin' LIMIT 1;

INSERT IGNORE INTO `sender_ids` (`user_id`, `type`, `sender_email`, `sender_name`, `status`, `is_default`, `verified_at`)
SELECT id, 'email', 'noreply@sms.ieosuia.com', 'IEOSUIA Portal', 'approved', 1, NOW() FROM users WHERE role = 'admin' LIMIT 1;
