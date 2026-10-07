CREATE TABLE IF NOT EXISTS purchase_alert_settings (
 id TINYINT UNSIGNED PRIMARY KEY,
 enabled_at DATETIME NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO purchase_alert_settings(id,enabled_at) VALUES(1,NOW());
CREATE TABLE IF NOT EXISTS purchase_alert_outbox (
 payment_id BIGINT UNSIGNED PRIMARY KEY,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 available_at DATETIME NOT NULL,
 locked_at DATETIME NULL,
 lock_token VARCHAR(64) NULL,
 snapshot_json LONGTEXT NULL,
 sent_at DATETIME NULL,
 last_error VARCHAR(255) NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 INDEX purchase_alert_due(status,available_at)
) ENGINE=InnoDB;
