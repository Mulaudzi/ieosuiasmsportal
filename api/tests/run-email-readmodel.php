<?php
require __DIR__.'/run-credit-transfer-readmodel.php';
$p->exec("CREATE TABLE email_campaign_jobs(id TEXT PRIMARY KEY,user_id INTEGER,unit_cents INTEGER);CREATE TABLE email_campaign_recipients(id TEXT PRIMARY KEY,campaign_id TEXT,user_id INTEGER);
INSERT INTO email_campaign_jobs VALUES('email-campaign-fixture',7,7),('foreign-email-fixture',9,7);");
$rid=str_repeat('a',32);$foreign=str_repeat('b',32);
$q=$p->prepare('INSERT INTO email_campaign_recipients VALUES(?,?,?)');$q->execute([$rid,'email-campaign-fixture',7]);$q->execute([$foreign,'foreign-email-fixture',9]);
// Use explicit fixture values so no runtime identity or wallet is consulted.
$q=$p->prepare("INSERT INTO wallet_ledger(id,user_id,wallet_id,campaign_id,payment_id,operation,direction,amount,idempotency_key,created_at) VALUES(?,7,7,NULL,NULL,'email_debit','debit',.07,?,'2026-10-24 05:00:00')");$q->execute([20001,'email-message:'.$rid.':settle']);
$snapshot=$p->query('SELECT * FROM wallet_ledger ORDER BY id')->fetchAll();
foreach([.35,.5,.22]as$price){$a=PortalReadModel::activity($p,7,$filters+['search'=>'EML-'.strtoupper($rid)],$price);billingCheck($a['total']===1,'Public email reference searchable');$row=$a['transactions'][0];billingCheck($row['type']==='Email usage'&&$row['credits']===-0.2&&$row['amount']===-0.07,'Historical email uses exact fractional credits, not present SMS rate');billingCheck($a['total_paid']===10.0,'Email usage is not a credit purchase');}
billingCheck(PortalReadModel::activity($p,9,$filters+['search'=>'EML-'.strtoupper($rid)],.35)['total']===0,'Foreign account cannot read email usage');
$q->execute([20002,'email-message:'.$foreign.':settle']);$failed=false;try{PortalReadModel::activity($p,7,$filters+['search'=>'EML-'.strtoupper($foreign)],.35);}catch(RuntimeException){$failed=true;}billingCheck($failed,'Cross-owner email accounting corruption fails closed');$p->exec('DELETE FROM wallet_ledger WHERE id=20002');
billingCheck($snapshot===$p->query('SELECT * FROM wallet_ledger ORDER BY id')->fetchAll(),'Email read-model is non-mutating');
echo "PASS $checks combined billing, transfer and exact fractional email-usage checks; synthetic only.\n";
