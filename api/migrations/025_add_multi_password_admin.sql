-- Add multiple password columns for admin users
-- Admins require 3 passwords for enhanced security

ALTER TABLE admin_users 
    ADD COLUMN password_2 VARCHAR(255) DEFAULT NULL COMMENT 'Bcrypt hashed second password' AFTER password,
    ADD COLUMN password_3 VARCHAR(255) DEFAULT NULL COMMENT 'Bcrypt hashed third password' AFTER password_2;

-- Rename password to password_1 for clarity
ALTER TABLE admin_users 
    CHANGE COLUMN password password_1 VARCHAR(255) NOT NULL COMMENT 'Bcrypt hashed first password';

-- Update existing admin user to have all 3 passwords
-- Default passwords for existing admin: same as password_1 (should be changed immediately)
UPDATE admin_users SET 
    password_2 = password_1,
    password_3 = password_1
WHERE password_2 IS NULL;
