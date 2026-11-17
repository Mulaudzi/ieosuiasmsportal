<?php
/** Scoped additive repair. No customer balances, transfers or email backfills. */
return static function(PDO $pdo):void {
 $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
 $tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
 // The deployed notification reconciler already depends on migration 013's table.
 $pdo->exec("CREATE TABLE IF NOT EXISTS notification_events (
  user_id BIGINT UNSIGNED NOT NULL,event_key VARCHAR(160) NOT NULL,created_at DATETIME NOT NULL,
  PRIMARY KEY(user_id,event_key)
 ){$tail}");
 $pdo->exec("CREATE TABLE IF NOT EXISTS credit_transfer_receipts (
  transfer_id CHAR(32) PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,recipient_email VARCHAR(254) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',attempts INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,locked_at DATETIME NULL,sent_at DATETIME NULL,
  error_fingerprint CHAR(16) NULL
 ){$tail}");
 if($mysql){
  require_once __DIR__.'/Migration.php';
  foreach(['notification_events','credit_transfer_receipts'] as $table){
   $q=$pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);
   if(strtolower((string)$q->fetchColumn())!=='innodb')throw new RuntimeException('Notification queues require InnoDB.');
  }
  Migration::addIndex($pdo,'credit_transfer_receipts','idx_transfer_receipt_due','status,created_at');
 }else $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transfer_receipt_due ON credit_transfer_receipts(status,created_at)');
};
