-- SMS Portal Phase 1.5 read-only database preflight.
-- Run against staging before migration 018. SELECT statements only.

SELECT DATABASE() AS selected_schema, VERSION() AS database_version,
       @@sql_mode AS sql_mode, @@character_set_database AS character_set_name,
       @@collation_database AS collation_name;

SELECT table_name,engine,table_rows,
       ROUND(data_length/1024/1024,2) AS data_mb,
       ROUND(index_length/1024/1024,2) AS index_mb
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('sms_campaigns','sms_messages','wallet_ledger')
ORDER BY table_name;

SELECT required.table_name AS missing_required_table
FROM (
  SELECT 'users' table_name UNION ALL SELECT 'sms_campaigns' UNION ALL
  SELECT 'sms_messages' UNION ALL SELECT 'sms_provider_events' UNION ALL
  SELECT 'wallet_ledger' UNION ALL SELECT 'opt_outs'
) required
LEFT JOIN INFORMATION_SCHEMA.TABLES actual
  ON actual.TABLE_SCHEMA=DATABASE() AND actual.TABLE_NAME=required.table_name
WHERE actual.TABLE_NAME IS NULL;

SELECT 'migration_018_columns' AS check_name, COUNT(*) AS present_count,
       CASE WHEN COUNT(*)=0 THEN 'PASS_NOT_APPLIED'
            WHEN COUNT(*)=2 THEN 'PASS_ALREADY_APPLIED'
            ELSE 'STOP_PARTIAL_MIGRATION' END AS result
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sms_campaigns'
  AND COLUMN_NAME IN ('request_fingerprint','quota_reserved_at');

SELECT 'migration_018_quota_table' AS check_name, COUNT(*) AS present_count,
       CASE WHEN COUNT(*)=0 THEN 'PASS_NOT_APPLIED'
            WHEN COUNT(*)=1 THEN 'PASS_ALREADY_APPLIED' ELSE 'STOP' END AS result
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sms_daily_quota_usage';

SELECT table_name, column_name, column_type
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND ((TABLE_NAME='users' AND COLUMN_NAME='id')
    OR (TABLE_NAME='sms_campaigns' AND COLUMN_NAME='user_id'));

SELECT required.index_name AS missing_dependency_index
FROM (
  SELECT 'sms_campaigns' table_name, 'uniq_sms_campaign_user_idempotency' index_name UNION ALL
  SELECT 'sms_messages', 'uniq_sms_campaign_destination' UNION ALL
  SELECT 'sms_messages', 'uniq_sms_provider_message' UNION ALL
  SELECT 'sms_provider_events', 'uniq_sms_provider_event' UNION ALL
  SELECT 'wallet_ledger', 'uniq_wallet_ledger_idempotency'
) required
LEFT JOIN INFORMATION_SCHEMA.STATISTICS actual
  ON actual.TABLE_SCHEMA=DATABASE() AND actual.TABLE_NAME=required.table_name
 AND actual.INDEX_NAME=required.index_name
WHERE actual.INDEX_NAME IS NULL;

-- These duplicate/orphan result sets must be empty.
SELECT user_id, idempotency_key, COUNT(*) duplicate_count
FROM sms_campaigns GROUP BY user_id,idempotency_key HAVING COUNT(*)>1;

SELECT campaign_id, destination, COUNT(*) duplicate_count
FROM sms_messages GROUP BY campaign_id,destination HAVING COUNT(*)>1;

SELECT provider, provider_message_id, COUNT(*) duplicate_count
FROM sms_messages WHERE provider_message_id IS NOT NULL AND provider_message_id<>''
GROUP BY provider,provider_message_id HAVING COUNT(*)>1;

SELECT idempotency_key, COUNT(*) duplicate_count
FROM wallet_ledger GROUP BY idempotency_key HAVING COUNT(*)>1;

SELECT COUNT(*) AS orphan_campaigns
FROM sms_campaigns c LEFT JOIN users u ON u.id=c.user_id WHERE u.id IS NULL;

SELECT COUNT(*) AS orphan_messages
FROM sms_messages m LEFT JOIN sms_campaigns c ON c.id=m.campaign_id
WHERE c.id IS NULL;

-- Review and drain/freeze these rows before switching workers. Do not reset
-- them or requeue them merely to make this count zero.
SELECT state, COUNT(*) row_count,
       SUM(provider_message_id IS NOT NULL AND provider_message_id<>'') provider_identified
FROM sms_messages
WHERE state IN ('pending','queued','processing','sending','accepted','submitted','unknown')
GROUP BY state ORDER BY state;

SELECT state, COUNT(*) campaign_count, COALESCE(SUM(total_segments),0) segment_count
FROM sms_campaigns
WHERE state IN ('queued','scheduled','processing')
GROUP BY state ORDER BY state;

SELECT COUNT(*) AS malformed_sms_suppressions
FROM opt_outs
WHERE channel='sms' AND (recipient IS NULL OR TRIM(recipient)='');
