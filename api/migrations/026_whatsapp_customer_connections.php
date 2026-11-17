<?php
declare(strict_types=1);
// Explicit additive migration. Never executed by a web request.
return static function(PDO $pdo): void {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $opaque = $mysql ? ' CHARACTER SET ascii COLLATE ascii_bin' : '';
    $text = $mysql ? 'LONGTEXT' : 'TEXT';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_connect_intents (
        state_hash CHAR(64){$opaque} PRIMARY KEY, owner_id BIGINT NOT NULL, auth_version BIGINT NOT NULL,
        session_hash CHAR(64){$opaque} NOT NULL, browser_hash CHAR(64){$opaque} NOT NULL,
        status VARCHAR(24){$opaque} NOT NULL, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL,
        token_cipher {$text} NULL, options_json {$text} NULL, token_expires_at BIGINT NULL
        ".($mysql ? ', INDEX idx_wa_connect_owner (owner_id,created_at)' : '')."
    ){$tail}");
    // Reserve the entire WABA for one portal owner, including after disconnect.
    // Reassignment requires a separately reviewed ownership-transfer process.
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_customer_accounts (
        waba_id VARCHAR(32){$opaque} PRIMARY KEY, owner_id BIGINT NOT NULL, created_at BIGINT NOT NULL
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_customer_connections (
        phone_number_id VARCHAR(32){$opaque} PRIMARY KEY, owner_id BIGINT NOT NULL,
        waba_id VARCHAR(32){$opaque} NOT NULL, status VARCHAR(24){$opaque} NOT NULL,
        token_cipher {$text} NULL, token_expires_at BIGINT NULL, metadata_json {$text} NOT NULL,
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL
        ".($mysql ? ', INDEX idx_wa_connection_owner (owner_id)' : '')."
    ){$tail}");
    if (!$mysql) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wa_connect_owner ON whatsapp_connect_intents (owner_id,created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wa_connection_owner ON whatsapp_customer_connections (owner_id)');
    }
};
