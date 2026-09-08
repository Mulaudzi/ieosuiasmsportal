-- IEOSUIA payment-reference hardening (MariaDB 10.6+)
-- Apply after 001_mvp_foundation.sql. Back up and rehearse first.

DELIMITER $$
DROP PROCEDURE IF EXISTS migrate_ieosuia_payment_002$$
CREATE PROCEDURE migrate_ieosuia_payment_002()
BEGIN
    DECLARE duplicate_count BIGINT DEFAULT 0;
    DECLARE object_count BIGINT DEFAULT 0;
    DECLARE lock_acquired INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DO RELEASE_LOCK(CONCAT(DATABASE(),':ieosuia-payment-002'));
        RESIGNAL;
    END;

    SELECT GET_LOCK(CONCAT(DATABASE(),':ieosuia-payment-002'),10) INTO lock_acquired;
    IF COALESCE(lock_acquired,0)<>1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Could not acquire the migration lock';
    END IF;

    SELECT COUNT(*) INTO duplicate_count FROM (
        SELECT reference FROM wallet_transactions
         WHERE reference IS NOT NULL GROUP BY reference HAVING COUNT(*)>1
    ) duplicates_found;
    IF duplicate_count>0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT='Migration stopped: duplicate wallet transaction references require review';
    END IF;

    ALTER TABLE wallet_transactions
        ADD COLUMN IF NOT EXISTS payos_reference VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS checkout_initiated_at DATETIME NULL;

    SELECT COUNT(*) INTO object_count FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='wallet_transactions'
       AND index_name='uniq_wallet_transaction_reference';
    IF object_count=0 THEN
        ALTER TABLE wallet_transactions
            ADD UNIQUE INDEX uniq_wallet_transaction_reference(reference);
    END IF;

    SELECT COUNT(*) INTO object_count FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='wallet_transactions'
       AND index_name='uniq_wallet_transaction_payos_reference';
    IF object_count=0 THEN
        ALTER TABLE wallet_transactions
            ADD UNIQUE INDEX uniq_wallet_transaction_payos_reference(payos_reference);
    END IF;

    CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(100) PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    INSERT INTO schema_migrations(version,applied_at)
    VALUES('002_payment_reference_hardening',NOW())
    ON DUPLICATE KEY UPDATE applied_at=VALUES(applied_at);
    DO RELEASE_LOCK(CONCAT(DATABASE(),':ieosuia-payment-002'));
END$$
CALL migrate_ieosuia_payment_002()$$
DROP PROCEDURE migrate_ieosuia_payment_002$$
DELIMITER ;

