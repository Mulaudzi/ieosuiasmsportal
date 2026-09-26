CREATE TABLE IF NOT EXISTS revoked_auth_tokens (
    token_hash CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    token_type ENUM('customer', 'admin') NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (token_hash),
    KEY idx_revoked_auth_tokens_user (user_id, token_type),
    KEY idx_revoked_auth_tokens_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
