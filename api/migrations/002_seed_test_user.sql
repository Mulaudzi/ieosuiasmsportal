-- IEOSUIA SMS Portal - Test User Seed
-- Run this to create a test user

-- Password: 123456789 (bcrypt hash)
-- Note: This hash was generated with PHP's password_hash('123456789', PASSWORD_DEFAULT)

INSERT INTO `users` (
    `name`,
    `email`,
    `password`,
    `phone`,
    `role`,
    `account_type`,
    `email_verified_at`,
    `is_active`,
    `created_at`
) VALUES (
    'Test User',
    'test@ieosuia.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '+1234567890',
    'admin',
    'standard',
    NOW(),
    1,
    NOW()
) ON DUPLICATE KEY UPDATE
    `password` = VALUES(`password`),
    `email_verified_at` = VALUES(`email_verified_at`);
