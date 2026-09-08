-- SMS phase 1: link approved central identities without deleting business data.

START TRANSACTION;

ALTER TABLE ejetffbz_sms.users
  ADD COLUMN IF NOT EXISTS identity_uuid CHAR(36) NULL AFTER id;
CREATE UNIQUE INDEX IF NOT EXISTS users_identity_uuid_unique
  ON ejetffbz_sms.users (identity_uuid);

ALTER TABLE ejetffbz_sms.admin_users
  ADD COLUMN IF NOT EXISTS identity_uuid CHAR(36) NULL AFTER id;
CREATE UNIQUE INDEX IF NOT EXISTS admin_users_identity_uuid_unique
  ON ejetffbz_sms.admin_users (identity_uuid);

-- Link only customers approved and granted SMS access centrally.
UPDATE ejetffbz_sms.users u
JOIN ejetffbz_auth.customer_accounts ca
  ON LOWER(ca.email) = LOWER(u.email)
JOIN ejetffbz_auth.customer_app_access caa
  ON caa.customer_account_id = ca.id AND caa.status = 'active'
JOIN ejetffbz_auth.applications a
  ON a.id = caa.application_id AND a.slug = 'sms'
SET u.identity_uuid = ca.uuid,
    u.email_verified_at = COALESCE(u.email_verified_at, ca.email_verified_at),
    u.is_active = 1,
    u.updated_at = CURRENT_TIMESTAMP
WHERE LOWER(u.email) NOT LIKE '%@ieosuia.com'
  AND LOWER(u.email) <> 'vendaboy.lm@gmail.com';

-- Excluded identities remain as disabled archival owners so campaigns,
-- contacts, wallets, templates and message history cannot be cascade-deleted.
UPDATE ejetffbz_sms.users
SET identity_uuid = NULL,
    is_active = 0,
    auth_version = auth_version + 1,
    email_verification_token = NULL,
    otp_code = NULL,
    otp_expires_at = NULL,
    updated_at = CURRENT_TIMESTAMP
WHERE LOWER(email) = 'vendaboy.lm@gmail.com'
   OR LOWER(email) LIKE '%@ieosuia.com';

SET @central_admin_uuid := (
  SELECT uuid FROM ejetffbz_auth.admin_accounts
  WHERE LOWER(email) = 'lufuno@ieosuia.com' AND status = 'active'
  LIMIT 1
);

UPDATE ejetffbz_sms.admin_users
SET identity_uuid = @central_admin_uuid,
    email = 'lufuno@ieosuia.com',
    name = 'Lufuno Mulaudzi',
    is_active = 1,
    failed_attempts = 0,
    locked_until = NULL,
    updated_at = CURRENT_TIMESTAMP
WHERE id = 1 AND @central_admin_uuid IS NOT NULL;

-- No other SMS administrator is approved. This is guarded by central lookup.
DELETE FROM ejetffbz_sms.admin_users
WHERE id <> 1 AND @central_admin_uuid IS NOT NULL;

COMMIT;

SELECT COUNT(*) AS linked_sms_customers
FROM ejetffbz_sms.users u
JOIN ejetffbz_auth.customer_accounts ca ON ca.uuid = u.identity_uuid
WHERE u.is_active = 1;

SELECT COUNT(*) AS excluded_active_customers
FROM ejetffbz_sms.users
WHERE (LOWER(email) = 'vendaboy.lm@gmail.com' OR LOWER(email) LIKE '%@ieosuia.com')
  AND (identity_uuid IS NOT NULL OR is_active <> 0);

SELECT (@central_admin_uuid IS NOT NULL) AS central_admin_found;
SELECT COUNT(*) AS linked_sms_admins
FROM ejetffbz_sms.admin_users
WHERE identity_uuid = @central_admin_uuid
  AND email = 'lufuno@ieosuia.com'
  AND is_active = 1;
