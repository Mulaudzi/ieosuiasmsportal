<?php
declare(strict_types=1);
// Pure read-model fixtures. No provider, messages, wallet or ledger is mutated.
require_once __DIR__.'/../services/PortalReadModel.php';

final class ReconciledBillingPDO extends PDO
{
    public array $statements=[];
    public function prepare(string $query,array $options=[]):PDOStatement|false
    { $this->statements[]=$query;return parent::prepare($query,$options); }
}
$checks=0;
function billingCheck(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$p=new ReconciledBillingPDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$p->sqliteCreateFunction('CONCAT',static fn(...$values)=>implode('',$values));$p->sqliteCreateFunction('FLOOR',static fn($value)=>floor($value));
$p->exec("CREATE TABLE contacts(id INTEGER,user_id INTEGER);
    CREATE TABLE payments(id INTEGER,user_id INTEGER,merchant_reference TEXT,amount NUMERIC,credits_added INTEGER,status TEXT,created_at TEXT);
    CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER,balance NUMERIC,reserved NUMERIC);
    CREATE TABLE wallet_transactions(wallet_id INTEGER,reference TEXT,description TEXT);
    CREATE TABLE wallet_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,wallet_id INTEGER,campaign_id INTEGER,payment_id INTEGER,operation TEXT,direction TEXT,amount NUMERIC,idempotency_key TEXT,created_at TEXT);
    CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT);
    CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,user_id INTEGER,campaign_id INTEGER,state TEXT,sent_at TEXT,delivered_at TEXT,last_error TEXT,actual_charge NUMERIC,segment_count INTEGER);
    INSERT INTO wallets VALUES(7,7,100,5),(9,9,500,0);
    INSERT INTO payments VALUES(1,7,'PURCHASE',10,28,'completed','2026-10-23 12:00:00');");
