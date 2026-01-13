-- Admin Users Table
-- Stores admin credentials with hashed passwords for database-driven admin authentication

CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL COMMENT 'Bcrypt hashed password',
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    last_login_ip VARCHAR(45) DEFAULT NULL,
    failed_attempts INT DEFAULT 0,
    locked_until DATETIME DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_admin_email (email),
    INDEX idx_admin_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert initial admin user (password: billionairesMu1@udz!7211018830)
-- This is a bcrypt hash generated with password_hash()
INSERT INTO admin_users (email, password, name, is_active, created_at)
VALUES (
    'godtheson@ieosuia.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'System Administrator',
    1,
    NOW()
) ON DUPLICATE KEY UPDATE updated_at = NOW();
