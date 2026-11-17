<?php
declare(strict_types=1);
require __DIR__.'/sms-integration-fixture.php';
require __DIR__.'/../services/SmsIntegrationCampaignSource.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
$checks = 0;
function resultCheck(bool $ok, string $name): void { global $checks; $checks++; if (!$ok) throw new RuntimeException('FAIL: '.$name); }
[$p, $service, $actor] = integrationFixture();
$connection = $service->createConnection($actor, ['name' => 'Blu-T production', 'product' => 'website', 'scopes' => SmsIntegrationPolicy::SCOPES]);
$context = $service->authenticate($connection['credential']['token'], 'sms:send');
$input = ['content' => 'Synthetic status only', 'recipients' => ['0820000001', '0820000002'], 'external_reference' => 'blu-t:order:fixture'];
$receipt = $service->send($context, $input, 'blu-t-synthetic-results-001'); $id = $receipt['message']['id'];
$campaignId = (int)$p->query("SELECT campaign_id FROM sms_integration_requests WHERE id='$id'")->fetchColumn();
$campaign = CampaignService::findOwned(1, $campaignId);
resultCheck(str_starts_with($campaign['name'], 'API | Blu-T production | 05 Nov 2026 12:00 SAST | '), 'Default name identifies connection and South African time');
resultCheck(str_ends_with($campaign['name'], strtoupper(substr($id, 0, 12))), 'Default name carries the original request suffix');
$repeat = $service->send($context, $input, 'blu-t-synthetic-results-001');
resultCheck($repeat['replayed'] && $repeat['message']['reference'] === $receipt['message']['reference'] && CampaignService::findOwned(1, $campaignId)['name'] === $campaign['name'], 'Auto naming does not change idempotency or original reference/name');
$named = $service->send($context, [...$input, 'name' => 'Order ready'], 'blu-t-synthetic-named-001');
$namedId = (int)$p->query("SELECT campaign_id FROM sms_integration_requests WHERE id='{$named['message']['id']}'")->fetchColumn();
resultCheck(CampaignService::findOwned(1, $namedId)['name'] === 'Order ready', 'Caller-supplied campaign name is preserved');
$unicodeName = SmsIntegrationPresentation::campaignName(str_repeat('漢é', 40), $id, 1793872800);
resultCheck(strlen($unicodeName) <= 100 && preg_match('//u', $unicodeName) === 1, 'Long connection names remain valid UTF-8 within campaign byte limit');
$allowed = ['id','reference','external_reference','status','state','complete','scheduled_at','created_at','recipients','credits_required','acceptance_is_not_delivery']; sort($allowed);
$spec = json_decode(file_get_contents(__DIR__.'/../../public/sms-api-v1.openapi.json'), true, 32, JSON_THROW_ON_ERROR);
$schema = $spec['components']['schemas']['MessageResult']['properties']['message'];
$documented = array_keys($schema['properties']); sort($documented);
resultCheck($schema['additionalProperties'] === false && $documented === $allowed, 'Downloadable contract matches the strict response allowlist');
resultCheck($schema['properties']['status']['enum'] === ['queued','sent','not_sent','partially_sent'], 'Downloadable contract lists exactly the supported sending outcomes');
function resultIs(string $expected, bool $complete): void {
    global $service, $context, $id, $allowed;
    $result = $service->status($context, $id); $keys = array_keys($result); sort($keys);
    resultCheck($result['status'] === $expected && $result['state'] === $expected && $result['complete'] === $complete, 'Public status '.$expected.' / '.($complete ? 'final' : 'pending'));
    resultCheck($keys === $allowed, 'Strict response allowlist for '.$expected);
}
resultIs('queued', false);
foreach (['processing', 'retry', 'unrecognised_future_state'] as $state) {
    $p->prepare('UPDATE sms_messages SET state=?,last_error=?,next_attempt_at=? WHERE campaign_id=?')->execute([$state, 'PRIVATE provider response and connection diagnostics', '9999-12-31 23:59:59', $campaignId]);
    $p->exec("UPDATE sms_campaigns SET state='completed' WHERE id=$campaignId");
    resultIs('queued', false); // Never trust the cached campaign terminal state.
}
$p->exec("UPDATE sms_messages SET state='sent',sent_at=NOW() WHERE campaign_id=$campaignId AND destination='+27820000001'");
resultIs('queued', false); // One accepted, one unresolved is not a final partial result.
$p->exec("UPDATE sms_messages SET state='failed' WHERE campaign_id=$campaignId AND destination='+27820000002'");
resultIs('partially_sent', true);
$p->exec("UPDATE sms_messages SET state='failed',sent_at=NULL,actual_charge=0 WHERE campaign_id=$campaignId");
resultIs('not_sent', true);
$p->exec("UPDATE sms_messages SET state='delivered',sent_at=NOW(),actual_charge=.35 WHERE campaign_id=$campaignId");
resultIs('sent', true);
$p->exec("UPDATE sms_messages SET state='failed' WHERE campaign_id=$campaignId");
resultIs('sent', true); // A later negative delivery report cannot undo acceptance.
$p->exec("UPDATE sms_messages SET state='failed',sent_at=NULL,last_error='LogicSMS delivery status: REJECTED' WHERE campaign_id=$campaignId");
resultIs('sent', true); // Reconciled historical acceptance without invented timestamps.
$p->exec("UPDATE sms_messages SET state='skipped',sent_at=NULL,actual_charge=0 WHERE campaign_id=$campaignId");
resultIs('not_sent', true);
$p->exec("UPDATE sms_campaigns SET state='cancelled' WHERE id=$campaignId; UPDATE sms_messages SET state='pending' WHERE campaign_id=$campaignId");
resultIs('not_sent', true);
$p->exec("UPDATE sms_messages SET state='retry',next_attempt_at='9999-12-31 23:59:59' WHERE campaign_id=$campaignId");
resultIs('queued', false); // Cancellation must never overwrite uncertain acceptance.
$before = json_encode($p->query('SELECT * FROM sms_messages')->fetchAll());
$sources = SmsIntegrationCampaignSource::attach($p, 1, [$campaign, CampaignService::findOwned(1, $namedId)]);
resultCheck($sources[0]['source'] === ['type' => 'api', 'connection_name' => 'Blu-T production', 'reference' => 'API-'.strtoupper($id), 'external_reference' => 'blu-t:order:fixture'], 'Owner portal sees connection and application references, not keys');
resultCheck(!isset(SmsIntegrationCampaignSource::attach($p, 2, [$campaign])[0]['source']), 'Foreign account cannot attach another account source');
$p->exec("UPDATE sms_integrations SET status='revoked' WHERE id='{$connection['connection_id']}'");
resultCheck(isset(SmsIntegrationCampaignSource::attach($p, 1, [$campaign])[0]['source']), 'Revoked connections retain their historical attribution');
resultCheck(json_encode($p->query('SELECT * FROM sms_messages')->fetchAll()) === $before, 'Source/status reads never mutate sending or financial records');
$empty = SmsIntegrationPresentation::result(['id' => $id, 'external_reference' => '', 'scheduled_at' => null, 'created_at' => 1, 'admitted_credits' => 1, 'secret_hash' => 'PRIVATE'], ['recipients' => 0, 'accepted' => 0, 'not_sent' => 0]);
resultCheck($empty['status'] === 'queued' && !$empty['complete'] && !isset($empty['secret_hash']), 'Missing message evidence remains unresolved and extra columns never leak');
$legacy = new PDO('sqlite::memory:');
resultCheck(SmsIntegrationCampaignSource::attach($legacy, 1, [$campaign]) === [$campaign], 'Pre-API schemas retain existing portal behaviour');
echo json_encode(['passed' => true, 'checks' => $checks, 'fixture_only' => true, 'real_sms_sent' => false, 'production_access' => false], JSON_PRETTY_PRINT).PHP_EOL;
