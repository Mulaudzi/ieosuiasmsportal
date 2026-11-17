<?php
declare(strict_types=1);
require_once __DIR__.'/../domain/SmsIntegrationService.php';

/** Browser management uses portal Auth; external endpoints use API credentials only. */
final class SmsIntegrationController
{
    private bool $portalMode = false;
    private function respond(callable $action, int $status = 200): void {
        header('Cache-Control: no-store'); header('Pragma: no-cache'); header('Referrer-Policy: no-referrer');
        try {
            if (!in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)) throw new SmsIntegrationException('HTTPS_REQUIRED', 'Use HTTPS for API access and credential management.', 400);
            Response::success($action(), $status);
        }
        catch (SmsIntegrationException $e) {
            if ($e->status === 429) header('Retry-After: '.(60 - time() % 60));
            if ($e->status === 401 && !$this->portalMode) header('WWW-Authenticate: Bearer realm="IEOSUIA SMS"');
            Response::json(['success' => false, 'message' => $e->getMessage(), 'error' => $this->portalMode ? $e->getMessage() : ['code' => $e->errorCode, 'message' => $e->getMessage()], 'code' => $e->errorCode], $e->status);
        }
        catch (InvalidArgumentException $e) { Response::json(['success' => false, 'error' => ['code' => 'INVALID_REQUEST', 'message' => $e->getMessage()]], 422); }
        catch (DomainException) { Response::json(['success' => false, 'error' => ['code' => 'MESSAGE_NOT_ACCEPTED', 'message' => 'The message exceeds the current account allowance or available credits.']], 409); }
        catch (Throwable $e) {
            error_log('SMS integration request failed ['.get_class($e).']'); // Never log input, keys or database error details.
            Response::json(['success' => false, 'error' => ['code' => 'API_UNAVAILABLE', 'message' => 'SMS API access is temporarily unavailable. Retry the same request key; do not create a new one.']], 503);
        }
    }
    private function jsonBody(): array {
        if (!preg_match('#^application/json(?:\s*;|$)#i', $_SERVER['CONTENT_TYPE'] ?? '')) throw new SmsIntegrationException('JSON_REQUIRED', 'Use Content-Type: application/json.', 415);
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) throw new SmsIntegrationException('REQUEST_TOO_LARGE', 'Request must not exceed 64 KiB.', 413);
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if ($raw === false || strlen($raw) > 65536) throw new SmsIntegrationException('REQUEST_TOO_LARGE', 'Request must not exceed 64 KiB.', 413);
        try { $object = json_decode($raw, false, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new SmsIntegrationException('INVALID_JSON', 'Provide a valid JSON object.'); }
        if (!$object instanceof stdClass) throw new SmsIntegrationException('INVALID_JSON', 'Provide a JSON object.');
        return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    }
    private function portal(): array {
        $this->portalMode = true;
        // Keep this explicit in addition to route middleware. API keys never become portal sessions.
        Auth::check(); $user = Auth::user();
        if (!$user || empty($user['is_active']) || empty($user['email_verified_at'])) throw new SmsIntegrationException('VERIFIED_ACCOUNT_REQUIRED', 'A verified active SMS Portal account is required.', 403);
        return $user;
    }
    private function external(string $scope, callable $action): array {
        // Credentials belong on the calling application's backend, never browser JavaScript.
        if (isset($_SERVER['HTTP_ORIGIN'])) throw new SmsIntegrationException('SERVER_SIDE_ONLY', 'Use this API from your server, not from browser JavaScript.', 403);
        if (!RateLimiter::attempt('sms-api-auth:'.hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown'), 120, 1)) throw new SmsIntegrationException('RATE_LIMITED', 'Authentication request limit reached.', 429);
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (strlen($header) > 120 || !preg_match('/^Bearer (smsk_[a-f0-9]{32}\.[a-f0-9]{64})$/D', $header, $m)) throw new SmsIntegrationException('INVALID_API_KEY', 'Invalid, expired or revoked API key.', 401);
        if (array_intersect(array_keys($_GET), ['token', 'key', 'api_key', 'access_token'])) throw new SmsIntegrationException('HEADER_AUTH_REQUIRED', 'Supply the credential only in the Authorization header.');
        if ($_GET) throw new SmsIntegrationException('INVALID_REQUEST', 'This endpoint does not accept query parameters.');
        $service = new SmsIntegrationService(db()); $context = $service->authenticate($m[1], $scope);
        return $action($service, $context);
    }
    public function connections(): void { $this->respond(fn() => (new SmsIntegrationService(db()))->connections($this->portal())); }
    public function create(): void { $this->respond(function() { $u = $this->portal(); return (new SmsIntegrationService(db()))->createConnection($u, $this->jsonBody()); }, 201); }
    public function rotate(array $p): void { $this->respond(function() use ($p) { $u = $this->portal(); return (new SmsIntegrationService(db()))->rotate($u, $p['id']); }, 201); }
    public function revoke(array $p): void { $this->respond(function() use ($p) { (new SmsIntegrationService(db()))->revoke($this->portal(), $p['id']); return ['revoked' => true]; }); }
    public function revokeKey(array $p): void { $this->respond(function() use ($p) { (new SmsIntegrationService(db()))->revoke($this->portal(), $p['id'], $p['key']); return ['revoked' => true]; }); }
    public function quote(): void { $this->respond(fn() => $this->external('sms:quote', fn($s, $c) => ['quote' => $s->quote($c, $this->jsonBody())])); }
    public function send(): void { $this->respond(fn() => $this->external('sms:send', fn($s, $c) => $s->send($c, $this->jsonBody(), $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')), 202); }
    public function status(array $p): void { $this->respond(fn() => $this->external('sms:read', fn($s, $c) => ['message' => $s->status($c, $p['id'])])); }
    public function cancel(array $p): void { $this->respond(fn() => $this->external('sms:cancel', fn($s, $c) => ['message' => $s->cancel($c, $p['id'])])); }
    public function balance(): void { $this->respond(fn() => $this->external('wallet:read', fn($s, $c) => ['balance' => $s->balance($c)])); }
    public function usage(): void { $this->respond(fn() => $this->external('sms:read', fn($s, $c) => ['usage' => $s->usageReport($c)])); }
}
