<?php
declare(strict_types=1);
/** Schema-only, explicitly invoked setup. HTTP controllers never instantiate this class. */
final class SmsIntegrationSetup
{
    public const VERSION = '027_sms_integration_api';
    public const TABLES = [
        'sms_integrations' => ['id','user_id','name','product','scopes_json','status','daily_credit_limit','requests_per_minute','rate_window','rate_count','created_at','revoked_at'],
        'sms_integration_keys' => ['id','integration_id','user_id','secret_hash','scopes_json','owner_auth_version','owner_security_version','created_at','expires_at','revoked_at','last_used_at'],
        'sms_integration_usage' => ['integration_id','user_id','usage_date','admitted_credits','campaign_count'],
        'sms_integration_requests' => ['id','integration_id','user_id','key_id','idempotency_hash','fingerprint','campaign_id','external_reference','admitted_credits','created_at'],
        'sms_integration_audit' => ['id','user_id','integration_id','key_id','event','object_id','created_at'],
    ];
    public function __construct(private PDO $pdo, private string $approvedMigrationHash) {}
    private function rows(string $sql, array $args = []): array { $q = $this->pdo->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC); }
    private function columns(string $table): array { return array_column($this->rows('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?', [$table]), 'COLUMN_NAME'); }
    private function unique(string $table, array $columns): bool {
        $indexes = [];
        foreach ($this->rows('SELECT INDEX_NAME,COLUMN_NAME,SUB_PART FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]) as $r) {
            $indexes[$r['INDEX_NAME']][] = $r['SUB_PART'] === null ? $r['COLUMN_NAME'] : 'prefix_index_rejected';
        }
        return in_array($columns, $indexes, true);
    }
    public function inspect(): array {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('Live setup inspection requires MySQL/InnoDB.');
        $problems = [];
        if (function_exists('env') && env('SMS_INTEGRATION_API_ENABLED', 'false') === 'true') $problems[] = 'Disable SMS_INTEGRATION_API_ENABLED before schema-only setup.';
        $prerequisites = [
            'schema_migrations' => ['version','applied_at'], 'users' => ['id','is_active','email_verified_at','auth_version'],
            'wallets' => ['id','user_id','balance','reserved'], 'wallet_ledger' => ['user_id','wallet_id','campaign_id','idempotency_key'],
            'sms_campaigns' => ['id','user_id','idempotency_key','request_fingerprint','quota_reserved_at','scheduled_at','state'],
            'sms_messages' => ['id','user_id','campaign_id','state','segment_count','sent_at','delivered_at','last_error','next_attempt_at'],
            'sms_daily_quota_usage' => ['user_id','usage_date','segments_reserved'], 'sms_login_security' => ['user_id','enabled','version'],
        ];
        foreach ($prerequisites as $table => $required) {
            if (array_diff($required, $this->columns($table))) $problems[] = 'Missing prerequisite schema: '.$table;
            $engine = $this->rows('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table]);
            if (!$engine || strtolower((string)$engine[0]['ENGINE']) !== 'innodb') $problems[] = 'Transactional InnoDB prerequisite required: '.$table;
        }
        if (!$this->unique('wallets', ['user_id']) || !$this->unique('wallet_ledger', ['idempotency_key']) || !$this->unique('sms_campaigns', ['user_id','idempotency_key']) || !$this->unique('sms_daily_quota_usage', ['user_id','usage_date']) || !$this->unique('schema_migrations', ['version'])) $problems[] = 'Required account/financial idempotency index is missing.';
        $history = $this->columns('schema_migrations') ? $this->rows('SELECT version,applied_at FROM schema_migrations ORDER BY version') : [];
        $recorded = in_array(self::VERSION, array_column($history, 'version'), true);
        $tables = []; $existing = 0;
        foreach (self::TABLES as $table => $columns) {
            $actual = $this->columns($table); $present = count($actual) > 0; $existing += $present ? 1 : 0;
            $tables[$table] = ['exists' => $present, 'missing_columns' => array_values(array_diff($columns, $actual))];
            if ($present && array_diff($columns, $actual)) $problems[] = 'Incomplete integration table: '.$table;
            if ($present) {
                $engine = $this->rows('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table]);
                if (strtolower((string)($engine[0]['ENGINE'] ?? '')) !== 'innodb') $problems[] = 'Integration table is not InnoDB: '.$table;
            }
        }
        foreach ([['sms_integrations',['id','user_id']], ['sms_integration_keys',['id']], ['sms_integration_usage',['integration_id','usage_date']], ['sms_integration_requests',['integration_id','idempotency_hash']], ['sms_integration_requests',['campaign_id']], ['sms_integration_audit',['id']]] as [$table,$index]) if ($tables[$table]['exists'] && !$this->unique($table, $index)) $problems[] = 'Integration uniqueness contract is missing: '.$table;
        $partial = $existing > 0 && (!$recorded || $existing !== count(self::TABLES));
        if ($recorded && $existing !== count(self::TABLES)) $problems[] = 'Recorded migration is missing tables.';
        return ['ok' => true, 'code' => 'SMS_API_SCHEMA_INSPECTED', 'read_only' => true, 'migration' => self::VERSION, 'allowed_migrations' => [self::VERSION], 'migration_recorded' => $recorded, 'tables' => $tables, 'ready_to_apply' => !$problems && !$partial, 'partial_setup_requires_investigation' => $partial, 'problems' => array_values(array_unique($problems)), 'other_migrations_run' => false, 'activation_flag_changed' => false];
    }
    public function apply(string $confirmation, bool $backupVerified): array {
        if ($confirmation !== self::VERSION || !$backupVerified) throw new RuntimeException('Confirm migration 027 and a verified database backup.');
        $lock = $this->rows("SELECT GET_LOCK('ieosuia-sms-integration-setup',0) AS acquired");
        if ((int)$lock[0]['acquired'] !== 1) throw new RuntimeException('Setup is already running. Inspect instead of retrying Apply.');
        try {
            $before = $this->inspect();
            if (!$before['ready_to_apply']) throw new RuntimeException('Schema prerequisites or a partial setup require investigation. No migration was run.');
            $history = $this->rows('SELECT version,applied_at FROM schema_migrations ORDER BY version');
            $file = __DIR__.'/../migrations/'.self::VERSION.'.php';
            if (!preg_match('/^[a-f0-9]{64}$/D', $this->approvedMigrationHash) || !hash_equals($this->approvedMigrationHash, hash_file('sha256', $file))) throw new RuntimeException('Approved migration bytes are missing or changed.');
            if (!$before['migration_recorded']) {
                $migration = require $file; $migration($this->pdo);
                $after = $this->inspect();
                if ($after['problems']) throw new RuntimeException('Post-migration verification failed. Inspect the partial setup; do not retry Apply.');
                $this->rows('INSERT INTO schema_migrations(version,applied_at) VALUES(?,NOW())', [self::VERSION]);
            }
            $current = $this->rows('SELECT version,applied_at FROM schema_migrations WHERE version<>? ORDER BY version', [self::VERSION]);
            $old = array_values(array_filter($history, fn($row) => $row['version'] !== self::VERSION));
            if ($current !== $old) throw new RuntimeException('Unrelated migration history changed; investigate.');
            $verified = $this->inspect();
            if (!$verified['migration_recorded'] || !$verified['ready_to_apply']) throw new RuntimeException('Final schema verification failed.');
            return ['ok' => true, 'code' => 'SMS_API_SCHEMA_READY', 'migration' => self::VERSION, 'verified_tables' => array_keys(self::TABLES), 'migration_recorded' => true, 'other_migrations_run' => false, 'email_migration_run' => false, 'activation_flag_changed' => false, 'application_deployed' => false, 'credentials_created' => false, 'customer_records_changed' => false, 'sms_submitted' => false, 'payments_processed' => false, 'schema_only' => true];
        } finally { $this->rows("SELECT RELEASE_LOCK('ieosuia-sms-integration-setup')"); }
    }
}
