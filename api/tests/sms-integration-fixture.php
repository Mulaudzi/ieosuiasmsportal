<?php
declare(strict_types=1);
// Synthetic-only SQLite adapter for the actual campaign, wallet and integration code.
class IntegrationFixturePdo extends PDO {
    public function prepare(string $sql, array $options = []): PDOStatement|false {
        $sql = str_replace(' FOR UPDATE', '', $sql);
        $sql = str_replace('ON DUPLICATE KEY UPDATE updated_at=NOW()', 'ON CONFLICT(user_id,usage_date) DO UPDATE SET updated_at=NOW()', $sql);
        $sql = str_replace('CURRENT_DATE', "'2026-11-05'", $sql);
        return parent::prepare($sql, $options);
    }
}
function env(string $key, mixed $default = null): mixed { return $GLOBALS['integration_env'][$key] ?? $default; }
function db(): PDO { return $GLOBALS['integration_pdo']; }
require_once __DIR__.'/../core/QueryBuilder.php';
require_once __DIR__.'/../domain/SmsIntegrationService.php';
function integrationFixture(): array {
    date_default_timezone_set('Africa/Johannesburg');
    $GLOBALS['integration_env'] = ['SMS_INTEGRATION_API_ENABLED' => 'true', 'SMS_PRICE_PER_SEGMENT' => '0.35', 'SMS_MAX_DAILY_SEGMENTS' => '50000'];
    $p = new IntegrationFixturePdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $GLOBALS['integration_pdo'] = $p; $p->exec('PRAGMA foreign_keys=ON');
    $p->sqliteCreateFunction('NOW', fn() => '2026-11-05 12:00:00');
    $p->sqliteCreateFunction('IF', fn($condition, $yes, $no) => $condition ? $yes : $no);
    $p->sqliteCreateFunction('GREATEST', fn(...$numbers) => max($numbers));
    integrationBaseSchema($p);
    $security = require __DIR__.'/../migrations/023_portal_login_security.php'; $security($p);
    $p->exec("INSERT INTO sms_login_security(user_id,enabled,version,updated_at) VALUES(1,1,1,NOW()),(2,1,1,NOW()),(3,1,1,NOW()),(4,1,1,NOW())");
    $migration = require __DIR__.'/../migrations/027_sms_integration_api.php'; $migration($p); $migration($p);
    $clock = (new DateTimeImmutable('2026-11-05T12:00:00+02:00'))->getTimestamp();
    $s = new SmsIntegrationService($p, static function() use (&$clock): int { return $clock; });
    return [$p, $s, ['id' => 1, '_sms_two_step_version' => 1], &$clock];
}
function integrationBaseSchema(PDO $p): void {
    $mysql = $p->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) $p->exec('SET default_storage_engine=InnoDB');
    $identity = $mysql ? 'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $money = $mysql ? 'DECIMAL(14,4)' : 'REAL';
    $p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER,email_verified_at TEXT,auth_version INTEGER);
        INSERT INTO users VALUES(1,1,'2026-11-01',1),(2,1,'2026-11-01',1),(3,0,'2026-11-01',1),(4,1,NULL,1);
        CREATE TABLE sms_campaigns(id {$identity},user_id INTEGER,idempotency_key VARCHAR(80),request_fingerprint TEXT,name TEXT,content TEXT,sender_id TEXT,state TEXT,encoding TEXT,segments_per_message INTEGER,recipient_count INTEGER,queued_count INTEGER,processing_count INTEGER DEFAULT 0,sent_count INTEGER DEFAULT 0,delivered_count INTEGER DEFAULT 0,failed_count INTEGER DEFAULT 0,skipped_count INTEGER DEFAULT 0,total_segments INTEGER,price_per_segment {$money},estimated_cost {$money},actual_cost {$money} DEFAULT 0,scheduled_at TEXT,queued_at TEXT,quota_reserved_at TEXT,created_at TEXT,updated_at TEXT,completed_at TEXT,started_at TEXT,cancelled_at TEXT,UNIQUE(user_id,idempotency_key));
        CREATE TABLE sms_messages(id {$identity},campaign_id INTEGER,user_id INTEGER,destination TEXT,content TEXT,encoding TEXT,segment_count INTEGER,estimated_charge {$money},state TEXT,sent_at TEXT,delivered_at TEXT,failed_at TEXT,actual_charge {$money} DEFAULT 0,last_error TEXT,next_attempt_at TEXT,created_at TEXT,updated_at TEXT);
        CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER UNIQUE,balance {$money},reserved {$money},updated_at TEXT);
        INSERT INTO wallets VALUES(1,1,350,0,NULL),(2,2,700,7,NULL);
        CREATE TABLE wallet_ledger(id {$identity},user_id INTEGER,wallet_id INTEGER,campaign_id INTEGER,payment_id INTEGER,operation TEXT,direction TEXT,amount {$money},idempotency_key VARCHAR(120) UNIQUE,created_at TEXT);
        CREATE TABLE sms_daily_quota_usage(user_id INTEGER,usage_date DATE,segments_reserved INTEGER,updated_at TEXT,PRIMARY KEY(user_id,usage_date));
        CREATE TABLE contacts(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,surname TEXT,email TEXT,phone TEXT,phone_normalized TEXT);
        CREATE TABLE opt_outs(user_id INTEGER,channel TEXT,recipient TEXT);
        CREATE TABLE sms_preferences(user_id INTEGER,opt_out_text TEXT);
        INSERT INTO sms_preferences VALUES(1,'Reply STOP to unsubscribe');
        INSERT INTO opt_outs VALUES(1,'sms','082 000 0004');
    ");
}
