-- SMS phase 2: purge all locally usable authentication credentials.
-- The nullable columns remain temporarily for legacy query compatibility.

START TRANSACTION;

ALTER TABLE ejetffbz_sms.users
  MODIFY COLUMN password VARCHAR(255) NULL;

UPDATE ejetffbz_sms.users
SET password = NULL,
    google_id = NULL,
    email_verification_token = NULL,
    email_verification_sent_at = NULL,
    otp_code = NULL,
    otp_expires_at = NULL,
    auth_version = auth_version + 1,
    updated_at = CURRENT_TIMESTAMP;

ALTER TABLE ejetffbz_sms.admin_users
  MODIFY COLUMN password VARCHAR(255) NULL,
  MODIFY COLUMN pin_hash VARCHAR(255) NULL;

UPDATE ejetffbz_sms.admin_users
SET password = NULL,
    pin_hash = NULL,
    failed_attempts = 0,
    locked_until = NULL,
    updated_at = CURRENT_TIMESTAMP;

COMMIT;

SELECT COUNT(*) AS users_with_local_credentials
FROM ejetffbz_sms.users
WHERE password IS NOT NULL
   OR google_id IS NOT NULL
   OR email_verification_token IS NOT NULL
   OR otp_code IS NOT NULL;

SELECT COUNT(*) AS admins_with_local_credentials
FROM ejetffbz_sms.admin_users
WHERE password IS NOT NULL OR pin_hash IS NOT NULL;

SELECT COUNT(*) AS linked_active_customers
FROM ejetffbz_sms.users
WHERE identity_uuid IS NOT NULL AND is_active = 1 AND role <> 'admin';

SELECT COUNT(*) AS linked_active_admins
FROM ejetffbz_sms.admin_users
WHERE identity_uuid IS NOT NULL AND is_active = 1;
