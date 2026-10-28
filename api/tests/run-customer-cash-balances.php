<?php
// Reuse the isolated checkout/controller fixture, never production configuration.
require __DIR__.'/run-credit-purchases.php';
if ($mysql) {$migration=require __DIR__.'/../migrations/017_customer_cash_balances.php';$migration($pdo);$migration($pdo);}
else $pdo->exec("CREATE TABLE customer_cash_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,order_id INTEGER,amount_cents INTEGER,event_key TEXT UNIQUE,description TEXT,created_at TEXT); CREATE TABLE credit_cash_allocations(order_id INTEGER PRIMARY KEY,user_id INTEGER,requested_credits INTEGER,choice TEXT,applied_cents INTEGER,sms_total_cents INTEGER,payment_cents INTEGER,received_cents INTEGER,status TEXT,created_at TEXT,updated_at TEXT)");
check(CustomerCashBalance::snapshot($pdo,1)['balance_cents']===0,'New cash ledger does not infer or reprice historical credits');
function cashBuy(int $credits,string $key,string $choice='keep_credits'):array {
    global $controller;
    Request::$data=['credits'=>$credits,'payment_method'=>'eft','balance_choice'=>$choice];
    $q=callApi(fn()=>$controller->quote())['quote'];
    Request::$data=['credits'=>$credits,'payment_method'=>'eft','balance_choice'=>$choice,'pricing_version'=>$q['pricing_version'],'quoted_total_cents'=>$q['total_cents'],'quoted_credits'=>$q['credits'],'quoted_cash_applied_cents'=>$q['cash_applied_cents'],'idempotency_key'=>$key];
    return callApi(fn()=>$controller->buy())['order'];
}
function cashApprove(array $o,int $cents,string $ref,bool $confirm=true,int $status=200):void {
    global $controller;
    Auth::$role='admin';Request::$data=['decision'=>'approve','note'=>'Fixture bank reconciliation','amount_received'=>number_format($cents/100,2,'.',''),'bank_reference'=>$ref,'funds_verified'=>true,'confirm_cash_difference'=>$confirm,'master_password'=>'fixture-master-approval-123'];
    callApi(fn()=>$controller->review(['id'=>$o['id']]),$status);
}
$o=cashBuy(1000,'cash-excess-000001');
cashApprove($o,30990,'CASH-EXCESS-01',false,409);
check(CustomerCashBalance::snapshot($pdo,1)['balance_cents']===0,'Unconfirmed difference changes neither cash nor credits');
cashApprove($o,30990,'CASH-EXCESS-01');
check(CustomerCashBalance::snapshot($pdo,1)['balance_cents']===300,'R3 overpayment is cash credit');
check((int)$pdo->query('SELECT credits_added FROM payments WHERE id='.(int)$o['payment_id'])->fetchColumn()===1000,'Overpayment does not silently change SMS quantity');
check((float)$pdo->query('SELECT amount FROM payments WHERE id='.(int)$o['payment_id'])->fetchColumn()===309.9,'Actual cleared money feeds cash received and receipt');
cashApprove($o,30990,'CASH-EXCESS-01');
check(CustomerCashBalance::snapshot($pdo,1)['balance_cents']===300,'Repeated approval does not duplicate cash credit');
Request::$data=CreditPurchasePricing::quote(1000,'eft')+['quoted_total_cents'=>30690,'idempotency_key'=>'cash-no-choice-0001'];
callApi(fn()=>$controller->buy(),409);
$keep=cashBuy(1000,'cash-keep-quantity-0001');
check((int)$keep['credits']===1000 && (int)$keep['total_cents']===30390,'Keep SMS quantity applies R3 cash credit to payment');
check(CustomerCashBalance::snapshot($pdo,1)['available_cents']===0,'Pending order reserves cash to prevent double spending');
$other=cashBuy(1000,'cash-second-order-0001');
check((int)$other['total_cents']===30690,'Second pending order cannot use the same R3');
Request::$data=['decision'=>'void','funds_not_received'=>true,'note'=>'Unpaid fixture'];
callApi(fn()=>$controller->review(['id'=>$keep['id']]));
check(CustomerCashBalance::snapshot($pdo,1)['available_cents']===300,'Void returns reserved cash without a cash-ledger mutation');
$budget=cashBuy(1000,'cash-keep-budget-0001','keep_payment');
check((int)$budget['total_cents']===30690 && (int)$budget['credits']>1000,'Keep payment budget buys additional SMS with cash credit');
$repeat=cashBuy(1000,'cash-keep-budget-0001','keep_payment');
check($repeat['id']===$budget['id'],'Idempotent retry uses frozen quantity despite the reserved balance');
cashApprove($budget,30690,'CASH-BUDGET-01');
$remaining=CustomerCashBalance::snapshot($pdo,1)['balance_cents'];
check($remaining>=0 && $remaining<35,'Rounding remainder remains as positive cash credit');
$short=cashBuy(1000,'cash-short-000001');
cashApprove($short,(int)$short['total_cents']-300,'CASH-SHORT-01');
check(CustomerCashBalance::snapshot($pdo,1)['balance_cents']===-300,'R3 shortfall is recorded as owed, separately from SMS credits');
$owed=cashBuy(1000,'cash-debt-quantity-001');
check((int)$owed['total_cents']===30990 && (int)$owed['credits']===1000,'Keep quantity adds debt to next payment');
Request::$data=['decision'=>'void','funds_not_received'=>true,'note'=>'Use alternative budget choice'];callApi(fn()=>$controller->review(['id'=>$owed['id']]));
$fewer=cashBuy(750,'cash-debt-budget-0001','keep_payment');
check((int)$fewer['total_cents']===CreditPurchasePricing::quote(750,'eft')['total_cents'] && (int)$fewer['credits']<750,'Keep budget settles debt and explicitly quotes fewer SMS');
cashApprove($fewer,(int)$fewer['total_cents'],'CASH-DEBT-BUDGET-01');
check(CustomerCashBalance::snapshot($pdo,1)['balance_cents']>=0,'Budget remainder is preserved after settling debt');
check(CustomerCashBalance::snapshot($pdo,2)['balance_cents']===0,'Cash balances remain tenant scoped');
foreach([50000,50001,250000,250001,500000] as $n)foreach([-300,300]as $balance){$q=CustomerCashBalance::quote($n,'eft','keep_payment',$balance);check($q['total_cents']===CreditPurchasePricing::quote($n,'eft')['total_cents'],'Budget remains exact at pricing boundaries');check($q['sms_total_cents']-$balance<=$q['total_cents'],'Balance covers frozen quantity at tier boundaries');}
require __DIR__.'/../controllers/PaymentWebhookController.php';
$callback=new PaymentWebhookController();$terminal=new ReflectionMethod($callback,'updateFailedPaymentState');
$cancel=cashBuy(1000,'cash-cancel-checkout-001');
$pdo->prepare("UPDATE credit_purchase_orders SET payment_method='payos' WHERE id=?")->execute([$cancel['id']]);
$pdo->prepare("UPDATE payments SET gateway='payos' WHERE id=?")->execute([$cancel['payment_id']]);
$q=$pdo->prepare('SELECT * FROM payments WHERE id=?');$q->execute([$cancel['payment_id']]);$payment=$q->fetch();
$walletBefore=$pdo->query('SELECT * FROM wallets')->fetchAll();
$terminal->invoke($callback,['id'=>$payment['transaction_id']],(int)$payment['id'],'cancelled','Verified fixture cancellation');
check(CreditPurchaseSettlement::order($pdo,(int)$payment['id'])['status']==='voided','Verified terminal checkout cancellation closes the order');
check($pdo->query('SELECT status FROM credit_cash_allocations WHERE order_id='.(int)$cancel['id'])->fetchColumn()==='released','Verified checkout cancellation releases cash allocation');
check($walletBefore===$pdo->query('SELECT * FROM wallets')->fetchAll(),'Cancelled checkout does not mutate SMS credits');
$terminal->invoke($callback,['id'=>$payment['transaction_id']],(int)$payment['id'],'cancelled','Repeated cancellation');
check($walletBefore===$pdo->query('SELECT * FROM wallets')->fetchAll(),'Repeated checkout cancellation is idempotent');
$q->execute([$o['payment_id']]);$completed=$q->fetch();
$terminal->invoke($callback,['id'=>$completed['transaction_id']],(int)$completed['id'],'cancelled','Delayed cancellation');
check($pdo->query('SELECT status FROM payments WHERE id='.(int)$completed['id'])->fetchColumn()==='completed','Delayed cancellation cannot downgrade a paid purchase');
Auth::$role='user';Request::$data=['master_password'=>'fixture-master-approval-123'];callApi(fn()=>$controller->enableCashBalances(),403);
Auth::$role='admin';Request::$data=['master_password'=>'incorrect'];callApi(fn()=>$controller->enableCashBalances(),422);
echo "PASS cash-balance and existing purchase checks: $checks; no live banking or SMS calls.\n";
