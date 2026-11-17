<?php
// Independent of email migration 019. Additive classification only; no contact/consent/billing rewrites.
return static function(PDO $pdo): void {
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_tags(id CHAR(32) PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,name VARCHAR(80) NOT NULL,name_key CHAR(64) NOT NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,UNIQUE(user_id,name_key)){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_tag_memberships(tag_id CHAR(32) NOT NULL,contact_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(tag_id,contact_id),FOREIGN KEY(tag_id) REFERENCES contact_tags(id) ON DELETE CASCADE,FOREIGN KEY(contact_id) REFERENCES contacts(id) ON DELETE CASCADE){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_tag_audit(id CHAR(32) PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,operation VARCHAR(30) NOT NULL,details_json TEXT NOT NULL,created_at DATETIME NOT NULL){$tail}");
    foreach([['contact_tag_memberships','idx_contact_tag_owner','user_id,contact_id'],['contact_tag_audit','idx_contact_tag_audit','user_id,created_at']]as[$table,$index,$columns]){
        if($mysql){$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');$s->execute([$table,$index]);if(!(int)$s->fetchColumn())$pdo->exec("CREATE INDEX {$index} ON {$table} ({$columns})");}
        else $pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table} ({$columns})");
    }
};
