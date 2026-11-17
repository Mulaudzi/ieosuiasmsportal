<?php
declare(strict_types=1);

/** Exact-byte authentication, sender binding and status extraction. */
final class MetaWhatsAppWebhook
{
    public function __construct(private string $appSecret, private string $verifyToken,
        private string $wabaId, private string $phoneNumberId) {}

    public function challenge(array $query): ?string
    {
        // PHP converts dots in query keys to underscores; support both representations.
        $mode = $query['hub.mode'] ?? $query['hub_mode'] ?? null;
        $token = $query['hub.verify_token'] ?? $query['hub_verify_token'] ?? null;
        $challenge = $query['hub.challenge'] ?? $query['hub_challenge'] ?? null;
        return $this->verifyToken !== '' && $mode === 'subscribe' && is_string($token)
            && hash_equals($this->verifyToken, $token) && is_string($challenge)
            && preg_match('/^[0-9]{1,128}$/D', $challenge) ? $challenge : null;
    }

    /** HMAC is checked against the original bytes before JSON is decoded. */
    public function matchingPayload(string $raw, string $signature): array
    {
        if ($this->appSecret === '' || !preg_match('/^[1-9][0-9]{0,31}$/D', $this->wabaId)
            || !preg_match('/^[1-9][0-9]{0,31}$/D', $this->phoneNumberId)) {
            throw new RuntimeException('WhatsApp webhook is not configured.');
        }
        if (strlen($raw) > 1048576 || !preg_match('/^sha256=[a-f0-9]{64}$/D', $signature)
            || !hash_equals('sha256='.hash_hmac('sha256', $raw, $this->appSecret), $signature)) {
            throw new InvalidArgumentException('Invalid WhatsApp webhook signature.');
        }
        try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new InvalidArgumentException('Invalid WhatsApp webhook JSON.'); }
        if (!is_array($data) || ($data['object'] ?? '') !== 'whatsapp_business_account'
            || !is_array($data['entry'] ?? null) || !array_is_list($data['entry'])) {
            throw new InvalidArgumentException('Invalid WhatsApp webhook envelope.');
        }
        $entries = [];
        foreach ($data['entry'] as $entry) {
            if (!is_array($entry) || ($entry['id'] ?? null) !== $this->wabaId || !is_array($entry['changes'] ?? null)) continue;
            $changes = [];
            foreach ($entry['changes'] as $change) {
                if (!is_array($change) || ($change['field'] ?? '') !== 'messages') continue;
                $value = $change['value'] ?? null;
                $metadata = is_array($value) ? ($value['metadata'] ?? null) : null;
                if (is_array($value) && is_array($metadata) && ($value['messaging_product'] ?? '') === 'whatsapp'
                    && ($metadata['phone_number_id'] ?? null) === $this->phoneNumberId) $changes[] = $change;
            }
            if ($changes) $entries[] = ['id' => $this->wabaId, 'changes' => $changes];
        }
        return ['object' => 'whatsapp_business_account', 'entry' => $entries];
    }

    public function statusEvents(string $raw, string $signature): array
    {
        $data = $this->matchingPayload($raw, $signature);
        $events = [];
        foreach ($data['entry'] as $entry) {
            if (!is_array($entry) || ($entry['id'] ?? null) !== $this->wabaId || !is_array($entry['changes'] ?? null)) continue;
            foreach ($entry['changes'] as $change) {
                if (!is_array($change) || ($change['field'] ?? '') !== 'messages') continue;
                $value = $change['value'] ?? null;
                if (!is_array($value) || ($value['messaging_product'] ?? '') !== 'whatsapp'
                    || ($value['metadata']['phone_number_id'] ?? null) !== $this->phoneNumberId
                    || !is_array($value['statuses'] ?? null)) continue;
                foreach ($value['statuses'] as $status) {
                    if (!is_array($status)) continue;
                    $id = $status['id'] ?? null; $state = $status['status'] ?? null; $timestamp = $status['timestamp'] ?? null;
                    if (!is_string($id) || !preg_match('/^wamid\.[A-Za-z0-9+\/_=.-]{1,1024}$/D', $id)
                        || !is_string($state) || !in_array($state, ['sent', 'delivered', 'read', 'failed'], true)
                        || !is_string($timestamp) || !preg_match('/^[1-9][0-9]{0,10}$/D', $timestamp)) continue;
                    $key = hash('sha256', $this->wabaId.'|'.$this->phoneNumberId.'|'.$id.'|'.$state.'|'.$timestamp);
                    $events[$key] = ['event_key' => $key, 'provider' => 'meta-whatsapp',
                        'waba_id' => $this->wabaId, 'phone_number_id' => $this->phoneNumberId,
                        'provider_message_id' => $id, 'status' => $state, 'provider_timestamp' => $timestamp];
                }
            }
        }
        return array_values($events);
    }
}
