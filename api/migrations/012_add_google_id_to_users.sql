-- Add google_id column to users table for OAuth
ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL AFTER email;
ALTER TABLE users ADD INDEX idx_users_google_id (google_id);
