<?php
// SQLite only. Reuse pricing, frozen-order, approval and ledger regressions.
if (PHP_SAPI !== 'cli' || getenv('CREDIT_PURCHASE_MYSQL_TEST') === '1') exit(1);
require __DIR__.'/run-credit-purchases.php';
putenv('PAYOS_PUBLIC_KEY=');putenv('PAYOS_API_SECRET=');putenv('PAYOS_CREATE_URL=');
putenv('PAYOS_BASE_URL=https://checkout.example.invalid');
$pdo->exec('CREATE TABLE accounts(id INTEGER PRIMARY KEY,user_id INTEGER,company_name TEXT)');
Auth::$role='user';Auth::$id=1;
$beforeWallets=$pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll();
$beforeLedger=(int)$pdo->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn();
putenv('SMS_BANK_TRANSFER_ENABLED=false'); // Online must not depend on direct EFT availability.
foreach ([50001,250001,500000] as $credits) {
    $quote=CreditPurchasePricing::quote($credits,'payos');
    $key='online-limit-fixture-'.$credits;
    $count=(int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
    Request::$data=$quote+['quoted_total_cents'=>$quote['total_cents'],'quoted_credits'=>$credits,'idempotency_key'=>$key];
    // Deliberately unconfigured transport must stop before any HTTP call. The
    // persisted order proves these quantities reach normal checkout initiation.
    callApi(fn()=>$controller->buy(),502);
    $q=$pdo->prepare('SELECT * FROM credit_purchase_orders WHERE user_id=1 AND idempotency_key=?');$q->execute([$key]);$order=$q->fetch();
    check((int)$order['credits']===$credits && $order['payment_method']==='payos','Large online order freezes requested credits and method');
    check((int)$order['discount_cents']===0 && (int)$order['subtotal_cents']===$quote['total_cents'] && (int)$order['total_cents']===$quote['total_cents'],'No service fee, discount or hidden markup on online orders');
    $p=$pdo->prepare('SELECT * FROM payments WHERE id=?');$p->execute([$order['payment_id']]);$payment=$p->fetch();
    check((int)round((float)$payment['amount']*100)===$quote['total_cents'],'Checkout payment equals reviewed cents');
    callApi(fn()=>$controller->buy(),502);
    check((int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn()===$count+1,'Retry reuses the saved online payment');
    $url='https://checkout.example.invalid/'.$order['reference'];
    $pdo->prepare('UPDATE credit_purchase_orders SET checkout_url=? WHERE id=?')->execute([$url,$order['id']]);
    $result=callApi(fn()=>$controller->buy());
    check($result['payment_url']===$url && (int)$result['order']['id']===(int)$order['id'],'Saved online checkout returns the frozen order and URL');
}
check($beforeWallets===$pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll(),'Creating/retrying online purchases never credits wallets');
check($beforeLedger===(int)$pdo->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn(),'Creating/retrying online purchases never creates usage or credit ledger entries');
putenv('SMS_BANK_TRANSFER_ENABLED=true');
echo 'PASS: '.$checks.' combined online-limit and purchase checks; no gateway, bank, email or production calls.'.PHP_EOL;
