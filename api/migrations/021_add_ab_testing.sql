-- A/B Testing for Campaigns
-- Allows creating multiple message variants per campaign

-- Add A/B testing fields to campaigns
ALTER TABLE campaigns
ADD COLUMN is_ab_test TINYINT(1) DEFAULT 0,
ADD COLUMN ab_test_split_percent INT DEFAULT 50 COMMENT 'Percentage of recipients for variant A',
ADD COLUMN ab_winner_variant CHAR(1) DEFAULT NULL COMMENT 'A or B - the winning variant',
ADD COLUMN ab_winner_selected_at DATETIME DEFAULT NULL;

-- Campaign variants table
CREATE TABLE IF NOT EXISTS campaign_variants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    variant_name CHAR(1) NOT NULL COMMENT 'A or B',
    message_content TEXT NOT NULL,
    subject VARCHAR(255) DEFAULT NULL COMMENT 'For email campaigns',
    recipient_count INT DEFAULT 0,
    sent_count INT DEFAULT 0,
    delivered_count INT DEFAULT 0,
    failed_count INT DEFAULT 0,
    delivery_rate DECIMAL(5,2) DEFAULT 0.00,
    is_winner TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    UNIQUE KEY unique_campaign_variant (campaign_id, variant_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add variant reference to messages
ALTER TABLE messages
ADD COLUMN variant_name CHAR(1) DEFAULT NULL COMMENT 'A or B for A/B test messages';

-- Index for faster variant queries
CREATE INDEX idx_messages_variant ON messages(campaign_id, variant_name);
CREATE INDEX idx_campaign_variants_campaign ON campaign_variants(campaign_id);
