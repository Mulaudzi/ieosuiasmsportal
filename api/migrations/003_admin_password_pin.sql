-- Replace legacy three-password Guymhan authentication with password + PIN.
-- Target account: lufuno@ieosuia.com
-- Password: supplied separately by the administrator (bcrypt stored below)
-- PIN: supplied separately by the administrator (bcrypt stored below)

ALTER TABLE admin_users
    ADD COLUMN IF NOT EXISTS password VARCHAR(255) NULL AFTER email,
    ADD COLUMN IF NOT EXISTS pin_hash VARCHAR(255) NULL AFTER password;

-- Preserve existing administrators: password 1 becomes the password and
-- password 2 becomes the PIN unless already migrated.
UPDATE admin_users
SET password = COALESCE(NULLIF(password, ''), password_1),
    pin_hash = COALESCE(NULLIF(pin_hash, ''), password_2);

-- Install the requested credentials for the specified administrator and clear
-- any lockout left by failed attempts.
UPDATE admin_users
SET password = '$2y$12$xpakXlxaKPBJTdKHv4G/JeL5NYHx.dh8kwCc.sxd31z4zVliM4kIu',
    pin_hash = '$2y$12$r4ZIA/gq6E9skVlXSaQp8.wfXHufdk6cMJe.K73wr8.5utGPqMXDm',
    failed_attempts = 0,
    locked_until = NULL,
    updated_at = NOW()
WHERE LOWER(email) = 'lufuno@ieosuia.com';

ALTER TABLE admin_users
    MODIFY COLUMN password VARCHAR(255) NOT NULL,
    MODIFY COLUMN pin_hash VARCHAR(255) NOT NULL,
    DROP COLUMN IF EXISTS password_1,
    DROP COLUMN IF EXISTS password_2,
    DROP COLUMN IF EXISTS password_3;

-- Keep the canonical users identity aligned with the administrator password.
UPDATE users
SET password = '$2y$12$xpakXlxaKPBJTdKHv4G/JeL5NYHx.dh8kwCc.sxd31z4zVliM4kIu',
    role = 'admin',
    updated_at = NOW()
WHERE LOWER(email) = 'lufuno@ieosuia.com';

SELECT id, email, is_active, failed_attempts,
       CASE WHEN password IS NOT NULL AND password <> '' THEN 'yes' ELSE 'no' END AS password_configured,
       CASE WHEN pin_hash IS NOT NULL AND pin_hash <> '' THEN 'yes' ELSE 'no' END AS pin_configured
FROM admin_users
WHERE LOWER(email) = 'lufuno@ieosuia.com';
