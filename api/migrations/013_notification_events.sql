-- Apply before activating customer notification reconciliation. No financial changes.
CREATE TABLE IF NOT EXISTS notification_events (
 user_id BIGINT UNSIGNED NOT NULL,
 event_key VARCHAR(160) NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY (user_id,event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
