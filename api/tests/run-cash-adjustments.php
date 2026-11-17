<?php
// Synthetic checkout data only; never loads production configuration or contacts a provider.
ob_start();
require __DIR__.'/run-customer-cash-balances.php';
$pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user'; ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1; ALTER TABLE users ADD COLUMN email_verified_at VARCHAR(30) DEFAULT '2026-01-01'; INSERT INTO users(id,name,email,role) VALUES(3,'Finance admin','finance@example.invalid','admin'),(4,'Other admin','admin@example.invalid','admin')");
$migration=require __DIR__.'/../migrations/025_cash_balance_adjustments.php';$migration($pdo);$migration($pdo);
putenv('CASH_BALANCE_ADJUSTMENTS_ENABLED=true');
$cashChecks=$checks;
function denied(callable $f,int $status=422):void{try{$f();throw new RuntimeException('Expected rejection '.$status);}catch(CashAdjustmentException|CreditApprovalException $e){check($e->status===$status,'Expected '.$status.', got '.$e->status.': '.$e->getMessage());}}
function randDraft(string $bank='DEPOSIT-ONE',string $amount='25.00',array $extra=[]):array{return $extra+['user_id'=>'1','source_kind'=>'deposit','order_reference'=>'','bank_reference'=>$bank,'cleared_amount'=>$amount,'reason'=>'Verified synthetic cleared funds','funds_verified'=>true,'request_id'=>sprintf('%08x-1111-4111-a111-%012x',random_int(1,100000000),random_int(1,100000000))];}
function randQuote(array $input):array{global $pdo;return CustomerCashAdjustment::quote($pdo,3,$input);}
function randCommit(array $quote):array{global $pdo;return CustomerCashAdjustment::execute($pdo,3,$quote['id'],'fixture-master-approval-123',true);}
check(CustomerCashAdjustment::available($pdo),'Additive schema ready without rewriting historical tables');
foreach(['0','-1','01.00','1.001','1e3','1,000.00','R10',' 10','10 ','10000000.01','NaN',10,1.1,null,[],true]as$invalid)denied(fn()=>CustomerCashAdjustment::cents($invalid));
foreach(['0.01'=>1,'1'=>100,'1.2'=>120,'125.50'=>12550,'10000000.00'=>1000000000]as$input=>$expected)check(CustomerCashAdjustment::cents((string)$input)===$expected,'Exact decimal cents');
denied(fn()=>CustomerCashAdjustment::quote($pdo,1,randDraft()),403);
$pdo->exec('UPDATE users SET is_active=0 WHERE id=3');denied(fn()=>randQuote(randDraft()),403);$pdo->exec('UPDATE users SET is_active=1 WHERE id=3');
$pdo->exec('UPDATE users SET email_verified_at=NULL WHERE id=3');denied(fn()=>randQuote(randDraft()),403);$pdo->exec("UPDATE users SET email_verified_at='2026-01-01' WHERE id=3");
denied(fn()=>randQuote(randDraft(extra:['user_id'=>'3'])));
$pdo->exec('UPDATE users SET is_active=0 WHERE id=2');denied(fn()=>randQuote(randDraft(extra:['user_id'=>'2'])));$pdo->exec('UPDATE users SET is_active=1 WHERE id=2');
denied(fn()=>randQuote(randDraft(extra:['funds_verified'=>false])));
denied(fn()=>randQuote(randDraft(extra:['amount_cents'=>999999])));
denied(fn()=>randQuote(randDraft(extra:['reason'=>'short'])));
putenv('CASH_BALANCE_ADJUSTMENTS_ENABLED=false');denied(fn()=>randQuote(randDraft()),503);putenv('CASH_BALANCE_ADJUSTMENTS_ENABLED=true');

$before=CustomerCashBalance::snapshot($pdo,1);$wallets=$pdo->query('SELECT * FROM wallets')->fetchAll();$payments=$pdo->query('SELECT * FROM payments')->fetchAll();$oldLedger=$pdo->query('SELECT * FROM customer_cash_ledger')->fetchAll();
$input=randDraft();$q=randQuote($input);
check(CustomerCashBalance::snapshot($pdo,1)===$before,'Quote moves no money');
check(randQuote($input)['id']===$q['id'],'Quote retry is immutable and idempotent');
denied(fn()=>randQuote(array_replace($input,['cleared_amount'=>'26.00'])),409);
denied(fn()=>CustomerCashAdjustment::execute($pdo,4,$q['id'],'fixture-master-approval-123',true),404);
denied(fn()=>CustomerCashAdjustment::execute($pdo,3,$q['id'],'incorrect',true));
check((int)$pdo->query('SELECT failures FROM credit_approval_attempts WHERE user_id=3')->fetchColumn()===1,'Bad finance password is durably counted');
check(CustomerCashBalance::snapshot($pdo,1)===$before,'Incorrect master password credits no money');
denied(fn()=>CustomerCashAdjustment::execute($pdo,3,$q['id'],'fixture-master-approval-123',false));
$receipt=randCommit($q);
check($receipt['amount_cents']===2500&&$receipt['balance_after_cents']===$before['balance_cents']+2500,'Cleared deposit adds rand credit exactly');
check(randCommit($q)==$receipt,'Commit replay returns original receipt once');
check(CustomerCashBalance::snapshot($pdo,1)['reserved_cents']===$before['reserved_cents'],'Cash reservations preserved');
check($wallets===$pdo->query('SELECT * FROM wallets')->fetchAll(),'No SMS credits created');
check($payments===$pdo->query('SELECT * FROM payments')->fetchAll(),'Historical receipts/payments not edited');
check($oldLedger===$pdo->query('SELECT * FROM customer_cash_ledger')->fetchAll(),'Existing cash ledger is append-preserved');
check(CustomerCashAdjustment::status($pdo,3,$q['id'])['adjustment']==$receipt,'Response-loss recovery returns original receipt');
denied(fn()=>CustomerCashAdjustment::status($pdo,4,$q['id']),404);
denied(fn()=>randQuote(randDraft(' deposit - one ')),409);
try{CustomerCashAdjustment::assertBankNotAssigned($pdo,'DEPOSIT-ONE');throw new RuntimeException('Deposit reuse accepted');}catch(DomainException){$checks++;}
check((int)$pdo->query("SELECT COUNT(*) FROM credit_approval_events WHERE event='rand_credit_recorded'")->fetchColumn()===1,'Exactly one immutable finance audit');

