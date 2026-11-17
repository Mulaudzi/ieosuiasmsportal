<?php
// Additive only: no historical SMS, contacts, wallet or payment rows are changed.
return static function (PDO $pdo): void {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if($mysql) {
        foreach(['users','wallets','wallet_ledger']as$table) {
            $q=$pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);
            if(strtolower((string)$q->fetchColumn())!=='innodb')throw new RuntimeException('Email preflight requires existing InnoDB identity and wallet tables. No financial schema conversion is automatic.');
        }
        $q=$pdo->query("SELECT COLUMN_NAME,NUMERIC_SCALE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wallets' AND column_name IN ('balance','reserved')");$scales=$q->fetchAll(PDO::FETCH_KEY_PAIR);
        if(count($scales)!==2 || min(array_map('intval',$scales))<2)throw new RuntimeException('Email preflight requires wallet amounts with at least two decimal places.');
        if((int)$pdo->query('SELECT COUNT(*) FROM (SELECT user_id FROM wallets GROUP BY user_id HAVING COUNT(*)>1) duplicate_email_wallets')->fetchColumn())throw new RuntimeException('Email preflight requires an unambiguous wallet per account. Existing wallets are not changed.');
    }
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $opaque = $mysql ? ' COLLATE utf8mb4_bin' : '';
    $tables = [
        'email_sender_profiles' => "user_id BIGINT UNSIGNED PRIMARY KEY, business_name VARCHAR(100) NOT NULL, postal_address VARCHAR(400) NOT NULL, reply_to VARCHAR(254) NOT NULL, state VARCHAR(20) NOT NULL, daily_limit INT NOT NULL DEFAULT 0, version INT NOT NULL DEFAULT 1, approval_evidence TEXT NULL, reviewed_by BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL",
        'email_campaign_jobs' => "id CHAR(32) PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, idempotency_key VARCHAR(80){$opaque} NOT NULL, fingerprint CHAR(64) NOT NULL, name VARCHAR(100) NOT NULL, subject VARCHAR(180) NOT NULL, body TEXT NOT NULL, sender_json TEXT NOT NULL, consent_evidence TEXT NOT NULL, state VARCHAR(24) NOT NULL, unit_cents INT NOT NULL, reserved_cents BIGINT NOT NULL DEFAULT 0, charged_cents BIGINT NOT NULL DEFAULT 0, recipient_count INT NOT NULL, reviewed_by BIGINT UNSIGNED NULL, review_evidence TEXT NULL, cancel_requested INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE(user_id,idempotency_key)",
        'email_campaign_recipients' => "id CHAR(32) PRIMARY KEY, campaign_id CHAR(32) NOT NULL, user_id BIGINT UNSIGNED NOT NULL, email VARCHAR(254) NOT NULL, state VARCHAR(24) NOT NULL, message_id VARCHAR(150) NOT NULL, provider VARCHAR(64) NULL, provider_message_id VARCHAR(200) NULL, unsubscribe_hash CHAR(64) NULL, outcome_code VARCHAR(40) NULL, delivery_state VARCHAR(20) NOT NULL DEFAULT 'unknown', attempts INT NOT NULL DEFAULT 0, started_at DATETIME NULL, accepted_at DATETIME NULL, updated_at DATETIME NOT NULL, UNIQUE(campaign_id,email), UNIQUE(provider,provider_message_id)",
        'email_campaign_suppressions' => "user_id BIGINT UNSIGNED NOT NULL, email VARCHAR(254) NOT NULL, reason VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(user_id,email)",
        'email_campaign_events' => "event_id VARCHAR(100){$opaque} PRIMARY KEY, recipient_id CHAR(32) NOT NULL, payload_sha256 CHAR(64) NOT NULL, event VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL",
        'email_campaign_audit' => "id CHAR(32) PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NULL, campaign_id CHAR(32) NULL, event VARCHAR(50) NOT NULL, evidence TEXT NOT NULL, created_at DATETIME NOT NULL",
        'email_campaign_dispatch_budget' => "id INT PRIMARY KEY, next_allowed_at DECIMAL(20,6) NOT NULL DEFAULT 0",
    ];
    foreach ($tables as $name => $columns) $pdo->exec("CREATE TABLE IF NOT EXISTS {$name} ({$columns}){$tail}");
    if(!(int)$pdo->query('SELECT COUNT(*) FROM email_campaign_dispatch_budget WHERE id=1')->fetchColumn())$pdo->exec('INSERT INTO email_campaign_dispatch_budget(id,next_allowed_at) VALUES(1,0)');
    foreach ([['email_campaign_jobs','idx_email_owner','user_id,created_at'], ['email_campaign_jobs','idx_email_queue','state,created_at'], ['email_campaign_recipients','idx_email_recipient_state','campaign_id,state'], ['email_campaign_recipients','idx_email_stale','state,started_at'], ['email_campaign_recipients','idx_email_rolling_global','started_at'], ['email_campaign_recipients','idx_email_rolling_user','user_id,started_at'], ['email_campaign_audit','idx_email_audit_owner','user_id,created_at']] as [$table,$index,$columns]) {
        if ($mysql) {
            $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?'); $q->execute([$table,$index]);
            if (!(int)$q->fetchColumn()) $pdo->exec("CREATE INDEX {$index} ON {$table} ({$columns})");
        } else $pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table} ({$columns})");
    }
};
