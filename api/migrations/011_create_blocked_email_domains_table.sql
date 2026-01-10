-- Create blocked email domains table for disposable and role-based email blocking
CREATE TABLE IF NOT EXISTS blocked_email_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    type ENUM('disposable', 'role') DEFAULT 'disposable',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create index for faster lookups
CREATE INDEX idx_blocked_domains_type ON blocked_email_domains(type);
