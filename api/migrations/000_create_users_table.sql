-- IEOSUIA SMS Portal - Users Table Migration
-- Migration: 000_create_users_table.sql

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