// A previously approved overpayment already feeds the existing cash ledger. Do not add it twice.
$completedOrder=$pdo->query("SELECT o.reference,o.bank_reference,p.amount FROM credit_purchase_orders o JOIN payments p ON p.id=o.payment_id WHERE o.bank_reference='CASH-EXCESS-01'")->fetch();
$over=randDraft('CASH-EXCESS-01','309.90',['source_kind'=>'overpayment','order_reference'=>$completedOrder['reference']]);
denied(fn()=>randQuote($over),409);
denied(fn()=>randQuote(array_replace($over,['cleared_amount'=>'308.00'])),409);
denied(fn()=>randQuote(randDraft('CASH-EXCESS-01','315.00')),409);
denied(fn()=>randQuote(array_replace($over,['cleared_amount'=>'315.00','user_id'=>'2'])),404);
denied(fn()=>randQuote(array_replace($over,['cleared_amount'=>'315.00','bank_reference'=>'OTHER-BANK'])),409);
$over['cleared_amount']='315.00';$q=randQuote($over);
check($q['recorded_cents']===30990&&$q['amount_cents']===510,'Overpayment uses actual previously received payment, not order total');
$r=randCommit($q);check($r['amount_cents']===510,'Only additional R5.10 credited');
denied(fn()=>randQuote(array_replace($over,['request_id'=>randDraft()['request_id'],'cleared_amount'=>'320.00'])),409);
check($payments===$pdo->query('SELECT * FROM payments')->fetchAll(),'Late credit leaves historical receipts unchanged');

// The very same verified deposit cannot both fund an old purchase and grant independent cash.
$pendingOrder=cashBuy(1000,'rand-reuse-order-001');
$cashBefore=CustomerCashBalance::snapshot($pdo,1);cashApprove($pendingOrder,(int)$pendingOrder['total_cents'],'DEPOSIT-ONE',true,409);
check(CustomerCashBalance::snapshot($pdo,1)===$cashBefore,'Order approval cannot reuse a manually credited deposit');
$reuseQuote=randQuote(randDraft('ORDER-WINS-ONE','500.00'));
cashApprove($pendingOrder,(int)$pendingOrder['total_cents'],'ORDER-WINS-ONE');
denied(fn()=>randCommit($reuseQuote),409);

$expired=randQuote(randDraft('EXPIRED-ONE'));
$pdo->prepare('UPDATE customer_cash_adjustment_quotes SET expires_at=? WHERE id=?')->execute([time()-1,$expired['id']]);
denied(fn()=>randCommit($expired),409);
check(CustomerCashAdjustment::status($pdo,3,$expired['id'])['expired']===true,'Expired review recovers read-only');
$staleOrder=cashBuy(1000,'rand-stale-payment-01');cashApprove($staleOrder,(int)$staleOrder['total_cents'],'STALE-PAYMENT-ONE');
$oldAmount=$pdo->query('SELECT amount FROM payments WHERE id='.(int)$staleOrder['payment_id'])->fetchColumn();
$stale=randQuote(randDraft('STALE-PAYMENT-ONE',number_format((float)$oldAmount+10,2,'.',''),['source_kind'=>'overpayment','order_reference'=>$staleOrder['reference']]));
$pdo->prepare('UPDATE payments SET amount=? WHERE id=?')->execute([(float)$oldAmount+1,$staleOrder['payment_id']]);
denied(fn()=>randCommit($stale),409);
$pdo->prepare('UPDATE payments SET amount=? WHERE id=?')->execute([$oldAmount,$staleOrder['payment_id']]);

