-- IEOSUIA SMS Portal - Test User Seed
-- Seed: 010_seed_test_user.sql
-- Test User: test@ieosuia.com / 123456789

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

-- Create wallet for test user
INSERT INTO `wallets` (`user_id`, `balance`, `currency`)
SELECT id, 100.00, 'USD' FROM `users` WHERE `email` = 'test@ieosuia.com'
ON DUPLICATE KEY UPDATE `balance` = VALUES(`balance`);

-- Create settings for test user
INSERT INTO `user_settings` (`user_id`, `default_sender_id`, `timezone`)
SELECT id, 'IEOSUIA', 'UTC' FROM `users` WHERE `email` = 'test@ieosuia.com'
ON DUPLICATE KEY UPDATE `default_sender_id` = VALUES(`default_sender_id`);
