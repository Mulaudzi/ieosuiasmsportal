<?php
/** Standalone verified rand credits only. Never rewrites wallets, payments or old cash entries. */
return static function(PDO $pdo):void {
 $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
 if($mysql){
  foreach(['users','credit_approval_security','credit_approval_attempts','credit_approval_events','customer_cash_ledger','credit_cash_allocations','credit_purchase_orders','payments']as$table){$q=$pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);if(strtolower((string)$q->fetchColumn())!=='innodb')throw new RuntimeException('Apply and verify the finance/cash prerequisites first: '.$table);}
 }
 $tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
 $pdo->exec("CREATE TABLE IF NOT EXISTS customer_cash_adjustment_quotes (
  id CHAR(32) PRIMARY KEY,actor_user_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,
  request_id CHAR(36) NOT NULL,input_hash CHAR(64) NOT NULL,source_kind VARCHAR(20) NOT NULL,
  order_id BIGINT UNSIGNED NULL,order_reference VARCHAR(100) NULL,bank_reference VARCHAR(120) NOT NULL,
  bank_hash CHAR(64) NOT NULL,cleared_cents BIGINT NOT NULL,recorded_cents BIGINT NOT NULL,
  amount_cents BIGINT NOT NULL,reason VARCHAR(1000) NOT NULL,expires_at BIGINT NOT NULL,created_at DATETIME NOT NULL,
  UNIQUE(actor_user_id,request_id)
 ){$tail}");
 $pdo->exec("CREATE TABLE IF NOT EXISTS customer_cash_adjustments (
  id CHAR(32) PRIMARY KEY,actor_user_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,
  source_kind VARCHAR(20) NOT NULL,order_id BIGINT UNSIGNED NULL,order_reference VARCHAR(100) NULL,
  bank_reference VARCHAR(120) NOT NULL,bank_hash CHAR(64) NOT NULL UNIQUE,
  cleared_cents BIGINT NOT NULL,recorded_cents BIGINT NOT NULL,amount_cents BIGINT NOT NULL,
  balance_before_cents BIGINT NOT NULL,balance_after_cents BIGINT NOT NULL,
  reason VARCHAR(1000) NOT NULL,security_version INTEGER NOT NULL,created_at DATETIME NOT NULL
 ){$tail}");
 if($mysql){
  require_once __DIR__.'/Migration.php';Migration::addIndex($pdo,'customer_cash_adjustments','idx_cash_adjustment_user','user_id,created_at');
  foreach(['customer_cash_adjustment_quotes','customer_cash_adjustments']as$table){$q=$pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);if(strtolower((string)$q->fetchColumn())!=='innodb')throw new RuntimeException('Finance adjustment tables must be transactional.');}
 }
 else $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cash_adjustment_user ON customer_cash_adjustments(user_id,created_at)');
};
