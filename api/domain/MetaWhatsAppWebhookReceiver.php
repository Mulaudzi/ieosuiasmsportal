<?php
declare(strict_types=1);
require_once __DIR__.'/MetaWhatsAppWebhook.php';

/** Single configured sender inbox only; no sends, billing or external-app grants. */
final class MetaWhatsAppWebhookReceiver
{
    public static function handle(string $method, array $query, string $body, string $signature,
        array $config, callable $persist): array
    {
        $reply = static fn(int $status, array $data): array => ['status' => $status,
            'content_type' => 'application/json; charset=utf-8', 'body' => json_encode($data, JSON_THROW_ON_ERROR)];
        if (($config['WHATSAPP_WEBHOOK_ENABLED'] ?? '') !== 'true') return $reply(503, ['error' => 'WHATSAPP_WEBHOOK_DISABLED']);
        foreach (['WHATSAPP_META_APP_SECRET', 'WHATSAPP_META_VERIFY_TOKEN', 'WHATSAPP_META_WABA_ID', 'WHATSAPP_META_PHONE_NUMBER_ID'] as $key) {
            if (!is_string($config[$key] ?? null) || trim($config[$key]) === '') return $reply(503, ['error' => 'WHATSAPP_WEBHOOK_NOT_CONFIGURED']);
        }
        if (strlen($config['WHATSAPP_META_VERIFY_TOKEN']) < 32
            || !preg_match('/^[1-9][0-9]{0,31}$/D', $config['WHATSAPP_META_WABA_ID'])
            || !preg_match('/^[1-9][0-9]{0,31}$/D', $config['WHATSAPP_META_PHONE_NUMBER_ID'])) {
            return $reply(503, ['error' => 'WHATSAPP_WEBHOOK_NOT_CONFIGURED']);
        }
        $webhook = new MetaWhatsAppWebhook($config['WHATSAPP_META_APP_SECRET'], $config['WHATSAPP_META_VERIFY_TOKEN'],
            $config['WHATSAPP_META_WABA_ID'], $config['WHATSAPP_META_PHONE_NUMBER_ID']);
        if ($method === 'GET') {
            $challenge = $webhook->challenge($query);
            return $challenge === null ? $reply(403, ['error' => 'WHATSAPP_VERIFICATION_REJECTED'])
                : ['status' => 200, 'content_type' => 'text/plain; charset=utf-8', 'body' => $challenge];
        }
        if ($method !== 'POST') return $reply(405, ['error' => 'METHOD_NOT_ALLOWED']);
        if (strlen($body) > 1048576) return $reply(413, ['error' => 'WEBHOOK_TOO_LARGE']);
        try {
            $payload = $webhook->matchingPayload($body, $signature);
            $events = $webhook->statusEvents($body, $signature);
        } catch (InvalidArgumentException) { return $reply(401, ['error' => 'WHATSAPP_WEBHOOK_REJECTED']); }
        catch (Throwable) { return $reply(503, ['error' => 'WHATSAPP_WEBHOOK_UNAVAILABLE']); }
        if (!$payload['entry']) return $reply(200, ['received' => true, 'matched' => false]);
        try {
            $stored = $persist(hash('sha256', $body), $payload, $events);
            if (($stored['stored'] ?? false) !== true) throw new RuntimeException('Receipt not confirmed');
            return $reply(200, ['received' => true, 'matched' => true, 'duplicate' => ($stored['duplicate'] ?? false) === true]);
        } catch (Throwable) {
            // Return a failure so Meta can retry. Never acknowledge an uncommitted receipt.
            return $reply(503, ['error' => 'WHATSAPP_RECEIPT_UNCONFIRMED']);
        }
    }
}
