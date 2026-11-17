<?php
// Saved form data only. This table is not read by sending or payment workers.
return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS work_drafts (
        id CHAR(36) NOT NULL PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        kind VARCHAR(16) NOT NULL,
        title VARCHAR(100) NOT NULL,
        payload_json MEDIUMTEXT NOT NULL,
        version INT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_work_drafts_owner(user_id,kind,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
