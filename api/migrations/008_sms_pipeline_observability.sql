ALTER TABLE sms_messages
    ADD COLUMN IF NOT EXISTS retry_generation SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER attempt_count,
    ADD COLUMN IF NOT EXISTS last_dlr_checked_at DATETIME NULL AFTER delivered_at,
    ADD COLUMN IF NOT EXISTS dlr_attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_dlr_checked_at,
    ADD INDEX IF NOT EXISTS idx_sms_dlr_poll (state,last_dlr_checked_at,sent_at);

CREATE TABLE IF NOT EXISTS service_job_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(60) NOT NULL,
    instance_id VARCHAR(120) NULL,
    status ENUM('running','completed','failed','skipped') NOT NULL,
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    message VARCHAR(500) NULL,
    metadata_json LONGTEXT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    duration_ms INT UNSIGNED NULL,
    INDEX idx_service_job_runs_service_started (service_name,started_at),
    INDEX idx_service_job_runs_status_started (status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operational_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level ENUM('debug','info','warning','error','critical') NOT NULL,
    service VARCHAR(60) NOT NULL,
    event VARCHAR(100) NOT NULL,
    message VARCHAR(500) NOT NULL,
    campaign_id BIGINT UNSIGNED NULL,
    sms_message_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    context_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_operational_logs_created (created_at),
    INDEX idx_operational_logs_level_created (level,created_at),
    INDEX idx_operational_logs_service_created (service,created_at),
    INDEX idx_operational_logs_campaign (campaign_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations(version,applied_at)
VALUES('008',NOW())
ON DUPLICATE KEY UPDATE applied_at=applied_at;
