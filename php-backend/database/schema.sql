-- IEOSUIA SMS Portal - MySQL Database Schema
-- Version: 1.0.0
-- PHP 8.4 / Laravel 11+ / MySQL 8+

-- Create database
CREATE DATABASE IF NOT EXISTS smsportal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smsportal;

-- =====================================================
-- ENUMS (MySQL doesn't have native enums like PostgreSQL, using ENUM type)
-- =====================================================

-- =====================================================
-- USERS & AUTHENTICATION
-- =====================================================

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP NULL,
    password VARCHAR(255) NOT NULL,
    account_type ENUM('Individual', 'Business', 'Organization') DEFAULT 'Individual',
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_users_email (email),
    INDEX idx_users_account_type (account_type)
) ENGINE=InnoDB;

-- User roles table (separate for security - prevents privilege escalation)
CREATE TABLE user_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('admin', 'moderator', 'user') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_role (user_id, role),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Business/Organization accounts
CREATE TABLE accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    company_name VARCHAR(255) NULL,
    registration_number VARCHAR(100) NULL,
    vat_number VARCHAR(50) NULL,
    address TEXT NULL,
    city VARCHAR(100) NULL,
    province VARCHAR(100) NULL,
    postal_code VARCHAR(20) NULL,
    country VARCHAR(100) DEFAULT 'South Africa',
    phone VARCHAR(20) NULL,
    logo VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- API tokens for authentication
CREATE TABLE personal_access_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tokenable_type VARCHAR(255) NOT NULL,
    tokenable_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    abilities TEXT NULL,
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tokenable (tokenable_type, tokenable_id)
) ENGINE=InnoDB;

-- =====================================================
-- CONTACTS
-- =====================================================

CREATE TABLE contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    phone VARCHAR(20) NULL,
    email VARCHAR(255) NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    custom_field_1 VARCHAR(255) NULL,
    custom_field_2 VARCHAR(255) NULL,
    custom_field_3 VARCHAR(255) NULL,
    opt_out BOOLEAN DEFAULT FALSE,
    opt_out_date TIMESTAMP NULL,
    source ENUM('import', 'manual', 'api', 'form') DEFAULT 'manual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_contacts_user (user_id),
    INDEX idx_contacts_phone (phone),
    INDEX idx_contacts_email (email),
    INDEX idx_contacts_opt_out (opt_out),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE contact_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_groups_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE group_contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_group_contact (group_id, contact_id),
    FOREIGN KEY (group_id) REFERENCES contact_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- TEMPLATES
-- =====================================================

CREATE TABLE templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    channel ENUM('sms', 'email') NOT NULL,
    subject VARCHAR(255) NULL, -- For email templates
    content TEXT NOT NULL,
    variables JSON NULL, -- Store available merge fields
    is_default BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_templates_user (user_id),
    INDEX idx_templates_channel (channel),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- CAMPAIGNS
-- =====================================================

