<?php
declare(strict_types=1);
// Dedicated public callback. Does not enable outgoing WhatsApp or app-client API access.
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
ini_set('display_errors', '0');
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/domain/MetaWhatsAppWebhookReceiver.php';
require_once __DIR__.'/domain/MetaWhatsAppWebhookInbox.php';
require_once __DIR__.'/domain/MetaWhatsAppCustomerWebhook.php';
try {
    $config = [];
    foreach (['WHATSAPP_WEBHOOK_ENABLED','WHATSAPP_META_APP_SECRET','WHATSAPP_META_VERIFY_TOKEN',
        'WHATSAPP_META_WABA_ID','WHATSAPP_META_PHONE_NUMBER_ID'] as $key) $config[$key] = (string)env($key, '');
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
    $raw = $method === 'POST' ? (string)file_get_contents('php://input', false, null, 0, 1048577) : '';
    $result = MetaWhatsAppWebhookReceiver::handle($method, $_GET, $raw,
        (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''), $config,
        static fn(string $hash, array $payload, array $events): array => MetaWhatsAppWebhookInbox::store(db(), $hash, $payload, $events));
    if ($method === 'POST' && $result['status'] === 200 && env('WHATSAPP_WORKSPACE_ENABLED', 'false') === 'true') {
        MetaWhatsAppCustomerWebhook::store(db(), $raw, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''), $config['WHATSAPP_META_APP_SECRET']);
    }
    http_response_code($result['status']);
    header('Content-Type: '.$result['content_type']);
    if ($result['status'] === 405) header('Allow: GET, POST');
    echo $result['body'];
} catch (Throwable) {
    http_response_code(503); header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"WHATSAPP_WEBHOOK_UNAVAILABLE"}';
}
