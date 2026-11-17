<?php
declare(strict_types=1);
// Additive schema only. Never included by an HTTP controller or run implicitly.
return static function(PDO $pdo): void {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $opaque = $mysql ? ' CHARACTER SET ascii COLLATE ascii_bin' : '';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_integrations (
        id CHAR(32){$opaque} PRIMARY KEY, user_id BIGINT NOT NULL, name VARCHAR(80) NOT NULL,
        product VARCHAR(32){$opaque} NOT NULL, scopes_json TEXT NOT NULL, status VARCHAR(16){$opaque} NOT NULL,
        daily_credit_limit INTEGER NOT NULL, requests_per_minute INTEGER NOT NULL,
        rate_window BIGINT NOT NULL DEFAULT 0, rate_count INTEGER NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL, revoked_at BIGINT NULL, UNIQUE(id,user_id)
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_integration_keys (
        id CHAR(32){$opaque} PRIMARY KEY, integration_id CHAR(32){$opaque} NOT NULL, user_id BIGINT NOT NULL,
        secret_hash CHAR(64){$opaque} NOT NULL, scopes_json TEXT NOT NULL,
        owner_auth_version BIGINT NOT NULL, owner_security_version BIGINT NOT NULL,
        created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, revoked_at BIGINT NULL, last_used_at BIGINT NULL,
        FOREIGN KEY(integration_id,user_id) REFERENCES sms_integrations(id,user_id)
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_integration_usage (
        integration_id CHAR(32){$opaque} NOT NULL, user_id BIGINT NOT NULL, usage_date CHAR(10){$opaque} NOT NULL,
        admitted_credits INTEGER NOT NULL, campaign_count INTEGER NOT NULL,
        PRIMARY KEY(integration_id,usage_date),
        FOREIGN KEY(integration_id,user_id) REFERENCES sms_integrations(id,user_id)
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_integration_requests (
        id CHAR(32){$opaque} PRIMARY KEY, integration_id CHAR(32){$opaque} NOT NULL, user_id BIGINT NOT NULL,
        key_id CHAR(32){$opaque} NOT NULL, idempotency_hash CHAR(64){$opaque} NOT NULL,
        fingerprint CHAR(64){$opaque} NOT NULL, campaign_id BIGINT NOT NULL,
        external_reference VARCHAR(120) NOT NULL, admitted_credits INTEGER NOT NULL, created_at BIGINT NOT NULL,
        UNIQUE(integration_id,idempotency_hash), UNIQUE(campaign_id),
        FOREIGN KEY(integration_id,user_id) REFERENCES sms_integrations(id,user_id),
        FOREIGN KEY(key_id) REFERENCES sms_integration_keys(id)
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_integration_audit (
        id CHAR(32){$opaque} PRIMARY KEY, user_id BIGINT NOT NULL, integration_id CHAR(32){$opaque} NOT NULL,
        key_id CHAR(32){$opaque} NULL, event VARCHAR(48){$opaque} NOT NULL,
        object_id CHAR(32){$opaque} NULL, created_at BIGINT NOT NULL
    ){$tail}");
};
