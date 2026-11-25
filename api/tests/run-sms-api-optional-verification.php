<?php
declare(strict_types=1);
// Disposable SQLite only; no application bootstrap, .env, provider or live SMS.
require __DIR__.'/sms-integration-fixture.php';
$checks = 0;
function optionalCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
function optionalReject(callable $call, int $status, string $label): void {
    try { $call(); } catch (SmsIntegrationException $error) {
        optionalCheck($error->status === $status, $label);
        return;
    }
    throw new RuntimeException('Not rejected: '.$label);
}
[$pdo, $service, $enabledActor] = integrationFixture();
$actor = ['id' => 1];
$input = ['name' => 'Synthetic optional verification', 'product' => 'custom',
    'scopes' => SmsIntegrationPolicy::SCOPES, 'daily_credit_limit' => 100, 'requests_per_minute' => 600];
$before = [
    $pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll(),
    $pdo->query('SELECT * FROM wallet_ledger ORDER BY id')->fetchAll(),
    $pdo->query('SELECT * FROM sms_campaigns ORDER BY id')->fetchAll(),
];
$pdo->exec('DELETE FROM sms_login_security WHERE user_id=1');
optionalCheck($service->connections($actor)['can_issue_keys'] === true, 'Default-off customer may issue keys without enrollment');
$created = $service->createConnection($actor, $input);
$token = $created['credential']['token'];
optionalCheck((int)$pdo->query('SELECT owner_security_version FROM sms_integration_keys WHERE user_id=1')->fetchColumn() === 0, 'Absent security row has default zero credential version');
$context = $service->authenticate($token, 'sms:read');
optionalCheck($context['user_id'] === 1, 'Default-off API key authenticates to its owner');
$quote = $service->quote($context, ['content' => 'Synthetic local quote', 'recipients' => ['0820000001']]);
optionalCheck($quote['credits_required'] === 1, 'Default-off API key can quote using normal policy');
$rotated = $service->rotate($actor, $created['connection_id']);
optionalCheck($service->authenticate($rotated['credential']['token'], 'sms:read')['user_id'] === 1, 'Default-off customer may rotate and use a key');
optionalCheck((int)$pdo->query('SELECT COUNT(*) FROM sms_login_security WHERE user_id=1')->fetchColumn() === 0, 'Issuance and rotation never enroll customer automatically');
$pdo->exec("INSERT INTO sms_login_security(user_id,enabled,version,updated_at) VALUES(1,1,1,NOW())");
optionalReject(fn() => $service->authenticate($token, 'sms:read'), 401, 'Enabling extra check invalidates previous security version');
optionalCheck($service->connections($actor)['can_issue_keys'] === false, 'Opted-in account still requires its completed sign-in check');
optionalReject(fn() => $service->createConnection($actor, $input), 403, 'Opted-in account cannot bypass its own verification during key creation');
optionalReject(fn() => $service->rotate($actor, $created['connection_id']), 403, 'Opted-in account cannot bypass verification during rotation');
optionalReject(fn() => $service->createConnection(['id' => 1, '_sms_two_step_version' => 0], $input), 403, 'Stale verification proof denied');
optionalCheck($service->connections($enabledActor)['can_issue_keys'] === true, 'Matching proof allows opted-in customer');
$secure = $service->createConnection($enabledActor, [...$input, 'name' => 'Synthetic enabled verification']);
optionalCheck($service->authenticate($secure['credential']['token'], 'sms:read')['user_id'] === 1, 'Opted-in completed verification key works');
$pdo->exec('UPDATE sms_login_security SET enabled=0,version=2 WHERE user_id=1');
optionalReject(fn() => $service->authenticate($secure['credential']['token'], 'sms:read'), 401, 'Disabling still invalidates keys bound to the previous security version');
optionalCheck($service->connections($actor)['can_issue_keys'] === true, 'Customer choosing to disable can issue a new key without reenrollment');
$off = $service->createConnection($actor, [...$input, 'name' => 'Synthetic chosen disabled']);
optionalCheck($service->authenticate($off['credential']['token'], 'sms:read')['user_id'] === 1, 'New key works with explicitly disabled verification');
foreach ([3, 4] as $id) {
    $pdo->exec('UPDATE sms_login_security SET enabled=0 WHERE user_id='.$id);
    optionalCheck($service->connections(['id' => $id])['can_issue_keys'] === false, 'Inactive or unverified accounts are not eligible');
    optionalReject(fn() => $service->createConnection(['id' => $id], $input), 403, 'Account activation and verified email remain required');
}
$service->revoke($actor, $off['connection_id']);
optionalReject(fn() => $service->authenticate($off['credential']['token'], 'sms:read'), 401, 'Explicit revocation still stops API access');
optionalCheck($before === [
    $pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll(),
    $pdo->query('SELECT * FROM wallet_ledger ORDER BY id')->fetchAll(),
    $pdo->query('SELECT * FROM sms_campaigns ORDER BY id')->fetchAll(),
], 'Issuance, authentication and quote leave wallet, ledger and campaigns unchanged');
echo json_encode(['ok' => true, 'checks' => $checks, 'environment' => 'disposable SQLite', 'live_sends' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