$held=randQuote(randDraft('RATE-LIMIT-CHECK'));
for($i=0;$i<5;$i++)denied(fn()=>CustomerCashAdjustment::execute($pdo,3,$held['id'],'bad',true),$i===4?429:422);
denied(fn()=>randCommit($held),429);
$pdo->exec('UPDATE credit_approval_attempts SET blocked_until=0,window_started=0');
check(count(CustomerCashAdjustment::accounts($pdo,3,'Customer'))===1,'Scoped customer search is independent of SMS transfer flags');
check(count(CustomerCashAdjustment::accounts($pdo,3,'%_'))===0,'Customer search escapes wildcard characters');
check(CustomerCashAdjustment::history($pdo,3,2)['total']===0,'Customer history does not leak another customer balance');
$rollbackQuote=randQuote(randDraft('ROLLBACK-ONE'));
$beforeRollback=CustomerCashBalance::snapshot($pdo,1);
$pdo->exec($mysql?"CREATE TRIGGER deny_rand_audit BEFORE INSERT ON credit_approval_events FOR EACH ROW BEGIN IF NEW.event='rand_credit_recorded' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'; END IF; END":"CREATE TRIGGER deny_rand_audit BEFORE INSERT ON credit_approval_events WHEN NEW.event='rand_credit_recorded' BEGIN SELECT RAISE(ABORT,'Synthetic audit failure'); END");
try{randCommit($rollbackQuote);throw new RuntimeException('Missing audit accepted');}catch(PDOException){$checks++;}
check(CustomerCashBalance::snapshot($pdo,1)===$beforeRollback,'Audit failure rolls back entire grant');
check(CustomerCashAdjustment::status($pdo,3,$rollbackQuote['id'])['status']==='not_completed','Rollback can be safely reconciled');
$pdo->exec('DROP TRIGGER deny_rand_audit');
randCommit($rollbackQuote);
Auth::$id=1;Auth::$role='user';$cashPurchase=cashBuy(1000,'rand-usable-cash-001');
check((int)$cashPurchase['cash_adjustment']['applied_cents']>0,'Standalone grants are usable by existing purchase reservations');
check(CustomerCashBalance::snapshot($pdo,1)['available_cents']<CustomerCashBalance::snapshot($pdo,1)['balance_cents'],'Existing purchase reserves granted cash instead of double spending');
putenv('CASH_BALANCE_ADJUSTMENTS_ENABLED=false');$balance=CustomerCashBalance::snapshot($pdo,1);
check($balance['balance_cents']>0,'Disabling new credits never removes existing rand funds');
check(CustomerCashAdjustment::status($pdo,3,$q['id'])['status']==='completed','Read-only status survives feature deactivation');
check(CustomerCashAdjustment::history($pdo,3)['enabled']===false,'Kill switch disables new grants');
putenv('CASH_BALANCE_ADJUSTMENTS_ENABLED=true');
require __DIR__.'/../controllers/CashAdjustmentController.php';
if(!class_exists('RateLimiter')){class RateLimiter {public static array $calls=[];public static function checkOrFail(string $key,int $max,int $minutes):void{self::$calls[]=[$key,$max,$minutes];}}}
$apiController=new CashAdjustmentController();Auth::$id=3;Auth::$role='admin';Request::$query=[];
check(callApi(fn()=>$apiController->index())['enabled']===true,'Admin history controller returns actual activation state');
Request::$query=['search'=>'Customer'];check(count(callApi(fn()=>$apiController->accounts())['accounts'])===1,'Admin account lookup contract');
Request::$query=['user_id'=>['1']];callApi(fn()=>$apiController->index(),422);Request::$query=[];
Request::$data=randDraft('CONTROLLER-ONE','15.00');$apiQuote=callApi(fn()=>$apiController->quote())['quote'];
Request::$data=['quote_id'=>$apiQuote['id'],'confirmed'=>true,'master_password'=>'fixture-master-approval-123','amount_cents'=>9999];callApi(fn()=>$apiController->commit(),422);
Request::$data=['quote_id'=>$apiQuote['id'],'confirmed'=>true,'master_password'=>'incorrect'];callApi(fn()=>$apiController->commit(),422);
Request::$data=['quote_id'=>$apiQuote['id'],'confirmed'=>true,'master_password'=>'fixture-master-approval-123'];$apiReceipt=callApi(fn()=>$apiController->commit())['adjustment'];
check($apiReceipt['amount_cents']===1500,'Admin commit controller credits exact reviewed amount');
check(callApi(fn()=>$apiController->status(['id'=>$apiQuote['id']]))['status']==='completed','Status controller recovers committed receipt');
Auth::$id=4;callApi(fn()=>$apiController->status(['id'=>$apiQuote['id']]),404);
Auth::$id=1;Auth::$role='user';callApi(fn()=>$apiController->index(),403);callApi(fn()=>$apiController->quote(),403);callApi(fn()=>$apiController->commit(),403);
Auth::$id=3;Auth::$role='admin';putenv('CASH_BALANCE_ADJUSTMENTS_ENABLED=false');Request::$data=randDraft('HTTP-DISABLED');callApi(fn()=>$apiController->quote(),503);putenv('CASH_BALANCE_ADJUSTMENTS_ENABLED=true');
echo 'PASS standalone rand-credit checks: '.($checks-$cashChecks)."; no production banking, credits or messages touched.\n";
ob_end_flush();
