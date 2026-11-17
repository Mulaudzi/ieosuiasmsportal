<?php
declare(strict_types=1);
// Reuse the isolated legacy billing fixture, including its preservation assertions.
require __DIR__.'/run-portal-reconciled-billing.php';
$p->exec('CREATE TABLE credit_transfers(id TEXT PRIMARY KEY,source_user_id INTEGER,destination_user_id INTEGER,credits INTEGER,amount NUMERIC)');
$tid=str_repeat('a',32);$reference='TRF-'.strtoupper($tid);
$p->prepare('INSERT INTO credit_transfers VALUES(?,7,9,5,1.75)')->execute([$tid]);
$q=$p->prepare("INSERT INTO wallet_ledger(id,user_id,wallet_id,operation,direction,amount,idempotency_key,created_at) VALUES(?,?,?,?,?,?,?,'2026-10-24 12:00:00')");
$q->execute([4001,7,7,'credit_transfer_out','debit',1.75,'credit-transfer:'.$tid.':out']);
$q->execute([4002,9,9,'credit_transfer_in','credit',1.75,'credit-transfer:'.$tid.':in']);
$snap=$p->query('SELECT * FROM wallet_ledger ORDER BY id')->fetchAll();
foreach([[7,-5,-1.75],[9,5,1.75]]as[$uid,$credits,$amount]){
    $activity=PortalReadModel::activity($p,$uid,$filters+['search'=>$reference],.70);
    billingCheck($activity['total']===1,'Public transfer reference is searchable');$row=$activity['transactions'][0];
    billingCheck($row['type']==='Credit transfer'&&$row['reference']===$reference,'Transfers are not purchases or SMS usage');
    billingCheck($row['credits']===$credits&&$row['amount']===$amount,'Pinned credits survive later price changes');
    billingCheck($activity['total_paid']===($uid===7?10.0:0.0),'Transfers do not inflate payments');
}
billingCheck(PortalReadModel::activity($p,99,$filters+['search'=>$reference],.35)['total']===0,'Unrelated user cannot read transfer activity');
billingCheck($snap===$p->query('SELECT * FROM wallet_ledger ORDER BY id')->fetchAll(),'Transfer presentation remains read-only');
echo "PASS {$checks} combined legacy billing and transfer-presentation checks. Synthetic SQLite only.\n";
