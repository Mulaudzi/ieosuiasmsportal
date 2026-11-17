<?php
if(PHP_SAPI!=='cli')exit(1);
putenv('CREDIT_PURCHASE_MYSQL_TEST=1');putenv('CREDIT_PURCHASE_MYSQL_PORT=33165');
require __DIR__.'/run-customer-cash-balances.php';
require __DIR__.'/../domain/CashAdjustmentSetup.php';
if(!preg_match('/^sms_credit_[a-f0-9]{16}_test$/D',$fixtureName))throw new RuntimeException('Not a disposable test database.');
$pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user';ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1;ALTER TABLE users ADD COLUMN email_verified_at VARCHAR(30) DEFAULT '2026-01-01';CREATE TABLE schema_migrations(version VARCHAR(255) PRIMARY KEY,applied_at DATETIME NOT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO schema_migrations VALUES('015_credit_approval_security',NOW()),('017_customer_cash_balances',NOW()),('999_unrelated_fixture',NOW())");
$directory=__DIR__.'/../migrations';$hash=hash_file('sha256',$directory.'/025_cash_balance_adjustments.php');$cashHash=hash_file('sha256',$directory.'/017_customer_cash_balances.php');$setup=new CashAdjustmentSetup($pdo,$directory,$hash,$cashHash);$started=$checks;
function refused(callable $f):void{try{$f();throw new LogicException('Unsafe setup accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;check(true,'Setup denied');}}
function financialState(PDO $p):array{$out=[];foreach(['users','wallets','payments','wallet_ledger','customer_cash_ledger','credit_cash_allocations','credit_approval_security']as$t)$out[$t]=$p->query('SELECT * FROM '.$t)->fetchAll();return $out;}
$before=financialState($pdo);$history=$pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll();
$inspection=$setup->inspect();check($inspection['ready_to_apply']&&$inspection['read_only'],'Clean read-only preflight permits only 025');
check(financialState($pdo)===$before&&$pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll()===$history,'Inspection changes no schema bookkeeping or financial rows');
refused(fn()=>$setup->apply('019_email_campaigns',true));refused(fn()=>$setup->apply('025_cash_balance_adjustments',false));
$wrong=new CashAdjustmentSetup($pdo,$directory,str_repeat('0',64),$cashHash);check(!$wrong->inspect()['ready_to_apply'],'Changed migration hash blocks setup');refused(fn()=>$wrong->apply('025_cash_balance_adjustments',true));
$pdo->exec("DELETE FROM schema_migrations WHERE version='017_customer_cash_balances'");check(!$setup->inspect()['ready_to_apply'],'Missing prerequisite marker blocks apply, never runs 017 implicitly');refused(fn()=>$setup->apply('025_cash_balance_adjustments',true));
$pdo->exec("INSERT INTO schema_migrations(version,applied_at) VALUES('017_customer_cash_balances',NOW())");
$pdo->exec("CREATE TABLE customer_cash_adjustment_quotes(id CHAR(32) PRIMARY KEY) ENGINE=InnoDB");check(!$setup->inspect()['ready_to_apply']&&$setup->inspect()['partial_setup_requires_investigation'],'Partial schema is held for investigation');refused(fn()=>$setup->apply('025_cash_balance_adjustments',true));$pdo->exec('DROP TABLE customer_cash_adjustment_quotes');
$result=$setup->apply('025_cash_balance_adjustments',true);
check($result['code']==='CASH_BALANCE_ADJUSTMENTS_READY'&&$result['migration_recorded'],'Scoped apply returns verified success');
check($setup->inspect()['ready_to_apply']&&$setup->inspect()['migration_recorded'],'Exact columns, engines and uniqueness verified after setup');
check(financialState($pdo)===$before,'Schema setup preserves every synthetic financial/customer row');
check((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='019_email_campaigns'")->fetchColumn()===0,'Email migration remains excluded');
check((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='999_unrelated_fixture'")->fetchColumn()===1,'Other markers preserved');
$current=$pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll();$setup->apply('025_cash_balance_adjustments',true);check($current===$pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll(),'Repeated verification never rewrites migration timestamps');
$pdo->exec('ALTER TABLE customer_cash_adjustments DROP INDEX bank_hash');check(!$setup->inspect()['ready_to_apply'],'Missing deposit uniqueness fails closed');
// Recreate only this disposable fixture's missing-prerequisite state; never a live database.
$pdo->exec('DROP TABLE customer_cash_adjustments,customer_cash_adjustment_quotes,customer_cash_ledger,credit_cash_allocations');
$pdo->exec("DELETE FROM schema_migrations WHERE version IN ('017_customer_cash_balances','025_cash_balance_adjustments')");
$beforeWallets=$pdo->query('SELECT * FROM wallets')->fetchAll();$beforePayments=$pdo->query('SELECT * FROM payments')->fetchAll();
check($setup->inspect()['ready_to_apply']&&$setup->inspect()['cash_prerequisite_permission_required'],'Fully missing 017 may be offered with explicit permission');
refused(fn()=>$setup->apply('025_cash_balance_adjustments',true));
check(!$setup->inspect()['tables']['customer_cash_ledger']['exists'],'Unselected prerequisite does not run');
$combined=$setup->apply('025_cash_balance_adjustments',true,true);
check($combined['cash_prerequisite_applied']&&$setup->inspect()['ready_to_apply'],'Explicit 017 plus 025 applies and verifies all four tables');
check((int)$pdo->query('SELECT COUNT(*) FROM customer_cash_ledger')->fetchColumn()===0,'Missing prerequisite never infers historical opening balances');
check($beforeWallets===$pdo->query('SELECT * FROM wallets')->fetchAll()&&$beforePayments===$pdo->query('SELECT * FROM payments')->fetchAll(),'Combined setup leaves SMS wallets and historical payments untouched');
check((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='019_email_campaigns'")->fetchColumn()===0,'Combined setup never records email migration');
echo 'PASS scoped setup checks: '.($checks-$started)."; actual migration 025, no customer/finance mutations and no email migration.\n";
