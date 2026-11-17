<?php
// Additive inbox only. No existing SMS, wallet, email or account data is changed.
return static function (PDO $pdo): void {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $opaque = $mysql ? ' CHARACTER SET ascii COLLATE ascii_bin' : '';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_webhook_receipts (
        receipt_key CHAR(64){$opaque} PRIMARY KEY, source_payload_sha256 CHAR(64){$opaque} NOT NULL,
        waba_id VARCHAR(32){$opaque} NOT NULL, phone_number_id VARCHAR(32){$opaque} NOT NULL,
        payload_json ".($mysql ? 'LONGTEXT' : 'TEXT')." NOT NULL, received_at_utc DATETIME NOT NULL
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_status_events (
        event_key CHAR(64){$opaque} PRIMARY KEY, receipt_key CHAR(64){$opaque} NOT NULL,
        waba_id VARCHAR(32){$opaque} NOT NULL, phone_number_id VARCHAR(32){$opaque} NOT NULL,
        provider_message_id VARCHAR(1030){$opaque} NOT NULL, status VARCHAR(16){$opaque} NOT NULL,
        provider_timestamp VARCHAR(11){$opaque} NOT NULL, received_at_utc DATETIME NOT NULL,
        FOREIGN KEY(receipt_key) REFERENCES whatsapp_webhook_receipts(receipt_key)
    ){$tail}");
};
