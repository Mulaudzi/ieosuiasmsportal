<?php
// Local integration fixture only. Never serves under Apache/FPM or without the test environment.
if (PHP_SAPI!=='cli-server' || !getenv('CREDIT_PURCHASE_TEST_DB') || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)) {http_response_code(404);exit;}
function env(string $key,mixed $default=null): mixed {$v=getenv($key);return $v===false?$default:$v;}
putenv('SMS_PRICE_PER_SEGMENT=0.35');
class FixturePdo extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $query=str_replace(' FOR UPDATE','',$query);
        if (str_contains($query,'INFORMATION_SCHEMA.TABLES')) $query='SELECT 0';
        return parent::prepare($query,$options);
    }
}
$pdo=new FixturePdo('sqlite:'.getenv('CREDIT_PURCHASE_TEST_DB'));$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
if (!$pdo->query("SELECT name FROM sqlite_master WHERE name='users'")->fetchColumn()) $pdo->exec(file_get_contents(__DIR__.'/credit-purchases.sql'));
function db(): PDO {global $pdo;return $pdo;}
class Auth {
    static function id(){return ($_SERVER['HTTP_AUTHORIZATION']??'')==='Bearer fixture-other'?2:1;}
    static function user(){return ['id'=>self::id(),'name'=>'Fixture customer','email'=>'fixture@example.invalid','role'=>($_SERVER['HTTP_AUTHORIZATION']??'')==='Bearer fixture-admin'?'admin':'user'];}
}
require __DIR__.'/../../core/Response.php';require __DIR__.'/../../core/Request.php';require __DIR__.'/../../core/QueryBuilder.php';require __DIR__.'/../../controllers/CreditPurchaseController.php';
if (!CreditApprovalSecurity::available($pdo)) {
    $pdo->exec(file_get_contents(__DIR__.'/credit-approval-security.sql'));
    CreditApprovalSecurity::configure($pdo,['id'=>1,'email'=>'lufuno@ieosuia.com','role'=>'admin','is_active'=>1,'email_verified_at'=>'2026-01-01'],null,'fixture-master-approval-123','fixture-master-approval-123');
}
header('Content-Type: application/json');$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$controller=new CreditPurchaseController();
try {
    if ($path==='/wallet/purchase-options') $controller->options();
    if ($path==='/wallet/purchase-orders') $controller->orders();
    if ($path==='/wallet/buy') {if(Request::input('payment_method')!=='eft')Response::error('Online checkout is disabled in the local fixture.',422);$controller->buy();}
    if (preg_match('#^/wallet/purchase-orders/(\d+)/proof$#',$path,$m)) $controller->uploadProof(['id'=>$m[1]]);
    if (preg_match('#^/wallet/payment-proofs/(\d+)$#',$path,$m)) $controller->proof(['id'=>$m[1]]);
    if (preg_match('#^/admin/operations/purchase-orders/(\d+)/review$#',$path,$m)) $controller->review(['id'=>$m[1]]);
    if ($path==='/snapshot') Response::success(['balance'=>(float)($pdo->query('SELECT balance FROM wallets WHERE user_id=1')->fetchColumn()?:0),'ledger_entries'=>(int)$pdo->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn(),'proofs'=>(int)$pdo->query('SELECT COUNT(*) FROM credit_payment_proofs')->fetchColumn(),'emails'=>(int)$pdo->query('SELECT COUNT(*) FROM credit_purchase_mail_outbox')->fetchColumn()]);
    Response::error('Not found',404);
} catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();Response::error($e->getMessage(),500);}
