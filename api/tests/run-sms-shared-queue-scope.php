<?php
declare(strict_types=1);
// Real claimBatch with isolated SQLite and the fake provider from the crash fixture.
// No application configuration, network, credentials or production database.
require __DIR__.'/run-dispatch-no-resend.php';

$scopeChecks=0;
function scopeCheck(bool $ok,string $label):void {
    global $scopeChecks;
    if(!$ok)throw new RuntimeException($label);
    $scopeChecks++;
}
scopeCheck($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite','Only the isolated SQLite fixture is permitted');
$callsBefore=$provider->calls;
$pdo->exec('DELETE FROM sms_messages; DELETE FROM sms_campaigns');
$pdo->exec('INSERT INTO users(id,is_active) VALUES(2,1),(3,0)');
$pdo->exec('INSERT INTO wallets(id,user_id,balance,reserved) VALUES(2,2,20,0.70)');
$pdo->exec("INSERT INTO sms_campaigns(id,user_id,state,scheduled_at) VALUES
    (9001,1,'queued','2026-10-23 12:00:00'),
    (9002,2,'queued','2026-10-23 12:00:00'),
    (9003,2,'queued','2099-01-01 12:00:00'),
    (9004,3,'queued','2026-10-23 12:00:00')");
$insert=$pdo->prepare("INSERT INTO sms_messages(id,campaign_id,user_id,state,estimated_charge,segment_count,attempt_count) VALUES(?,?,?,'pending',.35,1,0)");
foreach([[9001,9001,1],[9002,9002,2],[9003,9003,2],[9004,9004,3]] as $row)$insert->execute($row);
$walletsBefore=$pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll();
$ledgerBefore=$pdo->query('SELECT * FROM wallet_ledger ORDER BY id')->fetchAll();

$claimed=SmsDispatchService::claimBatch(2,'scope-first');
scopeCheck(array_column($claimed,'id')===[9001,9002],'A bounded batch can claim two unrelated customer campaigns');
scopeCheck(array_column($claimed,'user_id')===[1,2],'Batch size is not a customer or campaign isolation boundary');
scopeCheck(count(array_filter($claimed,static fn($row)=>(int)$row['attempt_count']===1))===2,'Each eligible row is claimed once');
scopeCheck($pdo->query('SELECT state FROM sms_messages WHERE id=9003')->fetchColumn()==='pending','Future campaign remains unclaimed');
scopeCheck($pdo->query('SELECT state FROM sms_messages WHERE id=9004')->fetchColumn()==='pending','Inactive customer remains excluded');
scopeCheck(SmsDispatchService::claimBatch(1,'scope-empty')===[],'Already leased rows are not claimed again');

// An empty eligible snapshot does not isolate the next cycle from new arrivals.
$pdo->exec("INSERT INTO sms_campaigns(id,user_id,state,scheduled_at) VALUES(9005,2,'queued','2026-10-23 12:00:00')");
$insert->execute([9005,9005,2]);
$later=SmsDispatchService::claimBatch(1,'scope-arrival');
scopeCheck(count($later)===1&&(int)$later[0]['id']===9005&&(int)$later[0]['user_id']===2,'A later customer arrival becomes eligible after the previous empty snapshot');

$pdo->exec("UPDATE sms_messages SET state='pending',lease_token=NULL,leased_at=NULL,next_attempt_at=NULL WHERE id IN (9001,9002,9005)");
$single=SmsDispatchService::claimBatch(1,'scope-one');
scopeCheck(count($single)===1&&(int)$single[0]['id']===9001&&(int)$single[0]['user_id']===1,'Limit one chooses global queue order rather than the intended second customer');
scopeCheck($provider->calls===$callsBefore,'Claim-scope checks perform no provider submission');
scopeCheck($pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll()===$walletsBefore,'All fixture wallet balances and reservations remain unchanged');
scopeCheck($pdo->query('SELECT * FROM wallet_ledger ORDER BY id')->fetchAll()===$ledgerBefore,'No reservation or charge journals are added by claiming');

echo "PASS: $scopeChecks shared-queue isolation boundary checks; SQLite only, no network or real SMS.\n";
