<?php
// Requires the deliberately isolated localhost MySQL fixture on port 33149.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function env(string $key,mixed $default=null):mixed{return $key==='SMS_PRICE_PER_SEGMENT'?'0.35':$default;}
$mode=$argv[1]??'parent';$worker=$mode!=='parent';$database=$worker?($argv[2]??''):'sms_credit_'.bin2hex(random_bytes(8)).'_test';
if(!preg_match('/^sms_credit_[a-f0-9]{16}_test$/D',$database))throw new RuntimeException('Unsafe test database');
$pdo=new PDO('mysql:host=127.0.0.1;port=33149;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db():PDO{global $pdo;return $pdo;}
if(!$worker)$pdo->exec("CREATE DATABASE `$database`");$pdo->exec("USE `$database`");
class Auth{static function id(){return 1;}static function user(){return ['id'=>1,'role'=>'admin','name'=>'Fixture','email'=>'fixture@example.invalid'];}}
class Request{static function input($key,$default=null){global $mode;return ['decision'=>'approve','note'=>'Isolated cleared-funds fixture','amount_received'=>'297.00','funds_verified'=>true,'bank_reference'=>'FIXTURE-CONCURRENT-001','master_password'=>$mode==='bad'?'incorrect-fixture':'fixture-master-approval-123'][$key]??$default;}}
class Response{static function success($data=null,int $status=200){echo json_encode(['status'=>$status,'data'=>$data]);exit;}static function error(string $message,int $status=400,array $errors=[]){echo json_encode(['status'=>$status,'message'=>$message]);exit;}}
require __DIR__.'/../core/QueryBuilder.php';require __DIR__.'/../controllers/CreditPurchaseController.php';
if($worker){try{(new CreditPurchaseController())->review(['id'=>1]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,$e->getMessage());exit(1);}exit;}
function runWorkers(string $mode,int $count,string $database):array{
    $jobs=[];for($i=0;$i<$count;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,$mode,$database],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Worker launch failed');fclose($pipes[0]);$jobs[]=[$process,$pipes];}
    $results=[];foreach($jobs as [$process,$pipes]){$body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($error);$results[]=json_decode($body,true,512,JSON_THROW_ON_ERROR);}return $results;
}
try{
    $sql=file_get_contents(__DIR__.'/fixtures/credit-purchases.sql');$sql=preg_replace('/CREATE TABLE credit_(?:purchase_orders|payment_proofs|purchase_mail_outbox)\b.*?(?:;|$)/s','',$sql);
    $sql=str_replace(['INTEGER PRIMARY KEY',' TEXT',' REAL'],['BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',' VARCHAR(255)',' DECIMAL(14,4)'],$sql);$sql=str_replace('CREATE TABLE payments(id BIGINT UNSIGNED','CREATE TABLE payments(id INT UNSIGNED',$sql);$pdo->exec($sql);
    foreach(['014_credit_purchase_orders','015_credit_approval_security'] as $name){$migration=require __DIR__.'/../migrations/'.$name.'.php';$migration($pdo);$migration($pdo);}
    CreditApprovalSecurity::configure($pdo,['id'=>1,'email'=>'lufuno@ieosuia.com','role'=>'admin','is_active'=>1,'email_verified_at'=>'2026-01-01'],null,'fixture-master-approval-123','fixture-master-approval-123');
    $pdo->exec("INSERT INTO wallets(id,user_id,balance,reserved,currency) VALUES(1,1,0,0,'ZAR');
    INSERT INTO wallet_transactions(id,wallet_id,amount,type,reference,status) VALUES(1,1,297,'credit','CONCURRENT-ORDER','pending');
    INSERT INTO payments(id,user_id,wallet_id,transaction_id,gateway,merchant_reference,amount,status,credits_added) VALUES(1,1,1,1,'eft','CONCURRENT-ORDER',297,'pending',0);
    INSERT INTO credit_purchase_orders(id,user_id,payment_id,reference,idempotency_key,pricing_version,credits,unit_cents,subtotal_cents,discount_cents,total_cents,wallet_amount,payment_method,expires_at,created_at,updated_at) VALUES(1,1,1,'CONCURRENT-ORDER','concurrent-fixture','test',1000,30,30000,300,29700,350,'eft',DATE_ADD(NOW(),INTERVAL 3 DAY),NOW(),NOW())");
    $bad=runWorkers('bad',6,$database);$statuses=array_column($bad,'status');sort($statuses);
    if($statuses!==[422,422,422,422,429,429])throw new RuntimeException('Concurrent attempts bypassed rate limit');
    if((float)$pdo->query('SELECT balance FROM wallets WHERE id=1')->fetchColumn()!==0.0)throw new RuntimeException('Incorrect passwords credited wallet');
    $pdo->exec('UPDATE credit_approval_attempts SET blocked_until=0,window_started=0');
    $valid=runWorkers('approve',2,$database);if(array_column($valid,'status')!==[200,200])throw new RuntimeException(json_encode($valid));
    if((float)$pdo->query('SELECT balance FROM wallets WHERE id=1')->fetchColumn()!==350.0 || (int)$pdo->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn()!==1 || (int)$pdo->query('SELECT credits_added FROM payments WHERE id=1')->fetchColumn()!==1000)throw new RuntimeException('Approval was credited more than once');
    if((int)$pdo->query("SELECT COUNT(*) FROM credit_approval_events WHERE event='payment_approved'")->fetchColumn()!==1)throw new RuntimeException('Approval audit must be exactly once');
    echo "PASS: concurrent wrong-password attempts are limited; two valid simultaneous approvals issue 1,000 credits and one audit/ledger entry exactly once (isolated MySQL).\n";
}finally{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec("DROP DATABASE `$database`");}
