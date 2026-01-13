-- Admin Users Table
-- Stores admin credentials with 3 hashed passwords for enhanced security

CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_1 VARCHAR(255) NOT NULL COMMENT 'Bcrypt hashed first password',
    password_2 VARCHAR(255) NOT NULL COMMENT 'Bcrypt hashed second password',
    password_3 VARCHAR(255) NOT NULL COMMENT 'Bcrypt hashed third password',
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
