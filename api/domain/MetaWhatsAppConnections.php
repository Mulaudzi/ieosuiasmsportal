<?php
declare(strict_types=1);
require_once __DIR__.'/MetaWhatsAppConnectConfig.php';
require_once __DIR__.'/../services/MetaWhatsAppOnboardingClient.php';

/** Customer-scoped authorization records; this service has no message-sending operation. */
final class MetaWhatsAppConnections
{
    public function __construct(private PDO $pdo, private MetaWhatsAppConnectConfig $config,
        private MetaWhatsAppOnboardingClient $meta, private $clock = null) {}

    private function now(): int { return $this->clock === null ? time() : ($this->clock)(); }
    private function lock(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    private function query(string $sql, array $args = []): PDOStatement
    {
        $s = $this->pdo->prepare($sql); $s->execute($args); return $s;
    }

    public function assertReady(): void
    {
        foreach (['whatsapp_connect_intents','whatsapp_customer_accounts','whatsapp_customer_connections'] as $table) {
            $this->pdo->query('SELECT * FROM '.$table.' WHERE 1 = 0');
        }
    }

    private function assertOwner(array $intent): array
    {
        $owner = $this->query('SELECT is_active, auth_version, role FROM users WHERE id = ?', [$intent['owner_id']])->fetch(PDO::FETCH_ASSOC);
        if (!$owner || !(bool)$owner['is_active'] || (int)$owner['auth_version'] !== (int)$intent['auth_version']) {
            throw new MetaWhatsAppConnectException('Your portal session changed. Sign in and start again.', 409);
        }
        $revoked = $this->query('SELECT token_hash FROM revoked_auth_tokens WHERE token_hash = ? AND expires_at >= ?',
            [$intent['session_hash'], date('Y-m-d H:i:s', $this->now())])->fetchColumn();
        if ($revoked !== false) throw new MetaWhatsAppConnectException('Your portal session ended. Sign in and start again.', 409);
        return $owner;
    }

    private function expire(int $owner): void
    {
        $this->query("UPDATE whatsapp_connect_intents SET status = 'expired', token_cipher = NULL, options_json = NULL
            WHERE owner_id = ? AND expires_at <= ? AND status IN ('awaiting','processing','pending','confirming')", [$owner, $this->now()]);
    }

    public function start(int $owner, int $authVersion, string $sessionHash, string $browser, int $sessionExpires): array
    {
        if ($owner < 1 || !preg_match('/^[a-f0-9]{64}$/D', $sessionHash) || !preg_match('/^[a-f0-9]{64}$/D', $browser)) {
            throw new MetaWhatsAppConnectException('Invalid portal session.', 401);
        }
        $state = bin2hex(random_bytes(32)); $now = $this->now();
        $expires = min($now+600, $sessionExpires);
        if ($expires <= $now) throw new MetaWhatsAppConnectException('Your portal session expired. Sign in again.', 401);
        $this->pdo->beginTransaction();
        try {
            // Serialize starts for an owner: a new attempt invalidates every older one.
            $this->query('SELECT id FROM users WHERE id = ?'.$this->lock(), [$owner])->fetchColumn();
            $this->assertOwner(['owner_id'=>$owner,'auth_version'=>$authVersion,'session_hash'=>$sessionHash]);
            $recent = (int)$this->query('SELECT COUNT(*) FROM whatsapp_connect_intents WHERE owner_id = ? AND created_at > ?', [$owner,$now-900])->fetchColumn();
            if ($recent >= 5) throw new MetaWhatsAppConnectException('Too many connection attempts. Please try again in 15 minutes.', 429);
            $this->query("UPDATE whatsapp_connect_intents SET status = 'cancelled', token_cipher = NULL, options_json = NULL
                WHERE owner_id = ? AND status IN ('awaiting','processing','pending','confirming')", [$owner]);
            $this->query("INSERT INTO whatsapp_connect_intents
                (state_hash,owner_id,auth_version,session_hash,browser_hash,status,created_at,expires_at)
                VALUES (?,?,?,?,?,'awaiting',?,?)", [hash('sha256',$state),$owner,$authVersion,$sessionHash,hash('sha256',$browser),$now,$expires]);
            $this->pdo->commit();
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
        return ['authorize_url'=>$this->config->authorizeUrl($state), 'expires_at'=>$expires];
    }

    /** Consume the browser-bound intent BEFORE exchanging a single-use code. No retry after uncertainty. */
    public function callback(array $query, string $browser): string
    {
        $state = $query['state'] ?? null;
        if (!is_string($state) || !preg_match('/^[a-f0-9]{64}$/D', $state) || !preg_match('/^[a-f0-9]{64}$/D', $browser)) {
            throw new MetaWhatsAppConnectException('Start WhatsApp connection from your portal Settings.', 400);
        }
        $hash = hash('sha256', $state);
        $intent = $this->query('SELECT * FROM whatsapp_connect_intents WHERE state_hash = ?', [$hash])->fetch(PDO::FETCH_ASSOC);
        if (!$intent || !hash_equals($intent['browser_hash'], hash('sha256',$browser)) || (int)$intent['expires_at'] <= $this->now()) {
            throw new MetaWhatsAppConnectException('This WhatsApp connection attempt is invalid or expired. Start again.', 400);
        }
        $this->assertOwner($intent);
        $claimed = $this->query("UPDATE whatsapp_connect_intents SET status = 'processing' WHERE state_hash = ? AND status = 'awaiting' AND expires_at > ?",
            [$hash, $this->now()]);
        if ($claimed->rowCount() !== 1) throw new MetaWhatsAppConnectException('This connection response was already used. Start again.', 409);
        try {
            if (isset($query['error'])) throw new MetaWhatsAppConnectException('WhatsApp authorization was cancelled. You can start again.', 400);
            // Browser-supplied WABA/phone IDs, tokens and error descriptions are never trusted.
            $code = $query['code'] ?? null;
            if (!is_string($code)) throw new MetaWhatsAppConnectException('Meta did not return an authorization code.', 400);
            $verified = $this->meta->exchange($code, $this->now());
            $verifiedOwner = $this->assertOwner($intent);
            $cipher = $this->config->seal(['token'=>$verified['token']], 'intent:'.$hash.':'.$intent['owner_id']);
            $saved = $this->query("UPDATE whatsapp_connect_intents SET status = 'pending', token_cipher = ?, options_json = ?, token_expires_at = ?
                WHERE state_hash = ? AND status = 'processing' AND expires_at > ?", [$cipher, json_encode($verified['options'], JSON_THROW_ON_ERROR),
                    $verified['expires_at'], $hash, $this->now()]);
            if ($saved->rowCount() !== 1) throw new MetaWhatsAppConnectException('This connection attempt ended. Start again.', 409);
            return strtolower((string)$verifiedOwner['role']) === 'admin' ? '/guymhan/whatsapp' : '/settings?tab=whatsapp';
        } catch (Throwable $e) {
            $this->query("UPDATE whatsapp_connect_intents SET status = 'failed', token_cipher = NULL, options_json = NULL WHERE state_hash = ? AND status = 'processing'", [$hash]);
            throw $e;
        }
    }

    public function status(int $owner, string $sessionHash, string $browser): array
    {
        $this->expire($owner);
        $connections = [];
        foreach ($this->query('SELECT phone_number_id,waba_id,status,token_expires_at,metadata_json FROM whatsapp_customer_connections WHERE owner_id = ? ORDER BY created_at,phone_number_id', [$owner])->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metadata = json_decode($row['metadata_json'], true, 16, JSON_THROW_ON_ERROR);
            $connections[] = ['phone_number_id'=>$row['phone_number_id'], 'waba_id'=>$row['waba_id'],
                'status'=>$row['status'] === 'authorized' && $row['token_expires_at'] !== null && (int)$row['token_expires_at'] <= $this->now() ? 'expired' : $row['status'],
                'expires_at'=>$row['token_expires_at'] === null ? null : (int)$row['token_expires_at'],
                'display_phone_number'=>$metadata['display_phone_number'], 'verified_name'=>$metadata['verified_name'], 'business_name'=>$metadata['business_name']];
        }
        $pending = null;
        if (preg_match('/^[a-f0-9]{64}$/D', $browser)) {
            $row = $this->query("SELECT state_hash,options_json,expires_at FROM whatsapp_connect_intents
                WHERE owner_id = ? AND session_hash = ? AND browser_hash = ? AND status = 'pending' AND expires_at > ?",
                [$owner,$sessionHash,hash('sha256',$browser),$this->now()])->fetch(PDO::FETCH_ASSOC);
            if ($row) $pending = ['attempt'=>$row['state_hash'], 'options'=>json_decode($row['options_json'], true, 32, JSON_THROW_ON_ERROR), 'expires_at'=>(int)$row['expires_at']];
        }
        return ['available'=>true, 'sending_enabled'=>false, 'connections'=>$connections, 'pending'=>$pending];
    }

    public function confirm(int $owner, string $sessionHash, string $browser, string $attempt, string $phone): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $attempt) || !preg_match('/^[a-f0-9]{64}$/D', $browser)) throw new MetaWhatsAppConnectException('Invalid connection attempt.', 422);
        $phone = MetaWhatsAppOnboardingClient::id($phone);
        $intent = $this->query("SELECT * FROM whatsapp_connect_intents WHERE state_hash = ? AND owner_id = ? AND session_hash = ? AND browser_hash = ? AND status = 'pending' AND expires_at > ?",
            [$attempt,$owner,$sessionHash,hash('sha256',$browser),$this->now()])->fetch(PDO::FETCH_ASSOC);
        if (!$intent) throw new MetaWhatsAppConnectException('This connection attempt is unavailable. Start again.', 409);
        $options = json_decode($intent['options_json'], true, 32, JSON_THROW_ON_ERROR);
        $selected = null;
        foreach ($options as $option) if ($option['phone_number_id'] === $phone) $selected = $option;
        if ($selected === null) throw new MetaWhatsAppConnectException('Choose a number authorized by Meta in this connection attempt.', 422);
        $expectedWaba = $selected['waba_id'];
        $this->assertOwner($intent);
        $claimed = $this->query("UPDATE whatsapp_connect_intents SET status = 'confirming' WHERE state_hash = ? AND status = 'pending' AND expires_at > ?", [$attempt,$this->now()]);
        if ($claimed->rowCount() !== 1) throw new MetaWhatsAppConnectException('This connection attempt was already used.', 409);
        try {
            $token = $this->config->unseal($intent['token_cipher'], 'intent:'.$attempt.':'.$owner)['token'];
            $grant = $this->meta->inspect($token, $this->now());
            $freshOptions = $this->meta->discover($token, $grant['wabas']);
            $selected = null;
            foreach ($freshOptions as $option) if ($option['phone_number_id'] === $phone) $selected = $option;
            if ($selected === null || $selected['waba_id'] !== $expectedWaba) throw new MetaWhatsAppConnectException('Meta authorization for this phone number changed. Start again.', 409);
            $waba = $selected['waba_id'];
            $cipher = $this->config->seal(['token'=>$token], 'connection:'.$owner.':'.$waba.':'.$phone);
            $this->pdo->beginTransaction();
            $this->query('SELECT id FROM users WHERE id = ?'.$this->lock(), [$owner])->fetchColumn();
            // The intent lock also serializes confirmation against cancellation/restart.
            $current = $this->query('SELECT status,expires_at FROM whatsapp_connect_intents WHERE state_hash = ?'.$this->lock(), [$attempt])->fetch(PDO::FETCH_ASSOC);
            if (!$current || $current['status'] !== 'confirming' || (int)$current['expires_at'] <= $this->now()) throw new MetaWhatsAppConnectException('This connection attempt ended. Start again.', 409);
            $this->assertOwner($intent);
            $account = $this->query('SELECT owner_id FROM whatsapp_customer_accounts WHERE waba_id = ?'.$this->lock(), [$waba])->fetch(PDO::FETCH_ASSOC);
            if ($account && (int)$account['owner_id'] !== $owner) throw new MetaWhatsAppConnectException('This WhatsApp account is already assigned to another portal account. Contact support.', 409);
            if (!$account) $this->query('INSERT INTO whatsapp_customer_accounts (waba_id,owner_id,created_at) VALUES (?,?,?)', [$waba,$owner,$this->now()]);
            $existing = $this->query('SELECT owner_id,waba_id FROM whatsapp_customer_connections WHERE phone_number_id = ?'.$this->lock(), [$phone])->fetch(PDO::FETCH_ASSOC);
            if ($existing && ((int)$existing['owner_id'] !== $owner || $existing['waba_id'] !== $waba)) throw new MetaWhatsAppConnectException('This phone number is already assigned to another connection. Contact support.', 409);
            $metadata = json_encode($selected, JSON_THROW_ON_ERROR);
            if ($existing) {
                $this->query("UPDATE whatsapp_customer_connections SET status = 'authorized', token_cipher = ?, token_expires_at = ?, metadata_json = ?, updated_at = ? WHERE phone_number_id = ? AND owner_id = ?",
                    [$cipher,$grant['expires_at'],$metadata,$this->now(),$phone,$owner]);
            } else {
                $this->query("INSERT INTO whatsapp_customer_connections (phone_number_id,owner_id,waba_id,status,token_cipher,token_expires_at,metadata_json,created_at,updated_at)
                    VALUES (?,?,?,'authorized',?,?,?,?,?)", [$phone,$owner,$waba,$cipher,$grant['expires_at'],$metadata,$this->now(),$this->now()]);
            }
            $this->query("UPDATE whatsapp_connect_intents SET status = 'complete', token_cipher = NULL, options_json = NULL WHERE state_hash = ?", [$attempt]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->query("UPDATE whatsapp_connect_intents SET status = 'failed', token_cipher = NULL, options_json = NULL WHERE state_hash = ? AND status = 'confirming'", [$attempt]);
            if ($e instanceof PDOException && (string)$e->getCode() === '23000') throw new MetaWhatsAppConnectException('This WhatsApp account was claimed by another connection. Contact support.', 409);
            throw $e;
        }
    }

    public function cancel(int $owner, string $sessionHash, string $browser): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $browser)) return;
        $this->query("UPDATE whatsapp_connect_intents SET status = 'cancelled', token_cipher = NULL, options_json = NULL
            WHERE owner_id = ? AND session_hash = ? AND browser_hash = ? AND status IN ('awaiting','processing','pending','confirming')",
            [$owner,$sessionHash,hash('sha256',$browser)]);
    }

    public function disconnect(int $owner, string $phone): void
    {
        $phone = MetaWhatsAppOnboardingClient::id($phone);
        $this->pdo->beginTransaction();
        try {
            $this->query('SELECT id FROM users WHERE id = ?'.$this->lock(), [$owner])->fetchColumn();
            if (!$this->query('SELECT phone_number_id FROM whatsapp_customer_connections WHERE owner_id = ? AND phone_number_id = ?', [$owner,$phone])->fetchColumn()) {
                throw new MetaWhatsAppConnectException('WhatsApp connection not found.', 404);
            }
            // Match the user -> intent -> phone lock order used by confirmation/restart.
            $this->query("UPDATE whatsapp_connect_intents SET status = 'cancelled', token_cipher = NULL, options_json = NULL WHERE owner_id = ? AND status IN ('awaiting','processing','pending','confirming')", [$owner]);
            $this->query("UPDATE whatsapp_customer_connections SET status = 'disconnected', token_cipher = NULL, updated_at = ? WHERE owner_id = ? AND phone_number_id = ?", [$this->now(),$owner,$phone]);
            $this->pdo->commit();
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
}
