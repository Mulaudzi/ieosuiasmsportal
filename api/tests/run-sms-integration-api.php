<?php
declare(strict_types=1);
require __DIR__.'/sms-integration-fixture.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
$checks = 0;
function apiCheck(bool $ok, string $name): void { global $checks; $checks++; if (!$ok) throw new RuntimeException('FAIL: '.$name); }
function apiReject(callable $action, int $status, string $name): void {
    try { $action(); throw new RuntimeException('Not rejected: '.$name); }
    catch (SmsIntegrationException $e) { apiCheck($e->status === $status, $name); }
}
function snapshot(PDO $p): string {
    $tables = ['sms_campaigns','sms_messages','wallets','wallet_ledger','sms_daily_quota_usage','sms_integration_requests','sms_integration_usage'];
    return json_encode(array_map(fn($table) => $p->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(), $tables));
}
function appInput(array $extra = []): array { return array_replace(['name' => 'Events fixture', 'product' => 'events', 'scopes' => SmsIntegrationPolicy::SCOPES, 'daily_credit_limit' => 100, 'requests_per_minute' => 600], $extra); }
function messageInput(array $extra = []): array { return array_replace(['content' => 'Synthetic message', 'recipients' => ['0820000001'], 'external_reference' => 'event:fixture'], $extra); }

