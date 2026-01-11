-- Add Telnyx-specific columns to messages table

ALTER TABLE messages 
ADD COLUMN telnyx_id VARCHAR(255) NULL AFTER external_id,
ADD COLUMN carrier VARCHAR(100) NULL AFTER parts,
ADD COLUMN from_number VARCHAR(20) NULL AFTER recipient;

-- Add index for Telnyx ID
ALTER TABLE messages ADD INDEX idx_telnyx_id (telnyx_id);

-- Update user_settings for Telnyx configuration
ALTER TABLE user_settings 
MODIFY COLUMN sms_gateway VARCHAR(50) DEFAULT 'telnyx',
ADD COLUMN telnyx_profile_id VARCHAR(255) NULL AFTER gateway_config;
