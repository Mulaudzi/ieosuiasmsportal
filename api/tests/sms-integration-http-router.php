<?php
declare(strict_types=1);
// Synthetic loopback-only HTTP fixture, not a production route or auth override.
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || getenv('SMS_API_HTTP_FIXTURE') !== '1') { http_response_code(404); exit; }
require __DIR__.'/sms-integration-fixture.php';
require __DIR__.'/../core/Router.php';
require __DIR__.'/../core/Response.php';
require __DIR__.'/../core/RateLimiter.php';
require __DIR__.'/../routes/SmsIntegrationRoutes.php';
[$p, $service, $actor] = integrationFixture();
$created = $service->createConnection($actor, ['name' => 'HTTP fixture', 'product' => 'custom', 'scopes' => SmsIntegrationPolicy::SCOPES, 'daily_credit_limit' => 1000, 'requests_per_minute' => 600]);
$q = $p->prepare('UPDATE sms_integration_keys SET id=?,secret_hash=? WHERE id=?');
$q->execute([str_repeat('a', 32), hash('sha256', str_repeat('b', 64)), $created['credential']['id']]);
// Deterministic, request-local fixture for GET/cancel wire-contract coverage.
// Never reachable through the production bootstrap or a non-loopback server.
if (str_starts_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/api/v1/sms/messages/'.str_repeat('d', 32))) {
    $ctx = ['user_id' => 1, 'integration_id' => $created['connection_id'], 'key_id' => str_repeat('a', 32)];
    $seed = $service->send($ctx, ['content' => 'Synthetic wire result', 'recipients' => ['0820000001', '0820000002']], 'http-status-fixture-seed');
    $q = $p->prepare('UPDATE sms_integration_requests SET id=? WHERE id=?'); $q->execute([str_repeat('d', 32), $seed['message']['id']]);
    $scenario = $_SERVER['HTTP_X_SYNTHETIC_RESULT'] ?? 'queued';
    if ($scenario === 'sent') $p->exec("UPDATE sms_messages SET state='sent',sent_at=NOW()");
    if ($scenario === 'not_sent') $p->exec("UPDATE sms_messages SET state='failed'");
    if ($scenario === 'partially_sent') $p->exec("UPDATE sms_messages SET state='failed'; UPDATE sms_messages SET state='sent',sent_at=NOW() WHERE destination='+27820000001'");
    if ($scenario === 'held') $p->exec("UPDATE sms_messages SET state='retry',next_attempt_at='9999-12-31 23:59:59',last_error='PRIVATE provider diagnostics'; UPDATE sms_campaigns SET state='completed'");
}
if (isset($_SERVER['HTTP_X_SYNTHETIC_DISABLED'])) $GLOBALS['integration_env']['SMS_INTEGRATION_API_ENABLED'] = 'false';
$_SERVER['HTTPS'] = isset($_SERVER['HTTP_X_SYNTHETIC_HTTP']) ? 'off' : 'on';
$rateDirectory = getenv('SMS_API_RATE_FIXTURE');
if (!$rateDirectory || !str_contains($rateDirectory, 'sms-api-http-')) throw new RuntimeException('Private fixture rate directory required');
(new ReflectionProperty(RateLimiter::class, 'cacheDir'))->setValue(null, $rateDirectory);
final class Auth {
    public static function check(): void { if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer synthetic-portal-session') Response::error('Unauthorized', 401); }
    public static function user(): array { return ['id' => 1, 'is_active' => 1, 'email_verified_at' => '2026-11-01', '_sms_two_step_version' => 1]; }
}
header('Content-Type: application/json');
$router = new Router();
$router->group(['middleware' => 'auth'], fn($r) => SmsIntegrationRoutes::portal($r));
SmsIntegrationRoutes::external($router); $router->dispatch();
