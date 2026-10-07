-- Run once before enabling the separate campaign-report worker.
-- The activation timestamp deliberately excludes historical campaigns.
CREATE TABLE IF NOT EXISTS campaign_report_settings (
 id TINYINT UNSIGNED PRIMARY KEY,
 enabled_at DATETIME NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO campaign_report_settings(id,enabled_at) VALUES(1,NOW());
CREATE TABLE IF NOT EXISTS campaign_report_outbox (
 campaign_id BIGINT UNSIGNED PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 lock_token VARCHAR(64) NULL,
 locked_at DATETIME NULL,
 sent_at DATETIME NULL,
 last_error VARCHAR(255) NULL,
 created_at DATETIME NOT NULL,
 INDEX campaign_report_due(status,created_at)
) ENGINE=InnoDB;
