-- IEOSUIA SMS Portal - Migrations Tracking Table
-- Migration: 009_create_migrations_table.sql

CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `migration` VARCHAR(255) NOT NULL UNIQUE,
    `batch` INT UNSIGNED NOT NULL,
    `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
