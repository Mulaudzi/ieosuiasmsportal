<?php

return static function (PDO $pdo): void {
    Migration::addColumn($pdo, 'users', 'role', "VARCHAR(20) NOT NULL DEFAULT 'user'");
    Migration::addColumn($pdo, 'users', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
    Migration::addColumn($pdo, 'users', 'auth_version', 'INT UNSIGNED NOT NULL DEFAULT 1');
    if (Migration::columnExists($pdo,'users','account_type')) $pdo->exec("UPDATE users SET role='admin' WHERE account_type='admin'");
    if (Migration::tableExists($pdo,'user_roles')) $pdo->exec("UPDATE users u JOIN user_roles r ON r.user_id=u.id SET u.role='admin' WHERE r.role='admin'");
    if (Migration::tableExists($pdo,'admin_users')) $pdo->exec("UPDATE users u JOIN admin_users a ON LOWER(a.email)=LOWER(u.email) SET u.role='admin' WHERE a.is_active=1");
    Migration::addColumn($pdo, 'contacts', 'phone_normalized', 'VARCHAR(20) NULL');
    Migration::addColumn($pdo, 'contacts', 'phone_original', 'VARCHAR(50) NULL');
    // Preserve the entered value and normalize safely recognizable E.164/SA forms.
    // Unrecognizable values deliberately remain NULL for manual review rather than
    // being silently changed to a potentially different recipient.
    $pdo->exec("UPDATE contacts c
        JOIN (
            SELECT id, REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') AS digits
            FROM contacts
        ) source ON source.id=c.id
        SET c.phone_original=COALESCE(c.phone_original,c.phone),
            c.phone_normalized=CASE
                WHEN source.digits REGEXP '^0[0-9]{9}$' THEN CONCAT('+27',SUBSTRING(source.digits,2))
                WHEN source.digits REGEXP '^27[0-9]{9}$' THEN CONCAT('+',source.digits)
                WHEN source.digits REGEXP '^[1-9][0-9]{7,14}$' THEN CONCAT('+',source.digits)
                ELSE NULL
            END
        WHERE c.phone_normalized IS NULL");
    Migration::assertNoDuplicates($pdo,'contacts',['user_id','phone_normalized'],'tenant phone');
    Migration::addIndex($pdo, 'contacts', 'idx_contacts_user_phone_normalized', '`user_id`, `phone_normalized`', true);

    // The supplied MariaDB schema uses BIGINT identifiers for users, wallets,
    // wallet transactions and contacts. Canonical foreign keys must match them.
    Migration::modifyColumn($pdo,'payments','user_id','BIGINT UNSIGNED NOT NULL');
    Migration::modifyColumn($pdo,'payments','wallet_id','BIGINT UNSIGNED NOT NULL');
    Migration::modifyColumn($pdo,'payments','transaction_id','BIGINT UNSIGNED NULL');
    Migration::modifyColumn($pdo,'wallets','currency',"VARCHAR(3) NOT NULL DEFAULT 'ZAR'");
    if(Migration::columnExists($pdo,'messages','telnyx_id')&&!Migration::columnExists($pdo,'messages','legacy_provider_id')){
        $pdo->exec('ALTER TABLE `messages` CHANGE COLUMN `telnyx_id` `legacy_provider_id` VARCHAR(255) NULL');
    }
    if(Migration::indexExists($pdo,'messages','idx_telnyx_id')&&!Migration::indexExists($pdo,'messages','idx_legacy_provider_id')){
        $pdo->exec('ALTER TABLE `messages` RENAME INDEX `idx_telnyx_id` TO `idx_legacy_provider_id`');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_campaigns (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        idempotency_key VARCHAR(80) NOT NULL,
        name VARCHAR(100) NOT NULL,
        content TEXT NOT NULL,
        sender_id VARCHAR(20) NULL,
        state VARCHAR(24) NOT NULL DEFAULT 'draft',
        encoding VARCHAR(10) NOT NULL,
        segments_per_message SMALLINT UNSIGNED NOT NULL,
        recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
        queued_count INT UNSIGNED NOT NULL DEFAULT 0,
        processing_count INT UNSIGNED NOT NULL DEFAULT 0,
        sent_count INT UNSIGNED NOT NULL DEFAULT 0,
        delivered_count INT UNSIGNED NOT NULL DEFAULT 0,
        failed_count INT UNSIGNED NOT NULL DEFAULT 0,
        skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
        total_segments INT UNSIGNED NOT NULL DEFAULT 0,
        price_per_segment DECIMAL(12,4) NOT NULL,
        estimated_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
        actual_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
        scheduled_at DATETIME NULL,
        queued_at DATETIME NULL,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        cancelled_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uniq_sms_campaign_user_idempotency (user_id, idempotency_key),
        KEY idx_sms_campaign_owner_state (user_id, state),
        KEY idx_sms_campaign_schedule (state, scheduled_at),
        CONSTRAINT fk_sms_campaign_user FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        destination VARCHAR(20) NOT NULL,
        content TEXT NOT NULL,
        encoding VARCHAR(10) NOT NULL,
        segment_count SMALLINT UNSIGNED NOT NULL,
        estimated_charge DECIMAL(12,4) NOT NULL,
        actual_charge DECIMAL(12,4) NOT NULL DEFAULT 0,
        provider VARCHAR(30) NULL,
        provider_message_id VARCHAR(100) NULL,
        state VARCHAR(24) NOT NULL DEFAULT 'pending',
        attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        next_attempt_at DATETIME NULL,
        lease_token CHAR(36) NULL,
        leased_at DATETIME NULL,
        last_error VARCHAR(500) NULL,
        sent_at DATETIME NULL,
        delivered_at DATETIME NULL,
        failed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uniq_sms_campaign_destination (campaign_id, destination),
        UNIQUE KEY uniq_sms_provider_message (provider, provider_message_id),
        KEY idx_sms_message_claim (state, next_attempt_at, leased_at),
        KEY idx_sms_message_owner (user_id, id),
        CONSTRAINT fk_sms_message_campaign FOREIGN KEY (campaign_id) REFERENCES sms_campaigns(id),
        CONSTRAINT fk_sms_message_user FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_provider_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(30) NOT NULL,
        provider_event_id VARCHAR(120) NOT NULL,
        provider_message_id VARCHAR(100) NULL,
        event_type VARCHAR(80) NOT NULL,
        payload_json LONGTEXT NOT NULL,
        processed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_sms_provider_event (provider, provider_event_id),
        KEY idx_sms_provider_message_id (provider, provider_message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_ledger (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        wallet_id BIGINT UNSIGNED NOT NULL,
        campaign_id BIGINT UNSIGNED NULL,
        payment_id INT UNSIGNED NULL,
        operation VARCHAR(30) NOT NULL,
        direction VARCHAR(10) NOT NULL,
        amount DECIMAL(14,4) NOT NULL,
        idempotency_key VARCHAR(120) NOT NULL,
        metadata_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_wallet_ledger_idempotency (idempotency_key),
        KEY idx_wallet_ledger_user_created (user_id, created_at),
        CONSTRAINT fk_wallet_ledger_user FOREIGN KEY (user_id) REFERENCES users(id),
        CONSTRAINT fk_wallet_ledger_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id),
        CONSTRAINT fk_wallet_ledger_campaign FOREIGN KEY (campaign_id) REFERENCES sms_campaigns(id),
        CONSTRAINT fk_wallet_ledger_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS service_heartbeats (
        service_name VARCHAR(60) PRIMARY KEY,
        instance_id VARCHAR(120) NULL,
        metadata_json TEXT NULL,
        last_seen_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // The supplied schema already has equivalent unique_opt_out(user_id,recipient,channel).
    Migration::assertNoDuplicates($pdo,'payments',['gateway','merchant_reference'],'payment gateway/merchant reference');
    Migration::addIndex($pdo, 'payments', 'uniq_payments_gateway_merchant', '`gateway`, `merchant_reference`', true);
};
