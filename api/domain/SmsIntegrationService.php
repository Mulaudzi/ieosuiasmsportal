<?php
declare(strict_types=1);
require_once __DIR__.'/SmsIntegrationPolicy.php';
require_once __DIR__.'/CampaignService.php';
require_once __DIR__.'/SmsIntegrationPresentation.php';

/** Account-owned server-to-server credentials; no provider calls or alternative payer. */
final class SmsIntegrationService
{
    public function __construct(private PDO $pdo, private ?Closure $clock = null) {}
    private function now(): int { return $this->clock ? ($this->clock)() : time(); }
    private function lock(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    private function query(string $sql, array $args = []): PDOStatement { $q = $this->pdo->prepare($sql); $q->execute($args); return $q; }
    private function one(string $sql, array $args = []): ?array { return $this->query($sql, $args)->fetch(PDO::FETCH_ASSOC) ?: null; }
    public function enabled(): bool {
        // Old live cores must never ignore an extra PHP argument and queue a
        // campaign without the atomic attribution/budget callback.
        return env('SMS_INTEGRATION_API_ENABLED', 'false') === 'true'
            && defined('CampaignService::INTEGRATION_TRANSACTION_VERSION')
            && CampaignService::INTEGRATION_TRANSACTION_VERSION === 1;
    }
    private function ready(): void {
        if (!$this->enabled()) throw new SmsIntegrationException('API_UNAVAILABLE', 'SMS API access is awaiting activation.', 503);
    }
    private function audit(int $user, string $app, string $event, ?string $key = null, ?string $object = null): void {
        $this->query('INSERT INTO sms_integration_audit(id,user_id,integration_id,key_id,event,object_id,created_at) VALUES(?,?,?,?,?,?,?)', [bin2hex(random_bytes(16)), $user, $app, $key, $event, $object, $this->now()]);
    }
    private function owner(int $id): ?array {
        return $this->one('SELECT u.id,u.is_active,u.email_verified_at,u.auth_version,s.enabled AS security_enabled,s.version AS security_version FROM users u LEFT JOIN sms_login_security s ON s.user_id=u.id WHERE u.id=?', [$id]);
    }
    private function issuer(array $actor): array {
        $u = $this->owner((int)$actor['id']);
        if (!$u || empty($u['is_active']) || empty($u['email_verified_at'])) throw new SmsIntegrationException('VERIFIED_ACCOUNT_REQUIRED', 'Use a verified, active SMS Portal account.', 403);
        if (empty($u['security_enabled']) || !isset($actor['_sms_two_step_version']) || (int)$actor['_sms_two_step_version'] !== (int)$u['security_version']) throw new SmsIntegrationException('API_KEY_STEP_UP_REQUIRED', 'Enable the SMS Portal extra sign-in check in Security, then sign out and back in before creating an API key.', 403);
        return $u;
    }
    private function ownedApp(int $user, string $id, bool $lock = false): array {
        $app = $this->one('SELECT * FROM sms_integrations WHERE id=? AND user_id=?'.($lock ? $this->lock() : ''), [$id, $user]);
        if (!$app) throw new SmsIntegrationException('NOT_FOUND', 'Connection not found.', 404);
        return $app;
    }
    private function issue(array $actor, array $app, int $days): array {
        $u = $this->issuer($actor);
        if ($app['status'] !== 'active') throw new SmsIntegrationException('CONNECTION_REVOKED', 'This connection has been revoked.', 409);
        $count = (int)$this->query('SELECT COUNT(*) FROM sms_integration_keys WHERE integration_id=? AND user_id=? AND revoked_at IS NULL AND expires_at>?', [$app['id'], $actor['id'], $this->now()])->fetchColumn();
        if ($count >= 2) throw new SmsIntegrationException('KEY_LIMIT', 'Revoke an existing key first. Two keys may overlap during rotation.', 409);
        $id = bin2hex(random_bytes(16)); $secret = bin2hex(random_bytes(32)); $expires = $this->now() + $days * 86400;
        $this->query('INSERT INTO sms_integration_keys(id,integration_id,user_id,secret_hash,scopes_json,owner_auth_version,owner_security_version,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?)', [$id, $app['id'], $actor['id'], hash('sha256', $secret), $app['scopes_json'], (int)$u['auth_version'], (int)$u['security_version'], $this->now(), $expires]);
        $this->audit((int)$actor['id'], $app['id'], 'key_created', $id);
        return ['id' => $id, 'token' => 'smsk_'.$id.'.'.$secret, 'expires_at' => $expires, 'shown_once' => true];
    }
    public function connections(array $actor): array {
        if (!$this->enabled()) return ['available' => false, 'connections' => [], 'scopes' => SmsIntegrationPolicy::SCOPES, 'products' => SmsIntegrationPolicy::PRODUCTS];
        $u = $this->owner((int)$actor['id']);
        $apps = $this->query('SELECT id,user_id,name,product,status,daily_credit_limit,requests_per_minute,scopes_json,created_at,revoked_at FROM sms_integrations WHERE user_id=? ORDER BY created_at DESC', [$actor['id']])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($apps as &$app) {
            $app['scopes'] = json_decode($app['scopes_json'], true); unset($app['scopes_json']);
            $app['keys'] = $this->query('SELECT id,created_at,expires_at,revoked_at,last_used_at FROM sms_integration_keys WHERE integration_id=? AND user_id=? ORDER BY created_at DESC', [$app['id'], $actor['id']])->fetchAll(PDO::FETCH_ASSOC);
            $usage = $this->usage($app);
            $app['admitted_credits_today'] = (int)($usage['admitted_credits'] ?? 0);
            unset($app['user_id']);
        } unset($app);
        return ['available' => true, 'can_issue_keys' => !empty($u['security_enabled']) && isset($actor['_sms_two_step_version']) && (int)$actor['_sms_two_step_version'] === (int)$u['security_version'], 'connections' => $apps, 'scopes' => SmsIntegrationPolicy::SCOPES, 'products' => SmsIntegrationPolicy::PRODUCTS];
    }
    public function createConnection(array $actor, array $input): array {
        $this->ready(); $this->issuer($actor);
        SmsIntegrationPolicy::fields($input, ['name', 'product', 'scopes', 'daily_credit_limit', 'requests_per_minute', 'expires_in_days']);
        $name = SmsIntegrationPolicy::text($input['name'] ?? null, 'connection name', 80);
        $product = $input['product'] ?? 'custom';
        if (!in_array($product, SmsIntegrationPolicy::PRODUCTS, true)) throw new SmsIntegrationException('INVALID_PRODUCT', 'Choose a supported product label.');
        $scopes = SmsIntegrationPolicy::scopes($input['scopes'] ?? []);
        $limit = SmsIntegrationPolicy::integer($input['daily_credit_limit'] ?? 1000, 'daily_credit_limit', 1, 50000);
        $rate = SmsIntegrationPolicy::integer($input['requests_per_minute'] ?? 60, 'requests_per_minute', 1, 600);
        $days = SmsIntegrationPolicy::integer($input['expires_in_days'] ?? 90, 'expires_in_days', 1, 90);
        $this->pdo->beginTransaction();
        try {
            $this->query('SELECT id FROM users WHERE id=?'.$this->lock(), [$actor['id']]);
            if ((int)$this->query("SELECT COUNT(*) FROM sms_integrations WHERE user_id=? AND status='active'", [$actor['id']])->fetchColumn() >= 10) throw new SmsIntegrationException('CONNECTION_LIMIT', 'Revoke an existing connection first. Ten active connections are allowed.', 409);
            $id = bin2hex(random_bytes(16));
            $this->query("INSERT INTO sms_integrations(id,user_id,name,product,scopes_json,status,daily_credit_limit,requests_per_minute,created_at) VALUES(?,?,?,?,?,'active',?,?,?)", [$id, $actor['id'], $name, $product, json_encode($scopes), $limit, $rate, $this->now()]);
            $app = $this->ownedApp((int)$actor['id'], $id); $key = $this->issue($actor, $app, $days);
            $this->audit((int)$actor['id'], $id, 'connection_created'); $this->pdo->commit();
            return ['connection_id' => $id, 'credential' => $key];
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    public function rotate(array $actor, string $id): array {
        $this->ready(); $this->issuer($actor); $this->pdo->beginTransaction();
        try { $app = $this->ownedApp((int)$actor['id'], $id, true); $key = $this->issue($actor, $app, 90); $this->pdo->commit(); return ['credential' => $key]; }
        catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    public function revoke(array $actor, string $id, ?string $key = null): void {
        $this->ready(); $this->pdo->beginTransaction();
        try {
            $app = $this->ownedApp((int)$actor['id'], $id, true);
            if ($key !== null) {
                $exists = $this->one('SELECT id FROM sms_integration_keys WHERE id=? AND integration_id=? AND user_id=?', [$key, $id, $actor['id']]);
                if (!$exists) throw new SmsIntegrationException('NOT_FOUND', 'Key not found.', 404);
                $this->query('UPDATE sms_integration_keys SET revoked_at=COALESCE(revoked_at,?) WHERE id=? AND integration_id=? AND user_id=?', [$this->now(), $key, $id, $actor['id']]);
            } else {
                $this->query("UPDATE sms_integrations SET status='revoked',revoked_at=COALESCE(revoked_at,?) WHERE id=? AND user_id=?", [$this->now(), $id, $actor['id']]);
                $this->query('UPDATE sms_integration_keys SET revoked_at=COALESCE(revoked_at,?) WHERE integration_id=? AND user_id=?', [$this->now(), $id, $actor['id']]);
            }
            $this->audit((int)$actor['id'], $app['id'], $key === null ? 'connection_revoked' : 'key_revoked', $key); $this->pdo->commit();
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    private function credential(array $context, string $scope, bool $lock = false): array {
        $app = $this->one('SELECT * FROM sms_integrations WHERE id=? AND user_id=?'.($lock ? $this->lock() : ''), [$context['integration_id'], $context['user_id']]);
        $key = $this->one('SELECT * FROM sms_integration_keys WHERE id=? AND integration_id=? AND user_id=?'.($lock ? $this->lock() : ''), [$context['key_id'], $context['integration_id'], $context['user_id']]);
        $u = $this->owner((int)$context['user_id']);
        if (!$app || !$key || !$u || $app['status'] !== 'active' || $key['revoked_at'] !== null || (int)$key['expires_at'] <= $this->now() || empty($u['is_active']) || empty($u['email_verified_at']) || empty($u['security_enabled']) || (int)$u['auth_version'] !== (int)$key['owner_auth_version'] || (int)$u['security_version'] !== (int)$key['owner_security_version']) throw new SmsIntegrationException('INVALID_API_KEY', 'Invalid, expired or revoked API key.', 401);
        if (!in_array($scope, json_decode($key['scopes_json'], true) ?: [], true) || !in_array($scope, json_decode($app['scopes_json'], true) ?: [], true)) throw new SmsIntegrationException('INSUFFICIENT_SCOPE', 'This API key does not have the required permission.', 403);
        return $app;
    }
    public function authenticate(#[SensitiveParameter] string $token, string $scope): array {
        $this->ready();
        if (!preg_match('/^smsk_([a-f0-9]{32})\.([a-f0-9]{64})$/D', $token, $m)) throw new SmsIntegrationException('INVALID_API_KEY', 'Invalid, expired or revoked API key.', 401);
        $key = $this->one('SELECT id,integration_id,user_id,secret_hash FROM sms_integration_keys WHERE id=?', [$m[1]]);
        if (!hash_equals($key['secret_hash'] ?? str_repeat('0', 64), hash('sha256', $m[2])) || !$key) throw new SmsIntegrationException('INVALID_API_KEY', 'Invalid, expired or revoked API key.', 401);
        $context = ['key_id' => $key['id'], 'integration_id' => $key['integration_id'], 'user_id' => (int)$key['user_id']];
        $this->pdo->beginTransaction();
        try {
            $app = $this->credential($context, $scope, true); $window = intdiv($this->now(), 60);
            $count = (int)$app['rate_window'] === $window ? (int)$app['rate_count'] : 0;
            if ($count >= (int)$app['requests_per_minute']) throw new SmsIntegrationException('RATE_LIMITED', 'Connection request limit reached. Retry after the current minute.', 429);
            $this->query('UPDATE sms_integrations SET rate_window=?,rate_count=? WHERE id=? AND user_id=?', [$window, $count + 1, $app['id'], $context['user_id']]);
            $this->query('UPDATE sms_integration_keys SET last_used_at=? WHERE id=?', [$this->now(), $key['id']]);
            $this->pdo->commit(); return $context;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    private function usage(array $app): ?array {
        return $this->one('SELECT admitted_credits,campaign_count FROM sms_integration_usage WHERE integration_id=? AND user_id=? AND usage_date=?', [$app['id'], $app['user_id'], date('Y-m-d', $this->now())]);
    }
    public function quote(array $context, array $input): array {
        $this->ready(); $app = $this->credential($context, 'sms:quote'); $message = SmsIntegrationPolicy::message($input);
        if ($message['scheduled_at'] !== null) $this->credential($context, 'sms:schedule');
        SmsSchedule::fromInput($message, $this->now()); $p = CampaignService::preview($context['user_id'], $message);
        return ['entered' => $p['entered_count'], 'sendable' => $p['sendable_count'], 'invalid' => $p['invalid_count'], 'duplicates' => $p['duplicate_count'], 'opted_out' => $p['opted_out_count'], 'encoding' => $p['encoding'], 'credits_required' => $p['required_credits'], 'estimated_charge' => number_format($p['estimated_charge'], 4, '.', ''), 'currency' => 'ZAR', 'available_credits' => $p['available_credits'], 'sufficient_balance' => $p['sufficient_balance'], 'daily_credit_allowance_remaining' => max(0, (int)$app['daily_credit_limit'] - (int)($this->usage($app)['admitted_credits'] ?? 0)), 'binding' => false];
    }
    public function send(array $context, array $input, string $idempotency): array {
        $this->ready(); $this->credential($context, 'sms:send');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,99}$/D', $idempotency)) throw new SmsIntegrationException('IDEMPOTENCY_REQUIRED', 'Provide an Idempotency-Key header of 16 to 100 safe characters.');
        $message = SmsIntegrationPolicy::message($input);
        if ($message['scheduled_at'] !== null) $this->credential($context, 'sms:schedule');
        $idem = hash('sha256', $idempotency); $fingerprint = SmsIntegrationPolicy::fingerprint($message);
        $existing = $this->one('SELECT * FROM sms_integration_requests WHERE integration_id=? AND user_id=? AND idempotency_hash=?', [$context['integration_id'], $context['user_id'], $idem]);
        if ($existing) {
            if (!hash_equals($existing['fingerprint'], $fingerprint)) throw new SmsIntegrationException('IDEMPOTENCY_CONFLICT', 'This request key was already used with different message details.', 409);
            return ['replayed' => true, 'message' => $this->status($context, $existing['id'], false)];
        }
        SmsSchedule::fromInput($message, $this->now());
        $message['idempotency_key'] = 'xapi:'.hash('sha256', $context['user_id'].':'.$context['integration_id'].':'.$idem);
        $requestId = bin2hex(random_bytes(16));
        CampaignService::create($context['user_id'], $message, function(PDO $pdo, int $campaign, array $preview) use ($context, $message, $idem, $fingerprint, $requestId): void {
            if ($pdo !== $this->pdo || !$pdo->inTransaction()) throw new LogicException('Integration reservation must use the canonical transaction.');
            $app = $this->credential($context, 'sms:send', true);
            if ($message['scheduled_at'] !== null) $this->credential($context, 'sms:schedule');
            $credits = (int)$preview['required_credits']; $usage = $this->usage($app);
            if ($message['max_credits'] !== null && $credits > $message['max_credits']) throw new SmsIntegrationException('QUOTE_LIMIT_EXCEEDED', 'The message requires more credits than authorized.', 409);
            if ((int)($usage['admitted_credits'] ?? 0) + $credits > (int)$app['daily_credit_limit']) throw new SmsIntegrationException('DAILY_LIMIT_REACHED', 'Connection daily credit allowance reached.', 429);
            $day = date('Y-m-d', $this->now());
            if ($usage) $this->query('UPDATE sms_integration_usage SET admitted_credits=admitted_credits+?,campaign_count=campaign_count+1 WHERE integration_id=? AND user_id=? AND usage_date=?', [$credits, $app['id'], $context['user_id'], $day]);
            else $this->query('INSERT INTO sms_integration_usage(integration_id,user_id,usage_date,admitted_credits,campaign_count) VALUES(?,?,?,?,1)', [$app['id'], $context['user_id'], $day, $credits]);
            $this->query('INSERT INTO sms_integration_requests(id,integration_id,user_id,key_id,idempotency_hash,fingerprint,campaign_id,external_reference,admitted_credits,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', [$requestId, $app['id'], $context['user_id'], $context['key_id'], $idem, $fingerprint, $campaign, $message['external_reference'], $credits, $this->now()]);
            // Keep generated presentation data OUT of the canonical request
            // fingerprint. Concurrent retries can generate different IDs/times,
            // but must still converge on one campaign and one reservation.
            if ($message['name'] === '') $this->query('UPDATE sms_campaigns SET name=? WHERE id=? AND user_id=?', [SmsIntegrationPresentation::campaignName($app['name'], $requestId, $this->now()), $campaign, $context['user_id']]);
            $this->audit($context['user_id'], $app['id'], 'message_queued', $context['key_id'], $requestId);
        });
        $stored = $this->one('SELECT id,fingerprint FROM sms_integration_requests WHERE integration_id=? AND user_id=? AND idempotency_hash=?', [$context['integration_id'], $context['user_id'], $idem]);
        if (!$stored || !hash_equals($stored['fingerprint'], $fingerprint)) throw new SmsIntegrationException('IDEMPOTENCY_CONFLICT', 'The request could not be associated safely. Contact support; do not change the request key.', 409);
        return ['replayed' => $stored['id'] !== $requestId, 'message' => $this->status($context, $stored['id'], false)];
    }
    private function request(array $context, string $id): array {
        $row = $this->one('SELECT r.id,r.campaign_id,r.external_reference,r.created_at,r.admitted_credits,c.state,c.scheduled_at FROM sms_integration_requests r JOIN sms_campaigns c ON c.id=r.campaign_id AND c.user_id=r.user_id WHERE r.id=? AND r.integration_id=? AND r.user_id=?', [$id, $context['integration_id'], $context['user_id']]);
        if (!$row) throw new SmsIntegrationException('NOT_FOUND', 'Message not found.', 404); return $row;
    }
    public function status(array $context, string $id, bool $checkScope = true): array {
        $this->ready(); if ($checkScope) $this->credential($context, 'sms:read'); $row = $this->request($context, $id);
        $accepted = SmsMessageState::acceptedSql('m'); $failed = SmsMessageState::submissionFailureSql('m');
        // Cancellation leaves unsent message rows intact. A live lease or a
        // retry is NOT proof of non-submission, even on a cancelled campaign.
        $counts = $this->one("SELECT COUNT(*) AS recipients,SUM(CASE WHEN {$accepted} THEN 1 ELSE 0 END) AS accepted,SUM(CASE WHEN NOT {$accepted} AND ({$failed} OR m.state='skipped' OR (c.state='cancelled' AND m.state IN ('pending','queued'))) THEN 1 ELSE 0 END) AS not_sent FROM sms_messages m JOIN sms_campaigns c ON c.id=m.campaign_id AND c.user_id=m.user_id WHERE m.campaign_id=? AND m.user_id=?", [$row['campaign_id'], $context['user_id']]);
        return SmsIntegrationPresentation::result($row, $counts);
    }
    public function balance(array $context): array {
        $this->ready(); $this->credential($context, 'wallet:read');
        $wallet = $this->one('SELECT balance,reserved FROM wallets WHERE user_id=?', [$context['user_id']]);
        $available = max(0, round((float)($wallet['balance'] ?? 0) - (float)($wallet['reserved'] ?? 0), 4));
        return ['available_credits' => (int)floor(($available + .000001) / SmsPricing::pricePerSegment()), 'available_amount' => number_format($available, 4, '.', ''), 'currency' => 'ZAR'];
    }
    public function cancel(array $context, string $id): array {
        $this->ready(); $this->pdo->beginTransaction();
        try {
            $row = $this->request($context, $id);
            $campaign = $this->one('SELECT * FROM sms_campaigns WHERE id=? AND user_id=?'.$this->lock(), [$row['campaign_id'], $context['user_id']]);
            // Match dispatch's campaign -> wallet -> integration lock order.
            $this->query('SELECT id FROM wallets WHERE user_id=?'.$this->lock(), [$context['user_id']]);
            $this->credential($context, 'sms:cancel', true);
            if ($campaign['state'] !== 'cancelled') {
                if (!in_array($campaign['state'], ['queued', 'scheduled'], true)) throw new SmsIntegrationException('CANNOT_CANCEL', 'Sending has started or finished; this message cannot be cancelled.', 409);
                $this->query("UPDATE sms_campaigns SET state='cancelled',cancelled_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=?", [$campaign['id'], $context['user_id']]);
                WalletService::release($this->pdo, $context['user_id'], (int)$campaign['id'], (float)$campaign['estimated_cost'], 'cancel');
                $this->audit($context['user_id'], $context['integration_id'], 'message_cancelled', $context['key_id'], $id);
            }
            $this->pdo->commit(); return $this->status($context, $id, false);
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    public function usageReport(array $context): array {
        $this->ready(); $app = $this->credential($context, 'sms:read'); $usage = $this->usage($app);
        return ['date' => date('Y-m-d', $this->now()), 'timezone' => date_default_timezone_get(), 'admitted_credits' => (int)($usage['admitted_credits'] ?? 0), 'campaigns' => (int)($usage['campaign_count'] ?? 0), 'daily_credit_limit' => (int)$app['daily_credit_limit'], 'cancelled_and_failed_requests_count_toward_allowance' => true];
    }
}
