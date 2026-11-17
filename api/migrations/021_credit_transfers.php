<?php
// Additive only. Never rewrites existing wallets, purchases, cash balances or SMS.
return static function(PDO $pdo): void {
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql){
        foreach(['users','wallets','wallet_ledger','credit_approval_security','credit_approval_attempts','credit_approval_events']as$table){
            $q=$pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);
            if(strtolower((string)$q->fetchColumn())!=='innodb')throw new RuntimeException('Credit transfers require InnoDB prerequisite: '.$table);
        }
        $q=$pdo->query('SELECT COUNT(*) FROM (SELECT user_id FROM wallets GROUP BY user_id HAVING COUNT(*)<>1) duplicates');
        if((int)$q->fetchColumn())throw new RuntimeException('Reconcile duplicate account wallets before enabling transfers.');
        $columns=$pdo->query("SELECT column_name AS field,data_type AS type,numeric_scale AS scale FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wallets' AND column_name IN ('balance','reserved')")->fetchAll(PDO::FETCH_ASSOC);
        if(count($columns)!==2)throw new RuntimeException('Wallet balance columns are incomplete.');
        foreach($columns as$c)if($c['type']!=='decimal'||(int)$c['scale']<2)throw new RuntimeException('Exact decimal wallets are required for credit transfers.');
    }
    $tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_transfer_quotes (
        id CHAR(32) PRIMARY KEY,actor_user_id BIGINT UNSIGNED NOT NULL,
        request_id CHAR(36) NOT NULL,source_user_id BIGINT UNSIGNED NOT NULL,destination_user_id BIGINT UNSIGNED NOT NULL,
        source_wallet_id BIGINT UNSIGNED NOT NULL,destination_wallet_id BIGINT UNSIGNED NOT NULL,
        credits INT UNSIGNED NOT NULL,unit_amount DECIMAL(14,4) NOT NULL,amount DECIMAL(14,4) NOT NULL,
        authorization_mode VARCHAR(10) NOT NULL DEFAULT 'admin',
        code_hash VARCHAR(255) NULL,code_expires_at BIGINT UNSIGNED NULL,code_requested_at BIGINT UNSIGNED NULL,
        reason VARCHAR(500) NOT NULL,source_name VARCHAR(255) NOT NULL,source_email VARCHAR(255) NOT NULL,
        destination_name VARCHAR(255) NOT NULL,destination_email VARCHAR(255) NOT NULL,
        source_available DECIMAL(14,4) NOT NULL,destination_available DECIMAL(14,4) NOT NULL,
        expires_at BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,
        UNIQUE(actor_user_id,request_id),FOREIGN KEY(actor_user_id) REFERENCES users(id),
        FOREIGN KEY(source_user_id) REFERENCES users(id),FOREIGN KEY(destination_user_id) REFERENCES users(id),
        FOREIGN KEY(source_wallet_id) REFERENCES wallets(id),FOREIGN KEY(destination_wallet_id) REFERENCES wallets(id)
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_transfer_security (
        user_id BIGINT UNSIGNED PRIMARY KEY,failures INT UNSIGNED NOT NULL DEFAULT 0,
        window_started BIGINT UNSIGNED NOT NULL DEFAULT 0,blocked_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
        FOREIGN KEY(user_id) REFERENCES users(id)
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_transfers (
        id CHAR(32) PRIMARY KEY,actor_user_id BIGINT UNSIGNED NOT NULL,
        source_user_id BIGINT UNSIGNED NOT NULL,destination_user_id BIGINT UNSIGNED NOT NULL,
        source_wallet_id BIGINT UNSIGNED NOT NULL,destination_wallet_id BIGINT UNSIGNED NOT NULL,
        credits INT UNSIGNED NOT NULL,unit_amount DECIMAL(14,4) NOT NULL,amount DECIMAL(14,4) NOT NULL,
        source_before DECIMAL(14,4) NOT NULL,source_after DECIMAL(14,4) NOT NULL,source_reserved DECIMAL(14,4) NOT NULL,
        destination_before DECIMAL(14,4) NOT NULL,destination_after DECIMAL(14,4) NOT NULL,destination_reserved DECIMAL(14,4) NOT NULL,
        created_at DATETIME NOT NULL,FOREIGN KEY(id) REFERENCES credit_transfer_quotes(id),
        FOREIGN KEY(actor_user_id) REFERENCES users(id),FOREIGN KEY(source_user_id) REFERENCES users(id),
        FOREIGN KEY(destination_user_id) REFERENCES users(id),FOREIGN KEY(source_wallet_id) REFERENCES wallets(id),
        FOREIGN KEY(destination_wallet_id) REFERENCES wallets(id)
    ){$tail}");
    foreach([['credit_transfers','idx_credit_transfer_source','source_user_id,created_at'],['credit_transfers','idx_credit_transfer_destination','destination_user_id,created_at'],['credit_transfer_quotes','idx_credit_transfer_actor','actor_user_id,created_at']]as[$table,$index,$columns]){
        if($mysql){$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');$q->execute([$table,$index]);if(!(int)$q->fetchColumn())$pdo->exec("CREATE INDEX {$index} ON {$table} ({$columns})");}
        else $pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table} ({$columns})");
    }
};
