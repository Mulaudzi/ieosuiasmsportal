<?php

return static function (PDO $pdo): void {
    Migration::addColumn($pdo,'sms_campaigns','request_fingerprint','CHAR(64) NULL AFTER idempotency_key');
    Migration::addColumn($pdo,'sms_campaigns','quota_reserved_at','DATETIME NULL AFTER queued_at');
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_daily_quota_usage (
        user_id BIGINT UNSIGNED NOT NULL,
        usage_date DATE NOT NULL,
        segments_reserved BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (user_id,usage_date),
        CONSTRAINT fk_sms_daily_quota_user FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};

// Reversal, after application rollback only:
// DROP TABLE sms_daily_quota_usage;
// ALTER TABLE sms_campaigns DROP COLUMN quota_reserved_at, DROP COLUMN request_fingerprint;
