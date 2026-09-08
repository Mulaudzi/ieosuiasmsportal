<?php

return static function(PDO $pdo):void{
    Migration::addColumn($pdo,'sms_messages','retry_generation','SMALLINT UNSIGNED NOT NULL DEFAULT 0');
    Migration::addColumn($pdo,'sms_messages','last_dlr_checked_at','DATETIME NULL');
    Migration::addColumn($pdo,'sms_messages','dlr_attempt_count','SMALLINT UNSIGNED NOT NULL DEFAULT 0');
    Migration::addIndex($pdo,'sms_messages','idx_sms_dlr_poll','`state`,`last_dlr_checked_at`,`sent_at`');
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_job_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,service_name VARCHAR(60) NOT NULL,instance_id VARCHAR(120) NULL,
        status ENUM('running','completed','failed','skipped') NOT NULL,processed_count INT UNSIGNED NOT NULL DEFAULT 0,
        failed_count INT UNSIGNED NOT NULL DEFAULT 0,message VARCHAR(500) NULL,metadata_json LONGTEXT NULL,
        started_at DATETIME NOT NULL,completed_at DATETIME NULL,duration_ms INT UNSIGNED NULL,
        KEY idx_service_job_runs_service_started(service_name,started_at),KEY idx_service_job_runs_status_started(status,started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS operational_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,level ENUM('debug','info','warning','error','critical') NOT NULL,
        service VARCHAR(60) NOT NULL,event VARCHAR(100) NOT NULL,message VARCHAR(500) NOT NULL,campaign_id BIGINT UNSIGNED NULL,
        sms_message_id BIGINT UNSIGNED NULL,user_id BIGINT UNSIGNED NULL,context_json LONGTEXT NULL,created_at DATETIME NOT NULL,
        KEY idx_operational_logs_created(created_at),KEY idx_operational_logs_level_created(level,created_at),
        KEY idx_operational_logs_service_created(service,created_at),KEY idx_operational_logs_campaign(campaign_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
