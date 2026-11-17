<?php
declare(strict_types=1);
// The imported fixture uses isolated in-memory SQLite and a fake provider only.
require __DIR__.'/run-dispatch-no-resend.php';
$bulkChecks=0;
function bulkCheck(bool $ok,string $label):void{global $bulkChecks;if(!$ok)throw new RuntimeException($label);$bulkChecks++;}
$walletBefore=$pdo->query('SELECT * FROM wallets')->fetchAll();
$ledgerBefore=$pdo->query('SELECT * FROM wallet_ledger')->fetchAll();
$callsBefore=$provider->calls;
foreach([2,485]as$count){
    $campaign=8000+$count;
    $pdo->prepare("INSERT INTO sms_campaigns(id,user_id,state,scheduled_at) VALUES(?,1,'queued','2099-01-01 11:00:00')")->execute([$campaign]);
    $insert=$pdo->prepare("INSERT INTO sms_messages(id,campaign_id,user_id,destination,content,state,estimated_charge,segment_count,attempt_count) VALUES(?,?,1,'27800000001','Synthetic scheduled fixture','pending',0.35,1,0)");
    for($i=0;$i<$count;$i++)$insert->execute([200000+$i,$campaign]);
    bulkCheck(SmsDispatchService::claimBatch(100,'early-bulk')===[],"$count recipients: no early claim even when queued incorrectly");
    $q=$pdo->prepare('SELECT SUM(attempt_count) FROM sms_messages WHERE campaign_id=?');$q->execute([$campaign]);bulkCheck((int)$q->fetchColumn()===0,"$count recipients: future messages retain zero attempts");
    $pdo->prepare("UPDATE sms_campaigns SET state='scheduled',scheduled_at=NOW() WHERE id=?")->execute([$campaign]);
    bulkCheck(SmsDispatchService::claimBatch(100,'not-yet-promoted')===[],"$count recipients: only the scheduler promotes scheduled campaigns");
    $pdo->prepare("UPDATE sms_campaigns SET state='queued' WHERE id=?")->execute([$campaign]);
    $claimed=[];$batches=[];
    do{$batch=SmsDispatchService::claimBatch(100,'bulk-'.count($batches));if($batch){$batches[]=count($batch);foreach($batch as$message)$claimed[]=$message['id'];}}while($batch);
    bulkCheck(count($claimed)===$count&&count(array_unique($claimed))===$count,"$count recipients: each due message claimed exactly once");
    bulkCheck($batches===($count===2?[2]:[100,100,100,100,85]),"$count recipients: safe batching preserves all recipients");
    bulkCheck($pdo->query('SELECT * FROM wallets')->fetchAll()===$walletBefore&&$pdo->query('SELECT * FROM wallet_ledger')->fetchAll()===$ledgerBefore,"$count recipients: scheduling and claiming do not debit or reserve again");
    $pdo->prepare('DELETE FROM sms_messages WHERE campaign_id=?')->execute([$campaign]);$pdo->prepare('DELETE FROM sms_campaigns WHERE id=?')->execute([$campaign]);
}
bulkCheck($provider->calls===$callsBefore,'Bulk scheduling tests never submit to a provider');
echo "PASS: $bulkChecks small/bulk scheduling, exact-once claim and wallet checks; no network or live database.\n";
