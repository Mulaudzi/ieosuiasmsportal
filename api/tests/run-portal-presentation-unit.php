<?php
require_once __DIR__.'/../services/PortalReadModel.php';
require_once __DIR__.'/../services/CampaignRecipientExport.php';
final class PortalFixture extends PDO {public function prepare(string $sql,array $options=[]):PDOStatement|false{return parent::prepare(str_replace('TIMESTAMPDIFF(SECOND,',"TIMESTAMPDIFF('SECOND',",$sql),$options);}}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$pdo=new PortalFixture('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('CONCAT',fn(...$args)=>implode('',$args));$pdo->sqliteCreateFunction('FLOOR',fn($n)=>floor($n),1);$pdo->sqliteCreateFunction('TIMESTAMPDIFF',fn($u,$s,$e)=>strtotime($e)-strtotime($s),3);
$pdo->exec('CREATE TABLE contacts(id INTEGER,user_id INTEGER,name TEXT,surname TEXT,phone_normalized TEXT); CREATE TABLE sms_campaigns(id INTEGER,user_id INTEGER,name TEXT); CREATE TABLE sms_messages(id INTEGER,user_id INTEGER,campaign_id INTEGER,destination TEXT,state TEXT,last_error TEXT,segment_count INTEGER,actual_charge REAL,sent_at TEXT,delivered_at TEXT,created_at TEXT); CREATE TABLE payments(id INTEGER,user_id INTEGER,merchant_reference TEXT,amount REAL,credits_added INTEGER,status TEXT,created_at TEXT); CREATE TABLE wallet_ledger(id INTEGER,user_id INTEGER,wallet_id INTEGER,campaign_id INTEGER,payment_id INTEGER,operation TEXT,direction TEXT,amount REAL,idempotency_key TEXT,created_at TEXT); CREATE TABLE wallet_transactions(wallet_id INTEGER,reference TEXT,description TEXT)');
$pdo->exec('CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER); INSERT INTO wallets VALUES(3,7),(8,8)');
$now=date('Y-m-d H:i:s');$sent=date('Y-m-d H:i:s',time()-200000);
$pdo->exec("INSERT INTO sms_campaigns VALUES(1,7,'AUTUMN NOTICE'),(2,8,'Other account'); INSERT INTO contacts VALUES(1,7,'Example','Person','+27000000000'),(2,7,'Duplicate','Contact','+27000000000'),(3,8,'Foreign','Person','+27000000000')");
$insert=$pdo->prepare('INSERT INTO sms_messages VALUES(?,?,?,?,?,?,?,?,?,?,?)');
foreach([1800,2700,3600,10800,176400] as $i=>$seconds)$insert->execute([$i+1,7,1,'+27000000000','delivered','DLR lookup: old problem',1,.35,$sent,date('Y-m-d H:i:s',strtotime($sent)+$seconds),$sent]);
foreach(['sent','failed','pending','queued','retry','processing','skipped'] as $i=>$state)$insert->execute([$i+6,7,1,'+27111111111',$state,$state==='failed'?'invalid number':null,1,in_array($state,['sent','failed'])?.35:0,in_array($state,['sent','failed'])?$sent:null,null,$sent]);
$insert->execute([20,7,1,'+27222222222','sent','DLR lookup: Cannot Find Message',1,.35,$sent,null,$sent]);
$insert->execute([21,8,2,'+27000000000','delivered',null,1,.35,$sent,$now,$sent]);
$p=$pdo->prepare('INSERT INTO payments VALUES(?,?,?,?,?,?,?)');foreach([[1,7,'PAID-1',10,28,'completed'],[2,7,'PENDING-2',20,57,'pending'],[3,7,'FAILED-3',30,85,'failed'],[4,7,'REFUND-4',40,114,'refunded'],[5,8,'FOREIGN-5',999,999,'completed']] as $row)$p->execute([...$row,$now]);
$l=$pdo->prepare('INSERT INTO wallet_ledger VALUES(?,?,?,?,?,?,?,?,?,?)');$l->execute([1,7,3,null,null,'manual_adjustment','credit',140,'ADJUSTMENT-1',$now]);$l->execute([2,7,3,null,1,'payment_credit','credit',10,'PAID-1',$now]);$l->execute([3,7,3,1,null,'reservation','reserve',50,'RESERVATION',$now]);
$pdo->exec("INSERT INTO wallet_transactions VALUES(3,'ADJUSTMENT-1','Approved credit allocation')");
$before=$pdo->query('SELECT * FROM sms_messages')->fetchAll();
$overview=PortalReadModel::overview($pdo,7);check($overview['contacts']===2&&$overview['bought_credits']&&$overview['sent_campaign'],'Overview counts');check(PortalReadModel::overview($pdo,99)['contacts']===0,'Overview ownership');
$confirmation=PortalReadModel::confirmation($pdo,7,'7d');check($confirmation['seconds']===10800.0&&$confirmation['sample_count']===5,'Typical time must be P75');check(PortalReadModel::confirmation($pdo,8,'7d')['seconds']===null,'Hide sparse time sample');
$all=PortalReadModel::recipients($pdo,7,1);check($all['total']===13,'No duplicate contact joins');check($all['rows'][0]['contact']==='Example Person','Owned contact name');check($all['rows'][0]['result']==='Delivery confirmed by the mobile network.','Current delivery meaning overrides stale lookup error');
check(PortalReadModel::recipients($pdo,7,1,'pending')['total']===3,'Pending dispatch combines pending, queued and retry');check(PortalReadModel::recipients($pdo,7,1,'sent')['total']===2,'Failed lookup remains confirmation pending');check(PortalReadModel::recipients($pdo,7,1,'failed')['rows'][0]['result']==='The mobile network reported that your SMS could not be delivered.','Failure has safe customer explanation');
check(PortalReadModel::recipients($pdo,7,1,'all','absent')['total']===0,'Empty search');
check(PortalReadModel::recipients($pdo,7,1,'all','','1')['total']===13,'Single segment filter');
check(PortalReadModel::recipients($pdo,7,1,'all','','multi')['total']===0,'Multi segment filter');
check(PortalReadModel::recipients($pdo,7,1,'all','example')['total']===5,'Case-insensitive contact search');
check(PortalReadModel::recipients($pdo,7,1,'all','%')['total']===0,'Search wildcard is literal');
$pageTwo=PortalReadModel::recipients($pdo,7,1,'all','','all',2);check($pageTwo['total']===13&&$pageTwo['rows']===[],'Database pagination preserves filtered total');
try{PortalReadModel::recipients($pdo,7,1,'all','','invalid');throw new Exception('Invalid segments accepted');}catch(InvalidArgumentException){}
try{PortalReadModel::recipients($pdo,8,1);throw new Exception('Ownership failed');}catch(OutOfBoundsException){}
foreach(['all','delivered','failed','pending','processing','sent'] as $state){$r=PortalReadModel::recipients($pdo,7,1,$state);$csv=CampaignRecipientExport::csv($r);check(str_starts_with($csv,'Contact,Number,Status,Segments,Sent,Result'),'CSV headers');check(count(explode("\n",trim($csv)))===$r['total']+1,'CSV selected rows');$pdf=CampaignRecipientExport::pdf($r);check(str_starts_with($pdf,'%PDF-')&&str_contains($pdf,'(Contact)')&&str_contains($pdf,'(Segments)')&&str_contains($pdf,'(Result)'),'PDF all table columns');}
check(CampaignRecipientExport::filename(PortalReadModel::recipients($pdo,7,1,'failed'))==='autumn-notice-not-delivered','Dynamic filename');
$filters=['period'=>'custom','start'=>date('Y-m-d',time()-400000),'end'=>date('Y-m-d'),'per_page'=>50];
$activity=PortalReadModel::activity($pdo,7,$filters,.35);check($activity['total']===6,'Payment/usage/adjustment deduplication');check($activity['total_paid']===10.0,'Payments must not be spend or refunds');
$types=array_count_values(array_column($activity['transactions'],'type'));check($types===['Purchase'=>4,'Credit adjustment'=>1,'SMS usage'=>1]||($types['Purchase']===4&&$types['SMS usage']===1&&$types['Credit adjustment']===1),'Three transaction types');
$adjust=array_values(array_filter($activity['transactions'],fn($row)=>$row['type']==='Credit adjustment'))[0];check($adjust['credits']===400&&$adjust['detail']==='Approved credit allocation','Adjustment credits and reason');
check(PortalReadModel::activity($pdo,7,$filters+['status'=>'paid'],.35)['total']===1,'Paid filter');check(PortalReadModel::activity($pdo,7,$filters+['search'=>'PENDING-2'],.35)['total']===1,'Reference search');check(PortalReadModel::activity($pdo,7,$filters+['search'=>'20'],.35)['total']===1,'Amount search');check(PortalReadModel::activity($pdo,99,$filters,.35)['total']===0,'Activity isolation');
check($pdo->query('SELECT * FROM sms_messages')->fetchAll()===$before,'Read model wrote data');
echo "PASS: overview ownership, reliable confirmation time, recipient filters/reasons, CSV/PDF parity, real payment totals, usage/adjustment deduplication and read-only queries\n";