CREATE TABLE campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    channel ENUM('sms', 'email') NOT NULL,
    status ENUM('Draft', 'Pending', 'Queued', 'Processing', 'Sent', 'Completed', 'Failed', 'Cancelled') DEFAULT 'Draft',
    sender_id VARCHAR(20) NULL, -- For SMS sender ID
    from_email VARCHAR(255) NULL, -- For email campaigns
    from_name VARCHAR(255) NULL,
    subject VARCHAR(255) NULL, -- For email campaigns
    content TEXT NOT NULL,
    html_content TEXT NULL, -- For email HTML
    template_id BIGINT UNSIGNED NULL,
    schedule_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    total_recipients INT UNSIGNED DEFAULT 0,
    estimated_cost DECIMAL(10, 2) DEFAULT 0.00,
    actual_cost DECIMAL(10, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_campaigns_user (user_id),
    INDEX idx_campaigns_status (status),
    INDEX idx_campaigns_channel (channel),
    INDEX idx_campaigns_schedule (schedule_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE campaign_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY unique_campaign_group (campaign_id, group_id),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES contact_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- MESSAGES
-- =====================================================

CREATE TABLE messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    recipient VARCHAR(255) NOT NULL, -- Phone or email
    content TEXT NOT NULL,
    status ENUM('Pending', 'Queued', 'Sent', 'Awaiting DLR', 'Delivered', 'Failed', 'Rejected', 'Opted-Out', 'Expired') DEFAULT 'Pending',
    external_id VARCHAR(255) NULL, -- Gateway message ID
    gateway VARCHAR(50) NULL, -- e.g., 'logicsms', 'bulksms'
    cost DECIMAL(8, 4) DEFAULT 0.0000,
    parts INT UNSIGNED DEFAULT 1, -- SMS parts count
    error_code VARCHAR(50) NULL,
    error_message TEXT NULL,
    sent_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_messages_campaign (campaign_id),
    INDEX idx_messages_status (status),
    INDEX idx_messages_recipient (recipient),
    INDEX idx_messages_external_id (external_id),
    INDEX idx_messages_sent_at (sent_at),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- DELIVERY REPORTS (DLR)
-- =====================================================

CREATE TABLE dlr_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    external_id VARCHAR(255) NULL,
    status VARCHAR(50) NOT NULL,
    status_code VARCHAR(20) NULL,
    gateway VARCHAR(50) NULL,
    raw_payload JSON NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dlr_message (message_id),
    INDEX idx_dlr_external_id (external_id),
    INDEX idx_dlr_received (received_at),
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- WALLET & TRANSACTIONS
-- =====================================================

CREATE TABLE wallets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    balance DECIMAL(12, 2) DEFAULT 0.00,
    reserved DECIMAL(12, 2) DEFAULT 0.00, -- Reserved for pending campaigns
    currency VARCHAR(3) DEFAULT 'ZAR',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE wallet_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wallet_id BIGINT UNSIGNED NOT NULL,
    type ENUM('credit', 'debit', 'reserve', 'release', 'refund') NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    balance_after DECIMAL(12, 2) NOT NULL,
    description VARCHAR(255) NULL,
    reference VARCHAR(100) NULL, -- Payment reference
    campaign_id BIGINT UNSIGNED NULL,
    payment_method VARCHAR(50) NULL, -- 'payfast', 'ozow', 'eft'
    payment_status ENUM('pending', 'completed', 'failed', 'cancelled') DEFAULT 'completed',
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transactions_wallet (wallet_id),
    INDEX idx_transactions_type (type),
    INDEX idx_transactions_reference (reference),
    INDEX idx_transactions_created (created_at),
    FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- OPT-OUTS
-- =====================================================

CREATE TABLE opt_outs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    channel ENUM('sms', 'email', 'all') DEFAULT 'all',
    reason TEXT NULL,
    source ENUM('user_request', 'reply_stop', 'bounce', 'complaint', 'manual') DEFAULT 'manual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_recipient (user_id, recipient),
    INDEX idx_optouts_recipient (recipient),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- SETTINGS
-- =====================================================

CREATE TABLE user_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    default_sender_id VARCHAR(20) NULL,
    default_from_email VARCHAR(255) NULL,
    default_from_name VARCHAR(255) NULL,
    timezone VARCHAR(50) DEFAULT 'Africa/Johannesburg',
    notification_email BOOLEAN DEFAULT TRUE,
    notification_sms BOOLEAN DEFAULT FALSE,
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    api_enabled BOOLEAN DEFAULT FALSE,
    api_key VARCHAR(64) NULL,
    webhook_url VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- QUEUE JOBS (for batch processing)
-- =====================================================

CREATE TABLE jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reserved_at INT UNSIGNED NULL,
    available_at INT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    INDEX idx_jobs_queue (queue)
) ENGINE=InnoDB;

CREATE TABLE failed_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(255) NOT NULL UNIQUE,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload LONGTEXT NOT NULL,
    exception LONGTEXT NOT NULL,
    failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================
-- AUDIT LOG
-- =====================================================

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id BIGINT UNSIGNED NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- SEED DATA
-- =====================================================

-- Default admin user (password: Admin@123456)
INSERT INTO users (name, email, password, account_type, email_verified_at) VALUES
('Admin User', 'admin@ieosuia.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Organization', NOW());

-- Assign admin role
INSERT INTO user_roles (user_id, role) VALUES (1, 'admin');

-- Create wallet for admin
INSERT INTO wallets (user_id, balance) VALUES (1, 1000.00);

-- Default settings
INSERT INTO user_settings (user_id, default_sender_id, timezone) VALUES (1, 'IEOSUIA', 'Africa/Johannesburg');

-- Sample contact groups
INSERT INTO contact_groups (user_id, name, description) VALUES
(1, 'All Contacts', 'Default group for all contacts'),
(1, 'VIP Customers', 'High-value customers'),
(1, 'Newsletter', 'Newsletter subscribers');

-- Sample templates
INSERT INTO templates (user_id, name, channel, content) VALUES
(1, 'Welcome SMS', 'sms', 'Welcome to IEOSUIA! Thank you for joining us. Reply STOP to opt out.'),
(1, 'Reminder', 'sms', 'Hi {first_name}, this is a friendly reminder about {custom_field_1}. Reply STOP to opt out.');
