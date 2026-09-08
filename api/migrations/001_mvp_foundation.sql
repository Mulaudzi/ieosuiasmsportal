-- IEOSUIA SMS Portal MVP foundation
-- Target: MariaDB 10.6+ (reviewed against the supplied MariaDB 11.4 schema)
-- Take a verified backup and run api/bin/schema-preflight.php before applying.
-- DDL in MariaDB commits implicitly; rehearse this file on a restored snapshot.

SET NAMES utf8mb4;
SET @old_sql_safe_updates := @@SQL_SAFE_UPDATES;
SET SQL_SAFE_UPDATES = 0;

DELIMITER $$

DROP PROCEDURE IF EXISTS migrate_ieosuia_mvp_001$$
CREATE PROCEDURE migrate_ieosuia_mvp_001()
BEGIN
    DECLARE duplicate_count BIGINT DEFAULT 0;
    DECLARE invalid_count BIGINT DEFAULT 0;
    DECLARE object_count BIGINT DEFAULT 0;
    DECLARE lock_acquired INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DO RELEASE_LOCK(CONCAT(DATABASE(), ':ieosuia-mvp-001'));
        RESIGNAL;
    END;

    SELECT GET_LOCK(CONCAT(DATABASE(), ':ieosuia-mvp-001'), 10) INTO lock_acquired;
    IF COALESCE(lock_acquired,0) <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Could not acquire the migration lock';
    END IF;

    -- Perform blocking data checks before the first DDL statement. MariaDB DDL
    -- commits implicitly, so failing later could otherwise leave a partial run.
    SELECT COUNT(*) INTO invalid_count
      FROM contacts
     WHERE phone IS NOT NULL AND phone<>''
       AND REGEXP_REPLACE(phone,'[^0-9]','') NOT REGEXP '^(0[0-9]{9}|27[0-9]{9}|[1-9][0-9]{7,14})$';
    IF invalid_count>0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: invalid contact phone numbers require review';
    END IF;

    SELECT COUNT(*) INTO duplicate_count
      FROM (
          SELECT user_id,
                 CASE
                   WHEN digits REGEXP '^0[0-9]{9}$' THEN CONCAT('+27',SUBSTRING(digits,2))
                   WHEN digits REGEXP '^27[0-9]{9}$' THEN CONCAT('+',digits)
                   WHEN digits REGEXP '^[1-9][0-9]{7,14}$' THEN CONCAT('+',digits)
                   ELSE NULL
                 END AS normalized
            FROM (
                SELECT user_id,REGEXP_REPLACE(COALESCE(phone,''),'[^0-9]','') AS digits
                  FROM contacts
            ) raw_contacts
      ) normalized_contacts
     WHERE normalized IS NOT NULL
     GROUP BY user_id,normalized
    HAVING COUNT(*)>1
     LIMIT 1;
    IF duplicate_count>1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: duplicate normalized contact phone numbers require review';
    END IF;

    SELECT COUNT(*) INTO duplicate_count
      FROM (
          SELECT gateway,merchant_reference
            FROM payments
           WHERE gateway IS NOT NULL AND merchant_reference IS NOT NULL
           GROUP BY gateway,merchant_reference
          HAVING COUNT(*)>1
      ) duplicate_payments;
    IF duplicate_count>0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: duplicate payment merchant references require review';
    END IF;

    SELECT COUNT(*) INTO invalid_count
      FROM wallets
     WHERE balance<0 OR reserved<0 OR reserved>balance;
    IF invalid_count>0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: invalid wallet balances require review';
    END IF;

    SELECT COUNT(*) INTO invalid_count
      FROM wallets
     WHERE currency IS NULL OR UPPER(currency)<>'ZAR';
    IF invalid_count>0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: non-ZAR wallets require an explicit conversion decision';
    END IF;

    SELECT COUNT(*) INTO invalid_count
      FROM payments p
      LEFT JOIN users u ON u.id=p.user_id
      LEFT JOIN wallets w ON w.id=p.wallet_id
      LEFT JOIN wallet_transactions wt ON wt.id=p.transaction_id
     WHERE u.id IS NULL OR w.id IS NULL
        OR (p.transaction_id IS NOT NULL AND wt.id IS NULL);
    IF invalid_count>0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: orphaned payment relationships require review';
    END IF;

    ALTER TABLE users
        ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'user',
        ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1,
        ADD COLUMN IF NOT EXISTS auth_version INT UNSIGNED NOT NULL DEFAULT 1;

    SELECT COUNT(*) INTO object_count
      FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='users' AND column_name='account_type';
    IF object_count > 0 THEN
        UPDATE users SET role='admin' WHERE account_type='admin';
    END IF;

    SELECT COUNT(*) INTO object_count
      FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name='user_roles';
    IF object_count > 0 THEN
        SET @migration_sql = 'UPDATE users u JOIN user_roles r ON r.user_id=u.id SET u.role=''admin'' WHERE r.role=''admin''';
        PREPARE migration_statement FROM @migration_sql;
        EXECUTE migration_statement;
        DEALLOCATE PREPARE migration_statement;
    END IF;

    SELECT COUNT(*) INTO object_count
      FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name='admin_users';
    IF object_count > 0 THEN
        SET @migration_sql = 'UPDATE users u JOIN admin_users a ON LOWER(a.email)=LOWER(u.email) SET u.role=''admin'' WHERE a.is_active=1';
        PREPARE migration_statement FROM @migration_sql;
        EXECUTE migration_statement;
        DEALLOCATE PREPARE migration_statement;
    END IF;

    ALTER TABLE contacts
        ADD COLUMN IF NOT EXISTS phone_normalized VARCHAR(20) NULL,
        ADD COLUMN IF NOT EXISTS phone_original VARCHAR(50) NULL;

    UPDATE contacts c
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
     WHERE c.phone_normalized IS NULL;

    SELECT COUNT(*) INTO invalid_count
      FROM contacts
     WHERE phone IS NOT NULL AND phone<>'' AND phone_normalized IS NULL;
    IF invalid_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: invalid contact phone numbers require review';
    END IF;

    SELECT COUNT(*) INTO duplicate_count
      FROM (
          SELECT user_id,phone_normalized
            FROM contacts
           WHERE phone_normalized IS NOT NULL
           GROUP BY user_id,phone_normalized
          HAVING COUNT(*)>1
      ) duplicate_phones;
    IF duplicate_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: duplicate normalized contact phone numbers require review';
    END IF;

    SELECT COUNT(*) INTO object_count
      FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='contacts'
       AND index_name='idx_contacts_user_phone_normalized';
    IF object_count=0 THEN
        ALTER TABLE contacts
            ADD UNIQUE INDEX idx_contacts_user_phone_normalized (user_id,phone_normalized);
    END IF;

    ALTER TABLE payments
        MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL,
        MODIFY COLUMN wallet_id BIGINT UNSIGNED NOT NULL,
        MODIFY COLUMN transaction_id BIGINT UNSIGNED NULL;

    ALTER TABLE wallets
        MODIFY COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'ZAR';

    SELECT COUNT(*) INTO object_count
      FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='messages' AND column_name='telnyx_id';
    IF object_count>0 THEN
        SELECT COUNT(*) INTO invalid_count
          FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='messages' AND column_name='legacy_provider_id';
        IF invalid_count=0 THEN
            ALTER TABLE messages
                CHANGE COLUMN telnyx_id legacy_provider_id VARCHAR(255) NULL;
        END IF;
    END IF;

    SELECT COUNT(*) INTO object_count
      FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='messages' AND index_name='idx_telnyx_id';
    IF object_count>0 THEN
        SELECT COUNT(*) INTO invalid_count
          FROM information_schema.statistics
         WHERE table_schema=DATABASE() AND table_name='messages' AND index_name='idx_legacy_provider_id';
        IF invalid_count=0 THEN
            ALTER TABLE messages RENAME INDEX idx_telnyx_id TO idx_legacy_provider_id;
        END IF;
    END IF;

    CREATE TABLE IF NOT EXISTS sms_campaigns (
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
        UNIQUE KEY uniq_sms_campaign_user_idempotency (user_id,idempotency_key),
        KEY idx_sms_campaign_owner_state (user_id,state),
        KEY idx_sms_campaign_schedule (state,scheduled_at),
        CONSTRAINT fk_sms_campaign_user FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS sms_messages (
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
        UNIQUE KEY uniq_sms_campaign_destination (campaign_id,destination),
        UNIQUE KEY uniq_sms_provider_message (provider,provider_message_id),
        KEY idx_sms_message_claim (state,next_attempt_at,leased_at),
        KEY idx_sms_message_owner (user_id,id),
        CONSTRAINT fk_sms_message_campaign FOREIGN KEY (campaign_id) REFERENCES sms_campaigns(id),
        CONSTRAINT fk_sms_message_user FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS sms_provider_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(30) NOT NULL,
        provider_event_id VARCHAR(120) NOT NULL,
        provider_message_id VARCHAR(100) NULL,
        event_type VARCHAR(80) NOT NULL,
        payload_json LONGTEXT NOT NULL,
        processed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_sms_provider_event (provider,provider_event_id),
        KEY idx_sms_provider_message_id (provider,provider_message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS wallet_ledger (
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
        KEY idx_wallet_ledger_user_created (user_id,created_at),
        CONSTRAINT fk_wallet_ledger_user FOREIGN KEY (user_id) REFERENCES users(id),
        CONSTRAINT fk_wallet_ledger_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id),
        CONSTRAINT fk_wallet_ledger_campaign FOREIGN KEY (campaign_id) REFERENCES sms_campaigns(id),
        CONSTRAINT fk_wallet_ledger_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS service_heartbeats (
        service_name VARCHAR(60) PRIMARY KEY,
        instance_id VARCHAR(120) NULL,
        metadata_json TEXT NULL,
        last_seen_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    SELECT COUNT(*) INTO duplicate_count
      FROM (
          SELECT gateway,merchant_reference
            FROM payments
           WHERE gateway IS NOT NULL AND merchant_reference IS NOT NULL
           GROUP BY gateway,merchant_reference
          HAVING COUNT(*)>1
      ) duplicate_payments;
    IF duplicate_count>0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: duplicate payment merchant references require review';
    END IF;

    SELECT COUNT(*) INTO object_count
      FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='payments'
       AND index_name='uniq_payments_gateway_merchant';
    IF object_count=0 THEN
        ALTER TABLE payments
            ADD UNIQUE INDEX uniq_payments_gateway_merchant (gateway,merchant_reference);
    END IF;

    CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(100) PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    INSERT INTO schema_migrations (version,applied_at)
    VALUES ('001_mvp_foundation',NOW())
    ON DUPLICATE KEY UPDATE applied_at=VALUES(applied_at);

    DO RELEASE_LOCK(CONCAT(DATABASE(), ':ieosuia-mvp-001'));
END$$

CALL migrate_ieosuia_mvp_001()$$
DROP PROCEDURE migrate_ieosuia_mvp_001$$

DELIMITER ;

SET SQL_SAFE_UPDATES = @old_sql_safe_updates;

-- Post-migration verification: each query must return zero rows/count zero.
SELECT COUNT(*) AS invalid_wallet_states
  FROM wallets WHERE balance<0 OR reserved<0 OR reserved>balance;
SELECT COUNT(*) AS non_zar_wallets
  FROM wallets WHERE currency<>'ZAR';
SELECT COUNT(*) AS contacts_requiring_phone_review
  FROM contacts WHERE phone IS NOT NULL AND phone<>'' AND phone_normalized IS NULL;