[$p, $s, $actor, $clock] = integrationFixture();
$GLOBALS['integration_env']['SMS_INTEGRATION_API_ENABLED'] = 'false';
apiCheck($s->connections($actor)['available'] === false, 'Feature is inert before activation');
apiReject(fn() => $s->createConnection($actor, appInput()), 503, 'Disabled feature cannot issue credentials');
$GLOBALS['integration_env']['SMS_INTEGRATION_API_ENABLED'] = 'true';
apiReject(fn() => $s->createConnection(['id' => 1], appInput()), 403, 'Portal session without verified step-up cannot issue keys');
foreach ([3,4] as $id) apiReject(fn() => $s->createConnection(['id' => $id, '_sms_two_step_version' => 1], appInput()), 403, 'Inactive/unverified issuer denied');
foreach ([['user_id' => 2], ['payer_id' => 2], ['scopes' => ['admin:*']], ['daily_credit_limit' => -1], ['requests_per_minute' => 0], ['expires_in_days' => 91], ['product' => []]] as $bad) apiReject(fn() => $s->createConnection($actor, appInput($bad)), 422, 'Invalid connection policy rejected');
$created = $s->createConnection($actor, appInput()); $id = $created['connection_id']; $token = $created['credential']['token'];
apiCheck((bool)preg_match('/^smsk_[a-f0-9]{32}\.[a-f0-9]{64}$/D', $token), 'Credential has a random identifier and 256-bit secret');
apiCheck(!str_contains(json_encode($p->query('SELECT * FROM sms_integration_keys')->fetchAll()), substr($token, -64)), 'Database contains no recoverable key secret');
apiCheck(!str_contains(json_encode($s->connections($actor)), 'secret_hash') && !str_contains(json_encode($s->connections($actor)), $token), 'List never exposes secrets or hashes');
apiReject(fn() => $s->authenticate($token.'x', 'sms:send'), 401, 'Malformed token rejected');
apiReject(fn() => $s->authenticate(substr($token, 0, -1).($token[-1] === '0' ? '1' : '0'), 'sms:send'), 401, 'Wrong secret rejected');
$context = $s->authenticate($token, 'sms:send');
$before = snapshot($p);
$quote = $s->quote($context, messageInput(['recipients' => ['0820000001', '+27820000001', 'bad', '0820000004']]));
apiCheck($quote['entered'] === 4 && $quote['sendable'] === 1 && $quote['duplicates'] === 1 && $quote['invalid'] === 1 && $quote['opted_out'] === 1, 'Quote uses existing normalization, dedupe and suppression');
apiCheck(snapshot($p) === $before, 'Quote has no reservations or financial writes');
$unicode = $s->quote($context, messageInput(['content' => str_repeat('漢', 71)]));
$footer = $s->quote($context, messageInput(['content' => str_repeat('a', 155), 'include_opt_out' => true]));
apiCheck($unicode['encoding'] === 'ucs2' && $unicode['credits_required'] === 2 && $footer['credits_required'] === 2, 'API quotes preserve Unicode and enabled-footer segment accounting');
foreach ([['user_id' => 2], ['sender_id' => 'SPOOF'], ['payer_user_id' => 2], ['recipients' => ['contact_ids' => [1]]], ['include_opt_out' => 'false'], ['content' => '{{email}}'], ['scheduled_at' => '2099-01-01T11:00']] as $bad) apiReject(fn() => $s->send($context, messageInput($bad), 'fixture-invalid-request'), 422, 'Unsafe or ambiguous message fields rejected');
apiReject(fn() => $s->send($context, messageInput(), ''), 422, 'Idempotency is mandatory');
$other = $p->query('SELECT * FROM wallets WHERE user_id=2')->fetch();
$first = $s->send($context, messageInput(['recipients' => ['0820000001', '0820000002']]), 'fixture-send-request-001');
$messageId = $first['message']['id'];
apiCheck($first['replayed'] === false && $first['message']['state'] === 'queued' && $first['message']['recipients'] === 2, 'Send queues the canonical campaign, never provider traffic');
apiCheck((float)$p->query('SELECT reserved FROM wallets WHERE user_id=1')->fetchColumn() === .7 && (float)$p->query('SELECT balance FROM wallets WHERE user_id=1')->fetchColumn() === 350.0, 'Authorizing wallet reserves once without a premature charge');
apiCheck($p->query('SELECT * FROM wallets WHERE user_id=2')->fetch() === $other, 'Other payer balance and reservation unchanged');
apiCheck($s->balance($context)['available_credits'] === 998, 'Balance excludes reserved credits');
apiCheck((int)$p->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn() === 1 && $s->usageReport($context)['admitted_credits'] === 2, 'One existing ledger reservation and atomic app attribution');
apiCheck($s->connections($actor)['connections'][0]['admitted_credits_today'] === 2, 'Settings uses real connection usage rather than an unscoped or empty count');
$before = snapshot($p); $repeat = $s->send($context, messageInput(['recipients' => ['0820000002', '0820000001']]), 'fixture-send-request-001');
apiCheck($repeat['replayed'] === true && $repeat['message']['id'] === $messageId && snapshot($p) === $before, 'Reordered retry returns original without reservation or quota duplication');
apiReject(fn() => $s->send($context, messageInput(['content' => 'Different']), 'fixture-send-request-001'), 409, 'Changed payload with same request key conflicts');
$rotated = $s->rotate($actor, $id); $rotatedContext = $s->authenticate($rotated['credential']['token'], 'sms:send');
$repeat = $s->send($rotatedContext, messageInput(['recipients' => ['0820000001', '0820000002']]), 'fixture-send-request-001');
apiCheck($repeat['replayed'] && snapshot($p) === $before, 'Idempotency survives credential rotation');
apiReject(fn() => $s->rotate($actor, $id), 409, 'At most two active keys may overlap');
$s->revoke($actor, $id, $created['credential']['id']);
apiReject(fn() => $s->authenticate($token, 'sms:send'), 401, 'Revoked key immediately loses access');
$token = $rotated['credential']['token']; $context = $s->authenticate($token, 'sms:send');
$secondApp = $s->createConnection($actor, appInput(['name' => 'Website fixture'])); $secondContext = $s->authenticate($secondApp['credential']['token'], 'sms:read');
apiReject(fn() => $s->status($secondContext, $messageId), 404, 'Different connection cannot read the same owner’s messages');
$actor2 = ['id' => 2, '_sms_two_step_version' => 1]; $foreign = $s->createConnection($actor2, appInput()); $foreignContext = $s->authenticate($foreign['credential']['token'], 'sms:read');
apiReject(fn() => $s->status($foreignContext, $messageId), 404, 'Different account cannot read messages');
apiReject(fn() => $s->revoke($actor2, $id), 404, 'Different account cannot revoke connections');
apiReject(fn() => $s->revoke($actor, $id, $foreign['credential']['id']), 404, 'Different account key cannot be revoked through an owned connection');
$readonly = $s->createConnection($actor, appInput(['scopes' => ['sms:read']]));
apiReject(fn() => $s->authenticate($readonly['credential']['token'], 'sms:send'), 403, 'Read-only key cannot send');
$unscheduled = $s->createConnection($actor, appInput(['scopes' => ['sms:send']])); $unscheduledContext = $s->authenticate($unscheduled['credential']['token'], 'sms:send');
apiReject(fn() => $s->send($unscheduledContext, messageInput(['scheduled_at' => '2099-01-01T11:00:00+02:00']), 'fixture-scope-schedule'), 403, 'Scheduling requires its own permission');
$scheduled = $s->send($context, messageInput(['scheduled_at' => '2099-01-01T11:00:00+02:00']), 'fixture-scheduled-001');
apiCheck($scheduled['message']['status'] === 'queued' && !$scheduled['message']['complete'] && $scheduled['message']['scheduled_at'] === '2099-01-01T11:00:00+02:00', 'Scheduled request stays queued with its explicit schedule');
$cancel = $s->cancel($context, $scheduled['message']['id']);
apiCheck($cancel['status'] === 'not_sent' && $cancel['complete'], 'Scoped cancellation returns a final not-sent result using the existing release ledger');
$before = snapshot($p); $s->cancel($context, $scheduled['message']['id']);
apiCheck(snapshot($p) === $before, 'Cancellation replay releases nothing twice');
$campaign = (int)$p->query("SELECT campaign_id FROM sms_integration_requests WHERE id='{$messageId}'")->fetchColumn();
$p->exec("UPDATE sms_campaigns SET state='processing' WHERE id={$campaign}");
apiReject(fn() => $s->cancel($context, $messageId), 409, 'In-progress campaign cannot be cancelled');
$before = snapshot($p);
apiReject(fn() => $s->send($context, messageInput(['content' => str_repeat('a', 161), 'max_credits' => 1]), 'fixture-credit-cap-001'), 409, 'Actual segment cost may not exceed caller-authorized credits');
apiCheck(snapshot($p) === $before, 'Caller credit-cap rejection rolls back campaign, quota, wallet, attribution and budget');
$p->exec("UPDATE sms_integrations SET daily_credit_limit=3 WHERE id='{$id}'");
apiReject(fn() => $s->send($context, messageInput(), 'fixture-daily-cap-001'), 429, 'Daily admitted-credit ceiling enforced');
apiCheck(snapshot($p) === $before, 'Daily-cap failure rolls back every financial side effect');
$p->exec("UPDATE sms_integrations SET daily_credit_limit=100 WHERE id='{$id}'");
$p->exec("CREATE TRIGGER fail_integration_audit BEFORE INSERT ON sms_integration_audit WHEN NEW.event='message_queued' BEGIN SELECT RAISE(ABORT,'synthetic audit unavailable');END");
try { $s->send($context, messageInput(), 'fixture-audit-failure-001'); apiCheck(false, 'Missing audit accepted'); } catch (PDOException) { apiCheck(snapshot($p) === $before, 'Audit failure rolls back the entire reservation'); }
$p->exec('DROP TRIGGER fail_integration_audit');
$p->exec("CREATE TRIGGER revoke_during_admission BEFORE INSERT ON sms_messages BEGIN UPDATE sms_integrations SET status='revoked' WHERE id='{$id}';END");
apiReject(fn() => $s->send($context, messageInput(), 'fixture-revoke-race-001'), 401, 'Credential authority is rechecked within the actual reservation transaction');
apiCheck(snapshot($p) === $before, 'Revocation observed during admission rolls back all financial side effects');
$p->exec('DROP TRIGGER revoke_during_admission');
$p->exec("UPDATE sms_messages SET state='failed',sent_at=NOW(),actual_charge=.35 WHERE campaign_id={$campaign} AND destination='+27820000001'; UPDATE sms_messages SET state='delivered',sent_at=NOW(),delivered_at=NOW(),actual_charge=.35 WHERE campaign_id={$campaign} AND destination='+27820000002'");
$beforeStatus = snapshot($p); $delivery = $s->status($context, $messageId);
apiCheck($delivery['status'] === 'sent' && $delivery['complete'] && !isset($delivery['delivered'], $delivery['held']), 'API returns sending confirmation without exposing the detailed delivery breakdown');
$portalDelivery = CampaignService::findOwned(1, $campaign);
apiCheck($portalDelivery['sent_count'] === 2 && $portalDelivery['delivered_count'] === 1 && $portalDelivery['failed_count'] === 1 && $portalDelivery['failed_submission_count'] === 0, 'Portal retains the real acceptance and delivery breakdown');
apiCheck(snapshot($p) === $beforeStatus, 'Delivery status retrieval is read-only');
$p->exec("UPDATE users SET auth_version=2 WHERE id=1");
apiReject(fn() => $s->authenticate($token, 'sms:read'), 401, 'Password/auth-version change invalidates old machine credentials');
$p->exec("UPDATE users SET auth_version=1 WHERE id=1; UPDATE sms_login_security SET version=2 WHERE user_id=1");
apiReject(fn() => $s->authenticate($token, 'sms:read'), 401, 'Security-setting change invalidates machine credentials');
$p->exec("UPDATE sms_login_security SET version=1 WHERE user_id=1; UPDATE users SET is_active=0 WHERE id=1");
apiReject(fn() => $s->authenticate($token, 'sms:read'), 401, 'Account deactivation stops machine access');
$p->exec('UPDATE users SET is_active=1 WHERE id=1');
$p->exec("UPDATE sms_integrations SET requests_per_minute=1,rate_count=0 WHERE id='{$id}'");
$s->authenticate($token, 'sms:read'); apiReject(fn() => $s->authenticate($token, 'sms:read'), 429, 'Atomic per-connection request limit enforced');
$p->exec("UPDATE sms_integrations SET requests_per_minute=600 WHERE id='{$id}'; UPDATE sms_integration_keys SET expires_at=1 WHERE id='{$rotated['credential']['id']}'");
apiReject(fn() => $s->authenticate($token, 'sms:read'), 401, 'Expired keys denied');
$s->revoke($actor, $secondApp['connection_id']);
apiReject(fn() => $s->authenticate($secondApp['credential']['token'], 'sms:read'), 401, 'Connection revocation invalidates all its keys');
$serializedAudit = json_encode($p->query('SELECT * FROM sms_integration_audit')->fetchAll());
apiCheck(!str_contains($serializedAudit, '0820000001') && !str_contains($serializedAudit, 'Synthetic message') && !str_contains($serializedAudit, $token), 'Audit trail contains no phone numbers, message bodies or key secrets');
$GLOBALS['integration_env']['SMS_INTEGRATION_API_ENABLED'] = 'false';
$mappingCount = (int)$p->query('SELECT COUNT(*) FROM sms_integration_requests')->fetchColumn();
$portal = CampaignService::create(1, ['idempotency_key' => 'portal-compatibility-001', 'content' => 'Existing portal fixture', 'recipients' => ['manual' => ['0820000001']], 'send_now' => true]);
apiCheck($portal['state'] === 'queued' && (int)$p->query('SELECT COUNT(*) FROM sms_integration_requests')->fetchColumn() === $mappingCount, 'Existing portal creation works unchanged with external API disabled');
echo json_encode(['passed' => true, 'checks' => $checks, 'fixture' => 'isolated SQLite with real campaign/wallet/integration code', 'real_sms_sent' => false, 'production_access' => false], JSON_PRETTY_PRINT).PHP_EOL;
