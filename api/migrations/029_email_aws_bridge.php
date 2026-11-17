<?php
declare(strict_types=1);
return static function(PDO $pdo):void {
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql){
        foreach(['users','wallets','wallet_ledger']as$table){
            $q=$pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);
            if(strtolower((string)$q->fetchColumn())!=='innodb')throw new RuntimeException('Existing identity and financial tables must use InnoDB.');
        }
        if((int)$pdo->query('SELECT COUNT(*) FROM (SELECT user_id FROM wallets GROUP BY user_id HAVING COUNT(*)<>1) duplicate_wallets')->fetchColumn())throw new RuntimeException('Ambiguous wallets require reconciliation.');
    }
    $tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin':'';
    $tables=[
        'email_aws_reservations'=>"id CHAR(32) PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,fingerprint CHAR(64) NOT NULL,reply_to_sha256 CHAR(64) NOT NULL,unit_cents INT NOT NULL,recipient_count INT NOT NULL,reserved_cents INT NOT NULL,charged_cents INT NOT NULL DEFAULT 0,cancelled INT NOT NULL DEFAULT 0,created_at DATETIME NOT NULL",
        'email_aws_attempts'=>"id CHAR(32) PRIMARY KEY,campaign_id CHAR(32) NOT NULL,email_sha256 CHAR(64) NOT NULL,state VARCHAR(20) NOT NULL,provider_id VARCHAR(200) NULL UNIQUE,issued_at DATETIME NULL,settled_at DATETIME NULL",
        'email_aws_nonces'=>"key_id VARCHAR(32) NOT NULL,nonce_hash CHAR(64) NOT NULL,expires_at BIGINT NOT NULL,PRIMARY KEY(key_id,nonce_hash)",
    ];
    foreach($tables as$name=>$columns)$pdo->exec("CREATE TABLE IF NOT EXISTS {$name} ({$columns}){$tail}");
    foreach([['email_aws_reservations','idx_email_aws_owner','user_id'],['email_aws_attempts','idx_email_aws_campaign','campaign_id,state'],['email_aws_nonces','idx_email_aws_nonce_expiry','expires_at']]as[$table,$index,$columns]){
        if($mysql){$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');$q->execute([$table,$index]);if(!$q->fetchColumn())$pdo->exec("CREATE INDEX {$index} ON {$table} ({$columns})");}
        else $pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table} ({$columns})");
    }
};