function billingMessage(PDO $p,int $id,int $campaign,array $change=[]):void
{
    $name='Campaign '.$campaign;$q=$p->prepare('INSERT OR IGNORE INTO sms_campaigns VALUES(?,?,?)');$q->execute([$campaign,$change['user_id']??7,$name]);
    $row=array_replace(['id'=>$id,'user_id'=>7,'campaign_id'=>$campaign,'state'=>'delivered','sent_at'=>null,'delivered_at'=>null,'last_error'=>null,'actual_charge'=>'.35','segment_count'=>1],$change);
    $q=$p->prepare('INSERT INTO sms_messages('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));
}
function billingSettlement(PDO $p,int $id,int $campaign,array $change=[]):void
{
    $row=array_replace(['id'=>$id,'user_id'=>7,'wallet_id'=>7,'campaign_id'=>$campaign,'payment_id'=>null,'operation'=>'sms_settlement','direction'=>'debit','amount'=>'.35','idempotency_key'=>'sms-message:'.$id.':settle','created_at'=>'2026-10-24 03:00:00'],$change);
    $q=$p->prepare('INSERT INTO wallet_ledger('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));
}
billingMessage($p,101,1);billingSettlement($p,101,1);
billingMessage($p,102,1,['state'=>'failed','last_error'=>'LogicSMS delivery status: INVALID']);billingSettlement($p,102,1);
billingMessage($p,201,2,['state'=>'sent','sent_at'=>'2026-10-23 13:00:00']);billingSettlement($p,201,2);
billingMessage($p,301,3,['state'=>'retry','actual_charge'=>0]);billingSettlement($p,301,3,['operation'=>'reservation','direction'=>'reserve']);
billingMessage($p,401,4);billingSettlement($p,401,4,['user_id'=>9,'wallet_id'=>9]);
billingMessage($p,501,5);billingSettlement($p,501,5,['amount'=>'.70']);
billingMessage($p,601,6);billingSettlement($p,601,6,['wallet_id'=>9]);
billingMessage($p,701,7);billingSettlement($p,701,7);billingSettlement($p,702,7,['idempotency_key'=>'sms-message:701:settle']);
billingMessage($p,801,8);billingSettlement($p,801,8);
billingMessage($p,802,8,['state'=>'sent','sent_at'=>'2026-10-24 02:00:00']);billingSettlement($p,802,8);
billingMessage($p,901,9,['state'=>'skipped','sent_at'=>'2026-10-24 02:00:00']);billingSettlement($p,901,9);
billingMessage($p,1001,10);billingSettlement($p,1001,10,['operation'=>'manual_adjustment']);
billingMessage($p,1101,11);billingSettlement($p,1101,11,['payment_id'=>1]);
billingMessage($p,1201,12);billingSettlement($p,1201,12,['campaign_id'=>99]);
billingMessage($p,1301,13,['actual_charge'=>0]);billingSettlement($p,1301,13);
billingMessage($p,2001,20,['user_id'=>9]);billingSettlement($p,2001,20,['user_id'=>9,'wallet_id'=>9]);
$p->exec("INSERT INTO wallet_ledger VALUES(3001,7,7,NULL,NULL,'manual_adjustment','credit',.70,'owner-adjustment','2026-10-24 02:00:00');
    INSERT INTO wallet_transactions VALUES(7,'owner-adjustment','Original adjustment description');
    INSERT INTO sms_messages VALUES(8001,8,0,'failed',NULL,NULL,'LogicSMS delivery status: BLOCKED',.35,1);
    INSERT INTO sms_messages VALUES(9001,99,0,'skipped','2026-10-24 03:00:00',NULL,NULL,.35,1);
    INSERT INTO sms_messages VALUES(10001,100,0,'dlr_unavailable',NULL,NULL,NULL,.35,1);
    INSERT INTO sms_messages VALUES(11001,110,0,'retry',NULL,NULL,'Submission started',0,1);");
$tables=['payments','wallets','wallet_ledger','wallet_transactions','sms_campaigns','sms_messages'];$before=[];
foreach($tables as $table)$before[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();
$p->statements=[];
$filters=['period'=>'custom','start'=>'2026-10-24','end'=>'2026-10-24','per_page'=>50];
$activity=PortalReadModel::activity($p,7,$filters,.35);$rows=array_column($activity['transactions'],null,'id');
billingCheck($activity['total']===3&&isset($rows['campaign-1'],$rows['campaign-8'],$rows['adjustment-3001']),'Reconciled charges and original adjustments appear once in the accounting-date window');
billingCheck($rows['campaign-1']['amount']===.70&&$rows['campaign-1']['credits']===2,'Delivered and accepted unsuccessful outcomes are both billed segments, not only deliveries');
billingCheck($rows['campaign-1']['created_at']==='2026-10-24 03:00:00'&&$rows['campaign-1']['date_semantics']==='includes_charge_recorded_at','Settlement supplies an explicitly accounting-only observation date');
billingCheck(str_contains($rows['campaign-1']['detail'],'charge-recorded dates')&&str_contains($rows['campaign-1']['detail'],'send times unverified'),'Existing UI detail explicitly labels fallback dates without a frontend change');
billingCheck($rows['campaign-8']['amount']===.70&&$rows['campaign-8']['credits']===2&&$rows['campaign-8']['date_semantics']==='includes_charge_recorded_at','Mixed real-send and settlement dates aggregate each message exactly once');
billingCheck($rows['adjustment-3001']['detail']==='Original adjustment description'&&$rows['adjustment-3001']['credits']===2&&$rows['adjustment-3001']['date_semantics']==='adjustment_recorded_at','Manual adjustment description, amount and type remain unchanged');
billingCheck($activity['total_paid']===10.0,'Lifetime actual payment total does not become SMS usage or settlement value');
foreach([3,4,5,6,7,9,10,11,12,13,20] as $excluded)billingCheck(!isset($rows['campaign-'.$excluded]),'Unsent, foreign, ambiguous, wrong-wallet, wrong-amount, unrelated or zero-charge evidence cannot invent a billing date');
$prior=PortalReadModel::activity($p,7,array_replace($filters,['start'=>'2026-10-23','end'=>'2026-10-23']),.35);$priorRows=array_column($prior['transactions'],null,'id');
billingCheck($prior['total']===2&&isset($priorRows['campaign-2'],$priorRows['payment-1']),'Actual sent_at retains its original date instead of moving to later settlement time');
billingCheck($priorRows['campaign-2']['date_semantics']==='sent_at'&&$priorRows['campaign-2']['detail']==='Campaign 2'&&$priorRows['campaign-2']['amount']===.35,'Normal SMS usage wording and amount are unchanged');
billingCheck($priorRows['payment-1']['date_semantics']==='purchase_created_at'&&$priorRows['payment-1']['amount']===10.0,'Purchase date and monetary value remain separate');
$both=PortalReadModel::activity($p,7,array_replace($filters,['start'=>'2026-10-23']),.35);
billingCheck($both['total']===5,'Two-day history counts purchases, usage and adjustments without ledger double counting');
billingCheck(PortalReadModel::activity($p,9,$filters,.35)['total']===1,'Foreign account sees only its own canonical consumption');
billingCheck(PortalReadModel::activity($p,7,$filters+['status'=>'paid'],.35)['total']===0,'Payment-only filtering does not turn usage into paid purchases');
billingCheck(PortalReadModel::activity($p,7,$filters+['search'=>'1'],.35)['total']===1,'Existing reference search works with reconciled activity');
$overview=PortalReadModel::overview($p,8);
billingCheck($overview['sent_campaign']&&$overview['checked_delivery'],'Accepted network failure without invented sent_at satisfies canonical onboarding evidence');
$skipped=PortalReadModel::overview($p,99);
billingCheck(!$skipped['sent_campaign']&&!$skipped['checked_delivery'],'Skipped rows never count as sent even with an inconsistent legacy timestamp');
$unavailable=PortalReadModel::overview($p,100);
billingCheck($unavailable['sent_campaign']&&!$unavailable['checked_delivery'],'Accepted unavailable confirmation is sent, but is not a confirmed network outcome');
$held=PortalReadModel::overview($p,110);
billingCheck(!$held['sent_campaign']&&!$held['checked_delivery'],'A held uncertain submission does not mark onboarding sent or delivery-confirmed');
foreach($tables as $table)billingCheck($p->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll()===$before[$table],'Read model must not mutate '.$table);
foreach($p->statements as $sql)billingCheck(str_starts_with($sql,'SELECT'),'Every presentation query remains read-only');
echo "PASS {$checks} reconciled-billing read-model checks; isolated SQLite, no network or mutations\n";
