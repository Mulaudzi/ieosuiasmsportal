-- IEOSUIA SMS Portal - Admin Features Migration
-- Migration: 017_add_admin_features.sql
-- Adds indexes for better audit log performance and ensures proper notification structure

-- Add index for faster audit log queries by date
CREATE INDEX IF NOT EXISTS `idx_audit_logs_created_at` ON `audit_logs` (`created_at`);

-- Add index for audit log action filtering
CREATE INDEX IF NOT EXISTS `idx_audit_logs_action` ON `audit_logs` (`action`);

-- Add index for audit log entity filtering
CREATE INDEX IF NOT EXISTS `idx_audit_logs_entity` ON `audit_logs` (`entity_type`, `entity_id`);

-- Add notification type index if not exists
CREATE INDEX IF NOT EXISTS `idx_notifications_type` ON `notifications` (`type`);

-- Add notification user_id and is_read index for better filtering
CREATE INDEX IF NOT EXISTS `idx_notifications_user_read` ON `notifications` (`user_id`, `is_read`);

-- Ensure the notifications table has all needed columns
-- Check if 'data' column exists, if not add it
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'notifications' 
               AND COLUMN_NAME = 'data');
SET @query := IF(@exist = 0, 
    'ALTER TABLE notifications ADD COLUMN data JSON NULL AFTER message',
    'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
