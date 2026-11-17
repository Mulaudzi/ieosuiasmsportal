<?php
// Additive, app-local security only. Does not alter Central Auth, wallets or email campaigns.
return static function(PDO $pdo):void {
 if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
  $q=$pdo->query("SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'");
  if(strtolower((string)$q->fetchColumn())!=='innodb')throw new RuntimeException('App login security requires transactional InnoDB users.');
  $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='auth_version' AND data_type IN ('int','bigint','smallint')");
  if((int)$q->fetchColumn()!==1)throw new RuntimeException('App login security requires the existing auth_version prerequisite.');
 }
 $tail=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
 $pdo->exec("CREATE TABLE IF NOT EXISTS sms_login_security (
  user_id BIGINT UNSIGNED PRIMARY KEY,enabled INTEGER NOT NULL DEFAULT 0,version INTEGER NOT NULL DEFAULT 0,
  requested_at BIGINT NOT NULL DEFAULT 0,window_started BIGINT NOT NULL DEFAULT 0,send_count INTEGER NOT NULL DEFAULT 0,
  failures INTEGER NOT NULL DEFAULT 0,blocked_until BIGINT NOT NULL DEFAULT 0,recovery_hashes TEXT NULL,updated_at DATETIME NOT NULL
 ){$tail}");
 $pdo->exec("CREATE TABLE IF NOT EXISTS sms_login_challenges (
  token_hash CHAR(64) PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,purpose VARCHAR(12) NOT NULL,
  binding_hash CHAR(64) NOT NULL,email_hash CHAR(64) NOT NULL,auth_version INTEGER NOT NULL,security_version INTEGER NOT NULL,
  code_hash CHAR(64) NOT NULL,claims_json TEXT NOT NULL,expires_at BIGINT NOT NULL,active INTEGER NOT NULL DEFAULT 0,
  consumed INTEGER NOT NULL DEFAULT 0,created_at DATETIME NOT NULL
 ){$tail}");
 $pdo->exec("CREATE TABLE IF NOT EXISTS portal_security_events (
  event_key VARCHAR(100) PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(40) NOT NULL,payload_hash CHAR(64) NULL,created_at DATETIME NOT NULL
 ){$tail}");
 foreach([['sms_login_challenges','idx_sms_login_challenge_user','user_id,expires_at'],['portal_security_events','idx_portal_security_user','user_id,created_at']]as[$table,$index,$columns]){
  if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');$q->execute([$table,$index]);if(!(int)$q->fetchColumn())$pdo->exec("CREATE INDEX {$index} ON {$table} ({$columns})");}
  else $pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table} ({$columns})");
 }
};
