<?php
declare(strict_types=1);
require __DIR__.'/sms-integration-fixture.php';
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--disposable-fixture', '--child'], true)) throw new RuntimeException('Disposable loopback fixture opt-in required');
date_default_timezone_set('Africa/Johannesburg');
$GLOBALS['integration_env'] = ['SMS_INTEGRATION_API_ENABLED' => 'true', 'SMS_PRICE_PER_SEGMENT' => '0.35', 'SMS_MAX_DAILY_SEGMENTS' => '50000'];
function fixtureDb(?string $name = null): PDO {
    if ($name !== null && !preg_match('/^sms_api_fixture_[a-f0-9]{12}$/D', $name)) throw new RuntimeException('Refuse non-fixture database');
    $p = new PDO('mysql:host=127.0.0.1;port=33169'.($name ? ';dbname='.$name : ''), 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec("SET SESSION time_zone='+02:00'; SET SESSION innodb_lock_wait_timeout=5"); return $p;
}
if ($argv[1] === '--child') {
    $GLOBALS['integration_pdo'] = fixtureDb($argv[2] ?? '');
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    try {
        $s = new SmsIntegrationService(db()); $context = $s->authenticate($input['token'], 'sms:send');
        $response = $s->send($context, ['content' => 'Synthetic concurrency fixture', 'recipients' => ['0820000001']], $input['request']);
        echo json_encode(['status' => 202, 'id' => $response['message']['id'], 'replayed' => $response['replayed']]);
    } catch (SmsIntegrationException $e) { echo json_encode(['status' => $e->status, 'code' => $e->errorCode]); }
    catch (DomainException) { echo json_encode(['status' => 409]); }
    exit;
}
$admin = fixtureDb(); $name = 'sms_api_fixture_'.bin2hex(random_bytes(6));
$admin->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$p = fixtureDb($name); $GLOBALS['integration_pdo'] = $p;
integrationBaseSchema($p);
$security = require __DIR__.'/../migrations/023_portal_login_security.php'; $security($p);
$p->exec('INSERT INTO sms_login_security(user_id,enabled,version,updated_at) VALUES(1,1,1,NOW()),(2,1,1,NOW())');
$migration = require __DIR__.'/../migrations/027_sms_integration_api.php'; $migration($p); $migration($p);
$s = new SmsIntegrationService($p); $actor = ['id' => 1, '_sms_two_step_version' => 1]; $checks = 0;
function mysqlCheck(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: '.$message); $checks++; }
function newConnection(SmsIntegrationService $s, array $actor, int $limit): array { return $s->createConnection($actor, ['name' => 'Synthetic InnoDB connection', 'product' => 'custom', 'scopes' => SmsIntegrationPolicy::SCOPES, 'daily_credit_limit' => $limit, 'requests_per_minute' => 600]); }
function compete(string $name, array $inputs): array {
    $workers = [];
    foreach ($inputs as $input) {
        $process = proc_open(['C:/php8/php.exe', __FILE__, '--child', $name], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Fixture subprocess unavailable');
        fwrite($pipes[0], json_encode($input)); fclose($pipes[0]); $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $error !== '') throw new RuntimeException('Synthetic child failure: '.$error);
        $results[] = json_decode($out, true, 16, JSON_THROW_ON_ERROR);
    }
    return $results;
}
$other = $p->query('SELECT * FROM wallets WHERE user_id=2')->fetch();
$app = newConnection($s, $actor, 100); $token = $app['credential']['token'];
$results = compete($name, array_fill(0, 4, ['token' => $token, 'request' => 'mysql-identical-request-001']));
mysqlCheck(array_column($results, 'status') === [202,202,202,202] && count(array_unique(array_column($results, 'id'))) === 1, 'Concurrent identical creates return one message identifier');
mysqlCheck((int)$p->query('SELECT COUNT(*) FROM sms_campaigns')->fetchColumn() === 1 && (int)$p->query('SELECT COUNT(*) FROM sms_integration_requests')->fetchColumn() === 1, 'Canonical and attribution inserts are once-only');
mysqlCheck((int)$p->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn() === 1 && (float)$p->query('SELECT reserved FROM wallets WHERE user_id=1')->fetchColumn() === .35, 'Concurrent replay reserves money once');
mysqlCheck((int)$p->query('SELECT SUM(admitted_credits) FROM sms_integration_usage')->fetchColumn() === 1 && (int)$p->query('SELECT SUM(segments_reserved) FROM sms_daily_quota_usage')->fetchColumn() === 1, 'Replay increments both app and account quotas once');

$limited = newConnection($s, $actor, 1); $before = (int)$p->query('SELECT COUNT(*) FROM sms_campaigns')->fetchColumn();
$results = compete($name, [['token' => $limited['credential']['token'], 'request' => 'mysql-budget-request-001'], ['token' => $limited['credential']['token'], 'request' => 'mysql-budget-request-002']]);
$statuses = array_column($results, 'status'); sort($statuses);
mysqlCheck($statuses === [202,429], 'Competing requests cannot overspend one daily app credit');
mysqlCheck((int)$p->query('SELECT COUNT(*) FROM sms_campaigns')->fetchColumn() === $before + 1 && (float)$p->query('SELECT reserved FROM wallets WHERE user_id=1')->fetchColumn() === .7, 'Rejected budget race leaves no orphan campaign or reservation');
mysqlCheck((int)$p->query('SELECT SUM(segments_reserved) FROM sms_daily_quota_usage')->fetchColumn() === 2, 'Rejected budget race also restores account quota');

$a = newConnection($s, $actor, 100); $b = newConnection($s, $actor, 100); $p->exec('UPDATE wallets SET balance=1.05 WHERE user_id=1');
$before = (int)$p->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn();
$results = compete($name, [['token' => $a['credential']['token'], 'request' => 'mysql-wallet-request-001'], ['token' => $b['credential']['token'], 'request' => 'mysql-wallet-request-002']]);
$statuses = array_column($results, 'status'); sort($statuses);
mysqlCheck($statuses === [202,409], 'Different app credentials cannot overspend a shared account wallet');
mysqlCheck((int)$p->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn() === $before + 1 && (float)$p->query('SELECT reserved FROM wallets WHERE user_id=1')->fetchColumn() === 1.05, 'Wallet contention retains exact one-reservation conservation');
mysqlCheck($p->query('SELECT * FROM wallets WHERE user_id=2')->fetch() === $other, 'Other account financial state is untouched');
$tables = $p->query("SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'sms_integration%'")->fetchAll(PDO::FETCH_COLUMN);
mysqlCheck(count($tables) === 5 && count(array_filter($tables, fn($engine) => strtolower($engine) === 'innodb')) === 5, 'All five additive integration tables use InnoDB');
require __DIR__.'/../domain/SmsIntegrationSetup.php';
$GLOBALS['integration_env']['SMS_INTEGRATION_API_ENABLED'] = 'false';
$setupName = 'sms_api_fixture_'.bin2hex(random_bytes(6));
$admin->exec('CREATE DATABASE '.$setupName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$setupPdo = fixtureDb($setupName); integrationBaseSchema($setupPdo); $security($setupPdo);
$setupPdo->exec("CREATE TABLE schema_migrations(version VARCHAR(100) PRIMARY KEY,applied_at DATETIME NOT NULL) ENGINE=InnoDB; INSERT INTO schema_migrations VALUES('018_phase1_sms_integrity',NOW()),('023_portal_login_security',NOW())");
$setup = new SmsIntegrationSetup($setupPdo, hash_file('sha256', __DIR__.'/../migrations/027_sms_integration_api.php'));
mysqlCheck($setup->inspect()['ready_to_apply'] && !$setup->inspect()['migration_recorded'], 'Dedicated setup inspects prerequisites without applying migrations');
try { $setup->apply(SmsIntegrationSetup::VERSION, false); mysqlCheck(false, 'Unconfirmed backup accepted'); } catch (RuntimeException) { mysqlCheck(!$setup->inspect()['migration_recorded'], 'Setup requires explicit backup confirmation'); }
try { (new SmsIntegrationSetup($setupPdo, str_repeat('0', 64)))->apply(SmsIntegrationSetup::VERSION, true); mysqlCheck(false, 'Wrong migration bytes accepted'); } catch (RuntimeException) { mysqlCheck(!$setup->inspect()['tables']['sms_integrations']['exists'], 'Wrong pinned hash executes no DDL'); }
$financial = $setupPdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll();
$applied = $setup->apply(SmsIntegrationSetup::VERSION, true);
mysqlCheck($applied['code'] === 'SMS_API_SCHEMA_READY' && $setup->inspect()['migration_recorded'], 'Allowlisted migration creates and verifies only the five API tables');
mysqlCheck($setupPdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll() === $financial, 'Schema setup changes no wallet balances or reservations');
mysqlCheck((int)$setupPdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 3 && (int)$setupPdo->query('SELECT COUNT(*) FROM sms_integration_keys')->fetchColumn() === 0, 'Setup records only migration 027 and creates no credentials');
$setup->apply(SmsIntegrationSetup::VERSION, true);
mysqlCheck((int)$setupPdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 3, 'Recorded setup inspection is safe without duplicate bookkeeping');
mysqlCheck((int)$setupPdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='email_campaign_jobs'")->fetchColumn() === 0 && !$applied['activation_flag_changed'], 'Email schema and API activation are excluded');
$GLOBALS['integration_env']['SMS_INTEGRATION_API_ENABLED'] = 'true';
mysqlCheck(!$setup->inspect()['ready_to_apply'], 'Installer refuses schema-only work while API activation is enabled');
$GLOBALS['integration_env']['SMS_INTEGRATION_API_ENABLED'] = 'false';
$setupPdo->exec("DELETE FROM schema_migrations WHERE version='027_sms_integration_api'");
mysqlCheck($setup->inspect()['partial_setup_requires_investigation'] && !$setup->inspect()['ready_to_apply'], 'Unrecorded existing setup must be investigated, not replayed automatically');
echo json_encode(['passed' => true, 'checks' => $checks, 'fixture_database' => $name, 'production_access' => false, 'real_sms_sent' => false, 'retained_synthetic_data' => true], JSON_PRETTY_PRINT).PHP_EOL;
