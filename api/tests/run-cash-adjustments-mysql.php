<?php
// Deliberately isolated loopback database. Never reads .env or connects to production.
if(PHP_SAPI!=='cli')exit(1);
$mode=$argv[1]??'parent';
if($mode!=='parent') {
 $database=$argv[2]??'';
 if(!preg_match('/^sms_credit_[a-f0-9]{16}_test$/D',$database))throw new RuntimeException('Invalid synthetic database');
 function env(string $key,mixed $default=null):mixed{$v=getenv($key);return $v===false?$default:$v;}
 $pdo=new PDO('mysql:host=127.0.0.1;port=33165;dbname='.$database.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 function db():PDO{global $pdo;return $pdo;}
 class Auth {static function id(){return 3;}static function user(){return ['id'=>3,'role'=>'admin','is_active'=>1,'email_verified_at'=>'2026-01-01'];}}
 class Request {static function input($key,$default=null){global $argv;return ['decision'=>'approve','note'=>'Synthetic simultaneous bank verification','amount_received'=>$argv[5]??'25.00','funds_verified'=>true,'bank_reference'=>$argv[4]??'RACE-BANK','master_password'=>'fixture-master-approval-123','confirm_cash_difference'=>true][$key]??$default;}}
 class Response {static function success($data=null,int $status=200){echo json_encode(['status'=>$status,'data'=>$data]);exit;}static function error(string $message,int $status=400,array $errors=[]){echo json_encode(['status'=>$status,'message'=>$message]);exit;}}
 require __DIR__.'/../core/QueryBuilder.php';require __DIR__.'/../controllers/CreditPurchaseController.php';
 try {
  if($mode==='approve'){(new CreditPurchaseController())->review(['id'=>$argv[3]]);exit;}
  $result=CustomerCashAdjustment::execute($pdo,3,$argv[3],'fixture-master-approval-123',true);
  echo json_encode(['status'=>200,'id'=>$result['id']]);
 }catch(CashAdjustmentException|CreditApprovalException $e){echo json_encode(['status'=>$e->status]);}
 exit;
}
putenv('CREDIT_PURCHASE_MYSQL_TEST=1');putenv('CREDIT_PURCHASE_MYSQL_PORT=33165');
require __DIR__.'/run-cash-adjustments.php';
function race(array $commands):array{
 $jobs=[];foreach($commands as$args){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Fixture worker failed');fclose($pipes[0]);$jobs[]=[$process,$pipes];}
 $out=[];foreach($jobs as[$process,$pipes]){$body=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($err);$out[]=json_decode($body,true,512,JSON_THROW_ON_ERROR);}return $out;
}
// Two independent immutable reviews for the same bank transaction: only one may win.
$one=randQuote(randDraft('CONCURRENT-DEPOSIT','25.00'));$two=randQuote(randDraft('CONCURRENT-DEPOSIT','25.00'));
$before=CustomerCashBalance::snapshot($pdo,1)['balance_cents'];
$results=race([['commit',$fixtureName,$one['id']],['commit',$fixtureName,$two['id']]]);
$statuses=array_column($results,'status');sort($statuses);check($statuses===[200,409],'Concurrent duplicate bank transactions are blocked');
check(CustomerCashBalance::snapshot($pdo,1)['balance_cents']===$before+2500,'Concurrent bank credit occurs exactly once');
$winner=$results[0]['status']===200?$one:$two;
$results=race([['commit',$fixtureName,$winner['id']],['commit',$fixtureName,$winner['id']]]);
check(array_column($results,'status')===[200,200],'Simultaneous response-loss replays return same receipt');
check(CustomerCashBalance::snapshot($pdo,1)['balance_cents']===$before+2500,'Concurrent replay does not duplicate funds');
// Existing order approval and standalone deposit share the finance lock and bank uniqueness.
Auth::$id=1;Auth::$role='user';$o=cashBuy(1000,'concurrent-rand-order01');
$q=randQuote(randDraft('RACE-ORDER-DEPOSIT',number_format((int)$o['total_cents']/100,2,'.','')));
$results=race([['commit',$fixtureName,$q['id']],['approve',$fixtureName,(string)$o['id'],'RACE-ORDER-DEPOSIT',number_format((int)$o['total_cents']/100,2,'.','')]]);
$statuses=array_column($results,'status');sort($statuses);check($statuses===[200,409],'Deposit cannot credit cash and also approve a purchase concurrently');
$grantCount=(int)$pdo->query("SELECT COUNT(*) FROM customer_cash_adjustments WHERE bank_reference='RACE-ORDER-DEPOSIT'")->fetchColumn();
$purchasePaid=(int)$pdo->query('SELECT credits_added FROM payments WHERE id='.(int)$o['payment_id'])->fetchColumn()>0;
check(($grantCount===1&&!$purchasePaid)||($grantCount===0&&$purchasePaid),'Exactly one financial destination wins');
echo "PASS isolated InnoDB race checks: concurrent deposit, replay and existing order approval; no live accounts touched.\n";
