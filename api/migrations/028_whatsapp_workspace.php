<?php
declare(strict_types=1);
// Additive journal. It does not change users, existing connections, SMS, credits or billing.
return static function(PDO $pdo):void {
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $opaque=$mysql?' CHARACTER SET ascii COLLATE ascii_bin':'';
    $text=$mysql?'LONGTEXT':'TEXT';
    $tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_workspace_operations (
        owner_id BIGINT NOT NULL, operation_key VARCHAR(80){$opaque} NOT NULL,
        request_sha256 CHAR(64){$opaque} NOT NULL, kind VARCHAR(24){$opaque} NOT NULL,
        waba_id VARCHAR(32){$opaque} NOT NULL, phone_number_id VARCHAR(32){$opaque} NOT NULL,
        state VARCHAR(24){$opaque} NOT NULL, provider_id VARCHAR(1030){$opaque} NULL,
        request_json {$text} NOT NULL, result_json {$text} NULL,
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        PRIMARY KEY(owner_id,operation_key)
    ){$tail}");
    if(!$mysql)$pdo->exec('CREATE INDEX IF NOT EXISTS idx_wa_workspace_owner ON whatsapp_workspace_operations(owner_id,created_at)');
};
