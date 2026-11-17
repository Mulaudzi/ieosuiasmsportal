<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../domain/CreditApprovalSecurity.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$checks=0;
function check(bool $pass,string $label):void{global $checks;if(!$pass)throw new RuntimeException($label);$checks++;}
function denied(callable $call,int $status):void{try{$call();throw new RuntimeException('Expected denial');}catch(CreditApprovalException $e){check($e->status===$status,'Expected HTTP '.$status.', got '.$e->status);}}
$owner=['id'=>1,'email'=>'lufuno@ieosuia.com','role'=>'admin','is_active'=>1,'email_verified_at'=>'2026-01-01'];
$admin=$owner;$admin['email']='admin@example.invalid';$admin['id']=2;
$password='fixture-only-master-password-123';$next='fixture-only-replacement-456';
denied(fn()=>CreditApprovalSecurity::verify($pdo,2,$password),503);
$pdo->exec(file_get_contents(__DIR__.'/fixtures/credit-approval-security.sql'));
check(CreditApprovalSecurity::status($pdo,$owner)['is_owner'],'Verified admin owner recognized');
foreach([['role'=>'user'],['is_active'=>0],['email_verified_at'=>null],['email'=>'lufuno@ieosuia.com.attacker.invalid']] as $bad)check(!CreditApprovalSecurity::isOwner(array_replace($owner,$bad)),'Owner claim cannot bypass identity checks');
denied(fn()=>CreditApprovalSecurity::configure($pdo,$admin,null,$password,$password),403);
denied(fn()=>CreditApprovalSecurity::configure($pdo,$owner,null,'short','short'),422);
denied(fn()=>CreditApprovalSecurity::configure($pdo,$owner,null,str_repeat('é',7),str_repeat('é',7)),422);
denied(fn()=>CreditApprovalSecurity::configure($pdo,$owner,null,str_repeat('x',73),str_repeat('x',73)),422);
denied(fn()=>CreditApprovalSecurity::configure($pdo,$owner,null,$password,'mismatch'),422);
denied(fn()=>CreditApprovalSecurity::verify($pdo,2,$password),503);
CreditApprovalSecurity::configure($pdo,$owner,null,$password,$password);
$hash=$pdo->query('SELECT password_hash FROM credit_approval_security')->fetchColumn();check($hash!==$password && password_verify($password,$hash),'Only a strong password hash is stored');
$status=CreditApprovalSecurity::status($pdo,$admin);check($status['configured']&&!$status['is_owner']&&!array_key_exists('password_hash',$status),'Status never exposes the password hash');
denied(fn()=>CreditApprovalSecurity::verify($pdo,2,''),422);
for($i=1;$i<=5;$i++)denied(fn()=>CreditApprovalSecurity::verify($pdo,2,'incorrect'),$i===5?429:422);
check(!$pdo->inTransaction(),'Password rejection never leaves an open transaction');
denied(fn()=>CreditApprovalSecurity::verify($pdo,2,$password),429);
check((int)$pdo->query('SELECT failures FROM credit_approval_attempts WHERE user_id=2')->fetchColumn()===5,'Failed attempts persist and cannot be skipped');
$pdo->exec('UPDATE credit_approval_attempts SET blocked_until=0,window_started=0');
$version=CreditApprovalSecurity::verify($pdo,2,$password);check($version===1,'Correct password returns current security version');
check((int)$pdo->query('SELECT COUNT(*) FROM credit_approval_attempts')->fetchColumn()===0,'Successful verification clears old failures');
$pdo->beginTransaction();CreditApprovalSecurity::assertVersion($pdo,$version);$pdo->commit();$checks++;
denied(fn()=>CreditApprovalSecurity::configure($pdo,$owner,'incorrect',$next,$next),422);
check(password_verify($password,$pdo->query('SELECT password_hash FROM credit_approval_security')->fetchColumn()),'Incorrect old password cannot rotate the master');
CreditApprovalSecurity::configure($pdo,$owner,$password,$next,$next);
$pdo->beginTransaction();denied(fn()=>CreditApprovalSecurity::assertVersion($pdo,$version),409);$pdo->rollBack();
denied(fn()=>CreditApprovalSecurity::verify($pdo,2,$password),422);
check(CreditApprovalSecurity::verify($pdo,2,$next)===2,'Rotation invalidates old passwords and grants');
denied(fn()=>CreditApprovalSecurity::configure($pdo,$owner,$next,$next,$next),422);
$events=json_encode($pdo->query('SELECT * FROM credit_approval_events')->fetchAll());
check(!str_contains($events,$password)&&!str_contains($events,$next)&&!str_contains($events,$hash),'Audit events never contain passwords or hashes');
check(str_contains($events,'password_rotated')&&str_contains($events,'password_rejected'),'Security actions have an audit trail');

function db():PDO{global $pdo;return $pdo;}
class Auth{static array $user=[];static function user(){return self::$user;}}
class Request{static array $data=[];static function input($key,$default=null){return self::$data[$key]??$default;}}
class SecurityResponse extends Exception{function __construct(public int $status,public array $data){parent::__construct('Fixture response');}}
class Response{static function success($data=null,int $status=200){throw new SecurityResponse($status,$data??[]);}static function error($message,int $status=400){throw new SecurityResponse($status,['message'=>$message]);}}
function response(callable $call,int $status):array{try{$call();throw new RuntimeException('Missing response');}catch(SecurityResponse $r){check($r->status===$status,'Controller authorization status');return $r->data;}}
require __DIR__.'/../controllers/CreditApprovalSecurityController.php';$controller=new CreditApprovalSecurityController();
Auth::$user=array_replace($admin,['role'=>'user']);response(fn()=>$controller->status(),403);
Auth::$user=$admin;$status=response(fn()=>$controller->status(),200);check(!$status['is_owner']&&!isset($status['password_hash']),'Ordinary admin cannot read secret or owner powers');
Request::$data=['current_password'=>$next,'new_password'=>'fixture-owned-by-attacker','confirm_password'=>'fixture-owned-by-attacker','is_owner'=>true,'email'=>'lufuno@ieosuia.com'];response(fn()=>$controller->update(),403);
Auth::$user=$owner;check(response(fn()=>$controller->status(),200)['is_owner'],'Only signed-in server owner receives management access');
echo 'PASS: '.$checks.' master-password security checks (isolated SQLite; no production access).'.PHP_EOL;
