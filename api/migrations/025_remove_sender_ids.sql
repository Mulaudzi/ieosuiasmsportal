-- Migration: Remove Sender IDs feature
-- This migration removes the sender_ids table and related references

-- Drop the sender_ids table
DROP TABLE IF EXISTS sender_ids;

-- Remove sender_id related columns from other tables (optional - keep for backwards compatibility)
-- ALTER TABLE campaigns DROP COLUMN IF EXISTS sender_id;
-- ALTER TABLE campaigns DROP COLUMN IF EXISTS sender_email;

-- Remove default sender settings from user_settings (optional)
-- ALTER TABLE user_settings DROP COLUMN IF EXISTS default_sender_id;
-- ALTER TABLE user_settings DROP COLUMN IF EXISTS default_sender_email;

-- Note: Run this migration manually in your database after deploying the code changes
-- The frontend and backend code has been updated to no longer use sender IDs
