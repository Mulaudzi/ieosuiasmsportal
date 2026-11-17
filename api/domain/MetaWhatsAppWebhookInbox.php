<?php
declare(strict_types=1);

/** Immutable receipt storage. Only pass a signature-verified, sender-filtered payload. */
final class MetaWhatsAppWebhookInbox
{
    public static function store(PDO $pdo, string $sourceHash, array $payload, array $events): array
    {
        if ($pdo->inTransaction()) throw new LogicException('Webhook inbox requires its own transaction');
        if (!preg_match('/^[a-f0-9]{64}$/D', $sourceHash) || empty($payload['entry'])) throw new InvalidArgumentException('Invalid verified receipt');
        $waba = (string)$payload['entry'][0]['id'];
        $phone = (string)$payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'];
        $key = hash('sha256', $waba.'|'.$phone.'|'.$sourceHash);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $now = gmdate('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT receipt_key FROM whatsapp_webhook_receipts WHERE receipt_key=?');
            $q->execute([$key]);
            if ($q->fetchColumn()) { $pdo->rollBack(); return ['stored' => true, 'duplicate' => true]; }
            $q = $pdo->prepare('INSERT INTO whatsapp_webhook_receipts(receipt_key,source_payload_sha256,waba_id,phone_number_id,payload_json,received_at_utc) VALUES(?,?,?,?,?,?)');
            $q->execute([$key,$sourceHash,$waba,$phone,$json,$now]);
            foreach ($events as $event) {
                if ($event['waba_id'] !== $waba || $event['phone_number_id'] !== $phone) throw new LogicException('Event sender mismatch');
                $q = $pdo->prepare('INSERT INTO whatsapp_status_events(event_key,receipt_key,waba_id,phone_number_id,provider_message_id,status,provider_timestamp,received_at_utc) VALUES(?,?,?,?,?,?,?,?)');
                try {
                    $q->execute([$event['event_key'],$key,$waba,$phone,$event['provider_message_id'],$event['status'],$event['provider_timestamp'],$now]);
                } catch (PDOException $error) {
                    // A repeated status may occur inside a different signed delivery batch.
                    if ($error->getCode() !== '23000') throw $error;
                    $existing = $pdo->prepare('SELECT waba_id,phone_number_id,provider_message_id,status,provider_timestamp FROM whatsapp_status_events WHERE event_key=?');
                    $existing->execute([$event['event_key']]); $row = $existing->fetch(PDO::FETCH_ASSOC);
                    if (!$row || $row['waba_id'] !== $waba || $row['phone_number_id'] !== $phone
                        || $row['provider_message_id'] !== $event['provider_message_id'] || $row['status'] !== $event['status']
                        || (string)$row['provider_timestamp'] !== $event['provider_timestamp']) throw $error;
                }
            }
            $pdo->commit();
            return ['stored' => true, 'duplicate' => false];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof PDOException && $error->getCode() === '23000') {
                $q = $pdo->prepare('SELECT receipt_key FROM whatsapp_webhook_receipts WHERE receipt_key=?'); $q->execute([$key]);
                if ($q->fetchColumn()) return ['stored' => true, 'duplicate' => true];
            }
            throw $error;
        }
    }
}
