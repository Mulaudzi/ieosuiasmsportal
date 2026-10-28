<?php
// Isolated SQLite integration checks. No production configuration or SMTP credentials are loaded.
function env(string $key, mixed $default=null): mixed { $v=getenv($key);return $v===false?$default:$v; }
putenv('SMS_PRICE_PER_SEGMENT=0.35');putenv('SMTP_HOST=127.0.0.1');putenv('SMTP_PORT=1');
final class TestDatabase extends PDO {
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
            $query=str_replace(' FOR UPDATE','',$query);
            if (str_contains($query,'INFORMATION_SCHEMA.TABLES')) $query='SELECT 0';
        }
        return parent::prepare($query,$options);
    }
}
$mysql=getenv('CREDIT_PURCHASE_MYSQL_TEST')==='1';$fixtureName=null;
if ($mysql) {
    if (PHP_SAPI!=='cli') throw new RuntimeException('CLI only');
    $port=(int)(getenv('CREDIT_PURCHASE_MYSQL_PORT')?:33149);
    if ($port<1024 || $port===3306) throw new RuntimeException('Use an isolated local test port');
    $pdo=new TestDatabase("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",'root','');
    $fixtureName='sms_credit_'.bin2hex(random_bytes(8)).'_test';
    $pdo->exec("CREATE DATABASE `$fixtureName`");$pdo->exec("USE `$fixtureName`");
    register_shutdown_function(function()use($pdo,$fixtureName){if($pdo->inTransaction())$pdo->rollBack();if(preg_match('/^sms_credit_[a-f0-9]{16}_test$/D',$fixtureName))$pdo->exec("DROP DATABASE `$fixtureName`");});
} else {
    $pdo=new TestDatabase('sqlite::memory:');$pdo->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
}
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function db(): PDO {global $pdo;return $pdo;}
final class Result extends Exception { public function __construct(public array $data,public int $status=200){parent::__construct(json_encode($data));} }
class Response {static function success($data=null,int $status=200):void {throw new Result($data??[],$status);} static function error(string $message,int $status=400,array $errors=[]):void {throw new Result(['message'=>$message],$status);} }
class Request {static array $data=[];static array $query=[];static function input($key,$default=null){return self::$data[$key]??$default;}static function query($key,$default=null){return self::$query[$key]??$default;} }
class Auth {static int $id=1;static string $role='user';static function id(){return self::$id;}static function user(){return ['id'=>self::$id,'email'=>'customer@example.invalid','name'=>'Customer','role'=>self::$role];} }
require __DIR__.'/../core/QueryBuilder.php';
require __DIR__.'/../controllers/CreditPurchaseController.php';
require __DIR__.'/../services/PdfReceiptService.php';
$schema=file_get_contents(__DIR__.'/fixtures/credit-purchases.sql');
if ($mysql) {
    $schema=preg_replace('/CREATE TABLE credit_(?:purchase_orders|payment_proofs|purchase_mail_outbox)\b.*?(?:;|$)/s','',$schema);
    $schema=str_replace(['INTEGER PRIMARY KEY',' TEXT',' REAL'],['BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',' VARCHAR(255)',' DECIMAL(14,4)'],$schema);
    $schema=str_replace('CREATE TABLE payments(id BIGINT UNSIGNED','CREATE TABLE payments(id INT UNSIGNED',$schema);
    $pdo->exec($schema);
    $migration=require __DIR__.'/../migrations/014_credit_purchase_orders.php';$migration($pdo);$migration($pdo);
} else $pdo->exec($schema);
if ($mysql) { $securityMigration=require __DIR__.'/../migrations/015_credit_approval_security.php';$securityMigration($pdo);$securityMigration($pdo); }
else $pdo->exec(file_get_contents(__DIR__.'/fixtures/credit-approval-security.sql'));
CreditApprovalSecurity::configure($pdo,['id'=>1,'email'=>'lufuno@ieosuia.com','role'=>'admin','is_active'=>1,'email_verified_at'=>'2026-01-01'],null,'fixture-master-approval-123','fixture-master-approval-123');
$checks=0;
function check(bool $pass,string $label):void {global $checks;if(!$pass)throw new RuntimeException($label);$checks++;}
function callApi(callable $f,int $status=200):array {try{$f();throw new RuntimeException('No response');}catch(Result $r){check($r->status===$status,'HTTP '.$status.' expected, got '.$r->status.' '.json_encode($r->data));return $r->data;}}
$controller=new CreditPurchaseController();
foreach ([[30,35],[100,35],[101,33],[500,33],[501,31],[750,31],[1000,31],[1001,29],[2500,29],[2501,28],[5000,28],[5001,27],[10000,27],[10001,26],[25000,26],[25001,25],[50000,25],[50001,24],[100000,24],[100001,23],[250000,23],[250001,22],[500000,22]] as [$n,$rate]) {
    $bank=CreditPurchasePricing::quote($n,'eft');
    check($bank['unit_cents']===$rate && $bank['subtotal_cents']===$n*$rate,'Correct rate at '.$n);
    check($bank['subtotal_cents']-$bank['total_cents']===$bank['discount_cents'],'Saving at '.$n);
    if($n<=50000)check(CreditPurchasePricing::quote($n,'payos')['total_cents']===$n*$rate,'Online rate at '.$n);
    else {try{CreditPurchasePricing::quote($n,'payos');throw new RuntimeException('Online limit bypassed');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'direct bank-transfer EFT'),'Direct EFT required at '.$n);}}
}
foreach ([0,29,30.5,500001,1000000,1000001,'1e3',true,null,[],INF] as $bad) {
    try{CreditPurchasePricing::quote($bad,'payos');throw new RuntimeException('Invalid quantity accepted');}catch(InvalidArgumentException){$checks++;}
}
check(CreditPurchasePricing::quote(30,'eft')['total_cents']===950,'Minimum transfer R9.50');
check(CreditPurchasePricing::quote(1000,'eft')['total_cents']===30690,'1000-credit transfer R306.90');
foreach ([[50001,'payos','direct bank-transfer EFT'],[500001,'eft','sms@ieosuia.com'],[500001,'payos','sms@ieosuia.com']] as [$credits,$method,$message]) {
    Request::$data=['credits'=>$credits,'payment_method'=>$method];
    check(str_contains(callApi(fn()=>$controller->buy(),422)['message'],$message),'API enforces purchase policy without creating a payment');
}
check((int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn()===0,'Policy rejections create no payments');
Request::$data=CreditPurchasePricing::quote(1000,'eft')+['quoted_total_cents'=>30690,'idempotency_key'=>'old-pricing-000001'];
Request::$data['pricing_version']='2026-10-volume-1';callApi(fn()=>$controller->buy(),409);
check((int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn()===0,'An old pricing version cannot start a new payment');
check(CreditPurchaseController::bankDetails()['account_number']==='63184542520','Uses actual IEOSUIA FNB account, not legacy placeholders');
putenv('SMS_BANK_TRANSFER_ENABLED=false');check(CreditPurchaseController::bankDetails()===null,'Transfer kill switch');putenv('SMS_BANK_TRANSFER_ENABLED=true');

function purchase(int $credits,string $key):array {
    $q=CreditPurchasePricing::quote($credits,'eft');Request::$data=$q+['quoted_total_cents'=>$q['total_cents'],'idempotency_key'=>$key];
    return callApi(fn()=>(new CreditPurchaseController())->buy())['order'];
}
$order=purchase(1000,'test-request-000001');
check((int)$order['total_cents']===30690 && (int)$order['credits']===1000,'Frozen direct transfer quote');
check((float)$pdo->query('SELECT balance FROM wallets WHERE user_id=1')->fetchColumn()===0.0,'Creating order adds no funds');
$repeat=purchase(1000,'test-request-000001');check($repeat['id']===$order['id'],'Repeated create returns same order');
check((int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn()===1,'Only one payment record');
check((int)$pdo->query('SELECT COUNT(*) FROM credit_purchase_mail_outbox')->fetchColumn()===2,'Instructions queued once for customer and SMS inbox');
check((int)$pdo->query("SELECT COUNT(*) FROM credit_purchase_mail_outbox WHERE html LIKE '%We do not charge VAT.%'")->fetchColumn()===2,'Both instruction emails explain VAT is not charged');
check((int)$pdo->query("SELECT COUNT(*) FROM credit_purchase_mail_outbox WHERE html LIKE '%VAT-registered%'")->fetchColumn()===0,'Instruction emails do not disclose VAT registration status');
check($pdo->query("SELECT recipient FROM credit_purchase_mail_outbox WHERE event_key LIKE '%:staff'")->fetchColumn()==='sms@ieosuia.com','Proof and order inbox uses SMS email');
Request::$data['credits']=500;callApi(fn()=>$controller->buy(),409);
Request::$data['quoted_total_cents']=1;callApi(fn()=>$controller->buy(),409);
Request::$data=['decision'=>'approve','note'=>'Checked bank','amount_received'=>'306.90','bank_reference'=>'FNB-TXN-001','funds_verified'=>true,'master_password'=>'fixture-master-approval-123'];
callApi(fn()=>$controller->review(['id'=>$order['id']]),403);
Auth::$role='admin';
Request::$data['funds_verified']=false;callApi(fn()=>$controller->review(['id'=>$order['id']]),422);Request::$data['funds_verified']=true;
unset(Request::$data['master_password']);callApi(fn()=>$controller->review(['id'=>$order['id']]),422);
Request::$data['master_password']='wrong-fixture-password';callApi(fn()=>$controller->review(['id'=>$order['id']]),422);
check((float)$pdo->query('SELECT balance FROM wallets WHERE user_id=1')->fetchColumn()===0.0,'Missing or incorrect master password cannot credit wallet');
Request::$data['master_password']='fixture-master-approval-123';
Request::$data['amount_received']='296.00';callApi(fn()=>$controller->review(['id'=>$order['id']]),409);
check((float)$pdo->query('SELECT balance FROM wallets WHERE user_id=1')->fetchColumn()===0.0,'Wrong amount cannot credit wallet');
Request::$data['amount_received']='306.90';callApi(fn()=>$controller->review(['id'=>$order['id']]));
check((float)$pdo->query('SELECT balance FROM wallets WHERE user_id=1')->fetchColumn()===350.0,'R306.90 buys exactly 1000 canonical credits');
check((int)$pdo->query('SELECT credits_added FROM payments WHERE id=1')->fetchColumn()===1000,'Payment receipt credit count matches');
$stamp=$pdo->query('SELECT processed_at FROM payments WHERE id=1')->fetchColumn();
callApi(fn()=>$controller->review(['id'=>$order['id']]));
check((int)$pdo->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn()===1,'Duplicate approval creates no ledger entry');
check($pdo->query('SELECT processed_at FROM payments WHERE id=1')->fetchColumn()===$stamp,'Duplicate approval preserves completion time');
$second=purchase(1000,'test-request-000002');
Request::$data=['decision'=>'approve','note'=>'Checked bank','amount_received'=>'306.90','bank_reference'=>' fnb-txn-001 ','funds_verified'=>true,'master_password'=>'fixture-master-approval-123'];
callApi(fn()=>$controller->review(['id'=>$second['id']]),409);
check((float)$pdo->query('SELECT balance FROM wallets WHERE user_id=1')->fetchColumn()===350.0,'Reused bank transaction rolls back wallet credit');
Request::$data=['decision'=>'reject','note'=>'Funds have not cleared'];callApi(fn()=>$controller->review(['id'=>$second['id']]));
check((int)$pdo->query('SELECT credits_added FROM payments WHERE id=2')->fetchColumn()===0,'Rejected order has no credits');
Request::$query=['review'=>'1','status'=>'completed','search'=>'Customer'];check(count(callApi(fn()=>$controller->orders())['orders'])===1,'Review history finds credited transfer');
Request::$query=['review'=>'1','status'=>'rejected'];check(count(callApi(fn()=>$controller->orders())['orders'])===1,'Review history finds rejected transfer');
Request::$query=['review'=>'1','status'=>'all'];check(count(callApi(fn()=>$controller->orders())['orders'])===2,'All transfers include full review history');
Auth::$id=2;Auth::$role='user';Request::$query=[];
check(callApi(fn()=>$controller->orders())['orders']===[],'Other customer cannot list purchase');
Request::$query=['review'=>'1'];callApi(fn()=>$controller->orders(),403);

// Old payments use the original amount conversion; new gateway payments use their stored credit quantity.
$pdo->exec("INSERT INTO wallets VALUES(2,2,0,0,'ZAR',NOW(),NOW()); INSERT INTO payments(id,user_id,wallet_id,transaction_id,gateway,merchant_reference,amount,status,credits_added) VALUES(3,2,2,3,'payos','LEGACY',10,'pending',0)");
$payment=table('payments')->where('id',3)->first();$pdo->beginTransaction();$legacyCredits=CreditPurchaseSettlement::credit($pdo,$payment,10);$pdo->commit();
check($legacyCredits===28,'Legacy R10 callback remains 28 credits');
$pdo->exec("INSERT INTO payments(id,user_id,wallet_id,transaction_id,gateway,merchant_reference,amount,status,credits_added) VALUES(4,2,2,4,'payos','NEW-GATEWAY',300,'pending',0);
    INSERT INTO credit_purchase_orders(user_id,payment_id,reference,idempotency_key,pricing_version,credits,unit_cents,subtotal_cents,total_cents,wallet_amount,payment_method,status,expires_at,created_at,updated_at) VALUES(2,4,'NEW-GATEWAY','fixture-new-gateway','test',1000,30,30000,30000,350,'payos','awaiting_payment','2030-01-01 00:00:00',NOW(),NOW())");
$payment=table('payments')->where('id',4)->first();$pdo->beginTransaction();$newCredits=CreditPurchaseSettlement::credit($pdo,$payment,300);CreditPurchaseSettlement::credit($pdo,$payment,300);$pdo->commit();
check($newCredits===1000 && (float)$pdo->query('SELECT balance FROM wallets WHERE user_id=2')->fetchColumn()===360.0,'Gateway settlement uses quantity once, preserves legacy remainder');
check((int)CreditPurchaseSettlement::order($pdo,4)['unit_cents']===30 && (int)CreditPurchaseSettlement::order($pdo,4)['total_cents']===30000,'Existing orders keep the original R0.30 quote after the price change');
$payment=table('payments')->where('id',1)->first();$payment+=['gateway_reference'=>null,'gateway_status'=>null];
$receipt=PdfReceiptService::generateReceipt($payment,['name'=>'Customer','email'=>'customer@example.invalid']);
check(!str_contains($receipt,'VAT (15%)') && str_contains($receipt,'Not charged') && !preg_match('/VAT[ -]?registered/i',$receipt),'Receipt charges no VAT without disclosing registration status');
check(str_contains($receipt,'1,000 credits') && str_contains($receipt,'306.90'),'Receipt shows actual cash and actual credits');
$payment['purchase_order']=CreditPurchaseSettlement::order($pdo,1);
$receipt=PdfReceiptService::generateReceipt($payment,['name'=>'Customer','email'=>'customer@example.invalid']);
check(str_contains($receipt,'Bank transfer saving') && str_contains($receipt,'310.00') && str_contains($receipt,'-R 3.10'),'Receipt itemizes frozen subtotal, bank saving and paid total');
check(str_contains($receipt,'R0.31 per credit') && !str_contains($receipt,'31c per credit'),'Receipt displays unit price in rand');

require __DIR__.'/../controllers/AdminOperationsController.php';
$operations=new AdminOperationsController();Request::$query=[];
callApi(fn()=>$operations->payments(),403);
Auth::$role='admin';Request::$query=['per_page'=>'1','page'=>'1'];
$history=callApi(fn()=>$operations->payments());check(count($history['payments'])===1&&$history['pagination']['total']===4,'Admin payment history is paginated across gateways');
Request::$query=['gateway'=>'eft','status'=>'completed','search'=>'Customer'];
check(count(callApi(fn()=>$operations->payments())['payments'])===1,'Admin payment filters match actual records');
Request::$query=['status'=>'invented'];callApi(fn()=>$operations->payments(),422);
// New bulk orders use direct EFT; all historical records above keep their stored quotes.
Auth::$role='user';Auth::$id=1;
$bulk=purchase(500000,'bulk-direct-eft-000001');
check((int)$bulk['credits']===500000 && (int)$bulk['unit_cents']===22 && (int)$bulk['total_cents']===10890000 && $bulk['payment_method']==='eft','Maximum self-service order freezes the R0.22 rate and existing transfer saving');
putenv('SMS_BANK_TRANSFER_ENABLED=false');
$bulkQuote=CreditPurchasePricing::quote(50001,'eft');
Request::$data=$bulkQuote+['quoted_total_cents'=>$bulkQuote['total_cents'],'idempotency_key'=>'disabled-direct-eft-000001'];
check(str_contains(callApi(fn()=>$controller->buy(),503)['message'],'sms@ieosuia.com'),'Unavailable bulk EFT routes to sales, never online checkout');
putenv('SMS_BANK_TRANSFER_ENABLED=true');

// Public banking needs neither login nor a purchase; only safe configuration fields.
$public=callApi(fn()=>$controller->publicBanking());
check($public['available']&&$public['bank_details']['account_number']==='63184542520'&&$public['proof_email']==='sms@ieosuia.com','Guest banking uses the same verified configuration as checkout');
check(!isset($public['orders'],$public['user_id'],$public['payments']),'Public banking exposes no account or order information');
putenv('SMS_BANK_TRANSFER_ENABLED=false');check(callApi(fn()=>$controller->publicBanking())['bank_details']===null,'Public banking respects the EFT kill switch');putenv('SMS_BANK_TRANSFER_ENABLED=true');

$void=purchase(1000,'void-unpaid-eft-000001');
$financial=[$pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM wallet_ledger ORDER BY id')->fetchAll()];
Request::$data=['decision'=>'void','note'=>'No transfer received','funds_not_received'=>true];
callApi(fn()=>$controller->review(['id'=>$void['id']]),403);
Auth::$role='admin';
Request::$data['funds_not_received']=false;callApi(fn()=>$controller->review(['id'=>$void['id']]),422);
Request::$data['funds_not_received']='true';callApi(fn()=>$controller->review(['id'=>$void['id']]),422);
Request::$data=['decision'=>'void','note'=>'','funds_not_received'=>true];callApi(fn()=>$controller->review(['id'=>$void['id']]),422);
Request::$data['note']='Checked FNB; customer never transferred money';
callApi(fn()=>$controller->review(['id'=>$void['id']]));
$voidPayment=table('payments')->where('id',$void['payment_id'])->first();
$voidStored=CreditPurchaseSettlement::order($pdo,(int)$void['payment_id']);
check($voidStored['status']==='voided'&&$voidPayment['status']==='cancelled'&&(int)$voidPayment['credits_added']===0,'Voiding closes the unpaid order and pending payment, never credits it');
check($voidStored['review_note']===Request::$data['note']&&(int)$voidStored['reviewed_by']===1&&!empty($voidStored['reviewed_at']),'Voiding preserves reviewer, reason and timestamp');
check($financial===[$pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM wallet_ledger ORDER BY id')->fetchAll()],'Void does not add, remove or reserve wallet credits');
$audit=(int)$pdo->query("SELECT COUNT(*) FROM credit_approval_events WHERE event='payment_voided'")->fetchColumn();
$mail=(int)$pdo->query("SELECT COUNT(*) FROM credit_purchase_mail_outbox WHERE event_key='order:".$void['id'].":voided'")->fetchColumn();
check($audit===1&&$mail===1,'Void audit event and customer notice queued once');
callApi(fn()=>$controller->review(['id'=>$void['id']]));
check((int)$pdo->query("SELECT COUNT(*) FROM credit_approval_events WHERE event='payment_voided'")->fetchColumn()===$audit,'Repeated void is idempotent');
check((int)$pdo->query("SELECT COUNT(*) FROM credit_purchase_mail_outbox WHERE event_key='order:".$void['id'].":voided'")->fetchColumn()===$mail,'Repeated void does not queue duplicate customer messages');
Request::$query=['review'=>'1','status'=>'voided'];check(count(callApi(fn()=>$controller->orders())['orders'])===1,'Voided orders remain available in finance history');
Request::$query=['review'=>'1','status'=>'pending'];check(!in_array($void['id'],array_column(callApi(fn()=>$controller->orders())['orders'],'id')),'Voided request leaves the awaiting-review queue');
Auth::$role='user';Request::$query=[];$customerOrders=callApi(fn()=>$controller->orders())['orders'];
check(in_array('voided',array_column($customerOrders,'status')),'Customer still sees the voided request and reference');
Auth::$role='admin';
Request::$data=['decision'=>'approve','note'=>'Late approval must not revive request','amount_received'=>'306.90','bank_reference'=>'VOID-LATE-001','funds_verified'=>true,'master_password'=>'fixture-master-approval-123'];
callApi(fn()=>$controller->review(['id'=>$void['id']]),409);
$pdo->beginTransaction();try{CreditPurchaseSettlement::credit($pdo,$voidPayment,306.90);throw new RuntimeException('Voided purchase settled');}catch(DomainException){$checks++;}finally{$pdo->rollBack();}
Request::$data=['decision'=>'void','note'=>'Should not touch credited order','funds_not_received'=>true];
callApi(fn()=>$controller->review(['id'=>$order['id']]),409);
check($financial===[$pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM wallet_ledger ORDER BY id')->fetchAll()],'Neither late approval nor void of credited order changes balances');

$proofOrder=purchase(1000,'void-with-proof-000001');
$pdo->prepare("UPDATE credit_purchase_orders SET status='awaiting_verification' WHERE id=?")->execute([$proofOrder['id']]);
$pdo->prepare('INSERT INTO credit_payment_proofs(order_id,mime_type,byte_size,sha256,document,created_at) VALUES(?,?,?,?,?,NOW())')->execute([$proofOrder['id'],'application/pdf',7,'fixture-proof','fixture']);
Request::$data=['decision'=>'void','note'=>'Proof did not match any cleared funds','funds_not_received'=>true];
callApi(fn()=>$controller->review(['id'=>$proofOrder['id']]));
check((int)$pdo->query('SELECT COUNT(*) FROM credit_payment_proofs WHERE order_id='.(int)$proofOrder['id'])->fetchColumn()===1,'Voiding preserves uploaded proof for reconciliation');

$inconsistent=purchase(1000,'void-inconsistent-000001');
$pdo->prepare("UPDATE payments SET status='completed' WHERE id=?")->execute([$inconsistent['payment_id']]);
Request::$data=['decision'=>'void','note'=>'Inconsistent records require reconciliation','funds_not_received'=>true];
callApi(fn()=>$controller->review(['id'=>$inconsistent['id']]),409);
check(CreditPurchaseSettlement::order($pdo,(int)$inconsistent['payment_id'])['status']==='awaiting_payment','Inconsistent payment evidence cannot be voided');
echo 'PASS: '.$checks.' credit pricing and purchase integration checks ('.($mysql?'isolated MySQL 8, actual migration applied twice':'SQLite').'; bank/SMTP/gateway not contacted).'.PHP_EOL;
