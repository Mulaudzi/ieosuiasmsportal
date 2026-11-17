<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/database.php';
// Only expired onboarding credentials. No Graph calls, messages or existing SMS/account changes.
try {
    $pdo = db(); $now = time();
    $pdo->beginTransaction();
    $s = $pdo->prepare("UPDATE whatsapp_connect_intents SET status = 'expired', token_cipher = NULL, options_json = NULL
        WHERE expires_at <= ? AND status IN ('awaiting','processing','pending','confirming')");
    $s->execute([$now]); $intents = $s->rowCount();
    $s = $pdo->prepare("UPDATE whatsapp_customer_connections SET status = 'expired', token_cipher = NULL, updated_at = ?
        WHERE status = 'authorized' AND token_expires_at IS NOT NULL AND token_expires_at <= ?");
    $s->execute([$now,$now]); $connections = $s->rowCount();
    $s = $pdo->prepare('DELETE FROM whatsapp_connect_intents WHERE expires_at < ? AND token_cipher IS NULL');
    $s->execute([$now-86400]);
    $pdo->commit();
    echo json_encode(['expired_intents'=>$intents, 'expired_connections'=>$connections], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "WhatsApp credential cleanup failed. Check private setup.\n"); exit(1);
}
