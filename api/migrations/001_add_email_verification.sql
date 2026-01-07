-- IEOSUIA SMS Portal - Email Verification Migration
-- Run this after the initial schema.sql

-- Add email verification columns to users table
ALTER TABLE `users`
    ADD COLUMN `email_verification_token` VARCHAR(64) NULL AFTER `email_verified_at`,
    ADD COLUMN `email_verification_sent_at` TIMESTAMP NULL AFTER `email_verification_token`,
    ADD COLUMN `otp_code` VARCHAR(6) NULL AFTER `email_verification_sent_at`,
    ADD COLUMN `otp_expires_at` TIMESTAMP NULL AFTER `otp_code`,
    ADD COLUMN `account_type` ENUM('individual', 'business', 'organization', 'standard') DEFAULT 'standard' AFTER `role`;

-- Add indexes for faster lookups
ALTER TABLE `users`
    ADD INDEX `idx_email_verification_token` (`email_verification_token`),
    ADD INDEX `idx_otp_code` (`otp_code`);
