<?php
require_once __DIR__.'/CustomerCashBalance.php';
require_once __DIR__.'/CreditApprovalSecurity.php';
final class CashAdjustmentException extends RuntimeException {public function __construct(string $message,public readonly int $status=422){parent::__construct($message);}}

/** Immutable, independently reviewed rand credits. Never grants SMS or records a fictitious purchase. */
final class CustomerCashAdjustment
{
 private static function query(PDO $p,string $sql,array $args=[]):PDOStatement{$q=$p->prepare($sql);$q->execute($args);return $q;}
 private static function lock(PDO $p):string{return $p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
 public static function tableExists(PDO $p,string $table):bool{$q=self::query($p,$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?":"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?",[$table]);return (int)$q->fetchColumn()===1;}
 public static function available(PDO $p):bool
 {
  if(!CustomerCashBalance::available($p)||!CreditApprovalSecurity::available($p)||!self::tableExists($p,'customer_cash_adjustment_quotes')||!self::tableExists($p,'customer_cash_adjustments'))return false;
  if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
   $tables=['users','payments','credit_purchase_orders','customer_cash_ledger','credit_cash_allocations','credit_approval_security','credit_approval_attempts','credit_approval_events','customer_cash_adjustment_quotes','customer_cash_adjustments'];
   $count=self::query($p,'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND engine=\'InnoDB\' AND table_name IN ('.implode(',',array_fill(0,count($tables),'?')).')',$tables)->fetchColumn();
   if((int)$count!==count($tables))return false;
  }
  return true;
 }
 private static function ready(PDO $p):void{if(env('CASH_BALANCE_ADJUSTMENTS_ENABLED','false')!=='true'||!self::available($p))throw new CashAdjustmentException('Standalone rand credits need the scoped finance setup before activation. No money or SMS credits have changed.',503);}
 public static function actor(PDO $p,int $id):array{$u=self::query($p,'SELECT id,name,email,role,is_active,email_verified_at FROM users WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if(!$u||$u['role']!=='admin'||!(int)$u['is_active']||empty($u['email_verified_at']))throw new CashAdjustmentException('An active, verified administrator is required.',403);return $u;}
 private static function customer(PDO $p,int $id):array{$u=self::query($p,'SELECT id,name,email,role,is_active,email_verified_at FROM users WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if(!$u||$u['role']==='admin'||!(int)$u['is_active']||empty($u['email_verified_at']))throw new CashAdjustmentException('Choose an active, verified customer account.',422);return $u;}
 public static function cents(mixed $value):int{if(!is_string($value)||!preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,2}))?$/D',$value,$m))throw new CashAdjustmentException('Enter the exact rand amount, for example 125.50.');$c=(int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');if($c<1||$c>1000000000)throw new CashAdjustmentException('Enter cleared funds between R0.01 and R10,000,000.00.');return $c;}
 public static function bankReference(string $value):string{$v=strtoupper(preg_replace('/\s+/','',trim($value)));if(strlen($v)<4||strlen($v)>120||preg_match('/[\x00-\x1f\x7f]/',$v))throw new CashAdjustmentException('Enter the unique bank transaction ID (4–120 characters), not a reusable customer/payment reference.');return $v;}
 /** Shared with order approval while its existing finance-security lock is held. */
 public static function assertBankNotAssigned(PDO $p,string $reference):void{
  if(!self::tableExists($p,'customer_cash_adjustments'))return;
  if(self::query($p,'SELECT id FROM customer_cash_adjustments WHERE bank_hash=?',[hash('sha256',self::bankReference($reference))])->fetchColumn())throw new DomainException('This bank deposit has already been recorded as a rand-balance credit. Reconcile it first; it cannot also fund another SMS purchase.');
 }
 private static function calculation(PDO $p,int $user,string $kind,string $orderReference,string $bank,int $cleared):array{
  if(self::query($p,'SELECT id FROM customer_cash_adjustments WHERE bank_hash=?',[hash('sha256',$bank)])->fetchColumn())throw new CashAdjustmentException('This bank deposit or overpayment has already been credited. Check the adjustment history.',409);
  $used=self::query($p,'SELECT id,user_id,reference FROM credit_purchase_orders WHERE bank_reference=?',[$bank])->fetch(PDO::FETCH_ASSOC);
  if($kind==='deposit'){
   if($orderReference!=='')throw new CashAdjustmentException('Use the completed-order overpayment option when linking a purchase.');
   if($used)throw new CashAdjustmentException('This bank transaction belongs to an existing purchase. Use the completed-order overpayment option; do not credit the whole deposit again.',409);
   return ['order_id'=>null,'order_reference'=>null,'recorded_cents'=>0,'amount_cents'=>$cleared];
  }
  if($kind!=='overpayment'||$orderReference==='')throw new CashAdjustmentException('Enter the completed EFT order reference for this overpayment.');
  $order=self::query($p,"SELECT o.id,o.reference,o.bank_reference,o.status,o.payment_method,p.status payment_status,p.amount,p.currency,p.gateway,p.user_id payment_user FROM credit_purchase_orders o JOIN payments p ON p.id=o.payment_id WHERE o.reference=? AND o.user_id=?",[$orderReference,$user])->fetch(PDO::FETCH_ASSOC);
  if(!$order)throw new CashAdjustmentException('A matching purchase was not found for this customer.',404);
  if($order['status']!=='completed'||$order['payment_status']!=='completed'||$order['gateway']!=='eft'||$order['payment_method']!=='eft'||$order['currency']!=='ZAR'||(int)$order['payment_user']!==$user)throw new CashAdjustmentException('Complete the original EFT payment review first. This separate action only reconciles an already completed purchase.',409);
  if(empty($order['bank_reference'])||self::bankReference($order['bank_reference'])!==$bank||!$used||(int)$used['id']!==(int)$order['id'])throw new CashAdjustmentException('The unique bank transaction ID must match the completed EFT purchase.',409);
  $allocation=self::query($p,'SELECT status FROM credit_cash_allocations WHERE order_id=?',[(int)$order['id']])->fetchColumn();
  if($allocation!==false&&$allocation!=='settled')throw new CashAdjustmentException('The original cash allocation is not settled. Reconcile the completed purchase first.',409);
  $money=(string)$order['amount'];
  if(!preg_match('/^(\d{1,9})(?:\.(\d{1,4}))?$/D',$money,$parts)||substr(str_pad($parts[2]??'',4,'0'),2)!=='00')throw new CashAdjustmentException('The original payment needs financial reconciliation before adjusting.',409);
  $recorded=(int)$parts[1]*100+(int)substr(str_pad($parts[2]??'',4,'0'),0,2);$extra=$cleared-$recorded;
  if($extra<=0)throw new CashAdjustmentException('This amount is already accounted for. Only previously unrecorded excess funds can be credited; existing overpayment credits must not be added again.',409);
  return ['order_id'=>(int)$order['id'],'order_reference'=>$order['reference'],'recorded_cents'=>$recorded,'amount_cents'=>$extra];
 }
 public static function quote(PDO $p,int $actor,array $input):array{
  self::actor($p,$actor);self::ready($p);
  if(array_diff(array_keys($input),['user_id','source_kind','order_reference','bank_reference','cleared_amount','reason','funds_verified','request_id']))throw new CashAdjustmentException('Unsupported rand-credit fields.');
  $id=$input['user_id']??null;if(!(is_int($id)||is_string($id))||!preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id))throw new CashAdjustmentException('Choose a customer.');$user=(int)$id;self::customer($p,$user);
  $kind=$input['source_kind']??'';$reference=$input['order_reference']??'';$bank=$input['bank_reference']??'';$reason=$input['reason']??'';$request=$input['request_id']??'';
  if(!is_string($kind)||!in_array($kind,['deposit','overpayment'],true)||!is_string($reference)||strlen($reference)>100||!is_string($bank)||!is_string($reason)||strlen(trim($reason))<10||strlen($reason)>1000||!is_string($request)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$request)||($input['funds_verified']??null)!==true)throw new CashAdjustmentException('Confirm cleared funds, choose the source and enter a reason of 10–1,000 characters.');
  $bank=self::bankReference($bank);$reference=strtoupper(trim($reference));$reason=trim($reason);$cleared=self::cents($input['cleared_amount']??null);$hash=hash('sha256',json_encode([$user,$kind,$reference,$bank,$cleared,$reason],JSON_THROW_ON_ERROR));
  $old=self::query($p,'SELECT * FROM customer_cash_adjustment_quotes WHERE actor_user_id=? AND request_id=?',[$actor,strtolower($request)])->fetch(PDO::FETCH_ASSOC);
  if($old){if($old['input_hash']!==$hash)throw new CashAdjustmentException('This request ID belongs to a different adjustment. Start a new review.',409);return self::presentQuote($p,$old);}
  $calc=self::calculation($p,$user,$kind,$reference,$bank,$cleared);$row=['id'=>bin2hex(random_bytes(16)),'actor_user_id'=>$actor,'user_id'=>$user,'request_id'=>strtolower($request),'input_hash'=>$hash,'source_kind'=>$kind,...$calc,'bank_reference'=>$bank,'bank_hash'=>hash('sha256',$bank),'cleared_cents'=>$cleared,'reason'=>$reason,'expires_at'=>time()+600,'created_at'=>date('Y-m-d H:i:s')];
  try{self::query($p,'INSERT INTO customer_cash_adjustment_quotes('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));}
  catch(PDOException $e){if((string)$e->getCode()!=='23000')throw $e;$old=self::query($p,'SELECT * FROM customer_cash_adjustment_quotes WHERE actor_user_id=? AND request_id=?',[$actor,strtolower($request)])->fetch(PDO::FETCH_ASSOC);if(!$old||$old['input_hash']!==$hash)throw new CashAdjustmentException('This request reference already exists. Check its status before starting another.',409);$row=$old;}
  return self::presentQuote($p,$row);
 }
 private static function presentQuote(PDO $p,array $row):array{$u=self::customer($p,(int)$row['user_id']);$out=array_intersect_key($row,array_flip(['id','user_id','source_kind','order_reference','bank_reference','cleared_cents','recorded_cents','amount_cents','reason','expires_at']));foreach(['user_id','cleared_cents','recorded_cents','amount_cents','expires_at']as$key)$out[$key]=(int)$out[$key];return $out+['customer'=>array_intersect_key($u,array_flip(['id','name','email'])),'cash_balance'=>CustomerCashBalance::snapshot($p,(int)$row['user_id'])];}
 public static function execute(PDO $p,int $actor,string $id,#[SensitiveParameter] mixed $password,bool $confirmed):array{
  self::actor($p,$actor);self::ready($p);self::quoteId($id);if(!$confirmed)throw new CashAdjustmentException('Explicitly confirm the reviewed rand credit.');
  $q=self::query($p,'SELECT * FROM customer_cash_adjustment_quotes WHERE id=? AND actor_user_id=?',[$id,$actor])->fetch(PDO::FETCH_ASSOC);if(!$q)throw new CashAdjustmentException('Adjustment review not found.',404);
  $existing=self::query($p,'SELECT * FROM customer_cash_adjustments WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if($existing)return self::receipt($existing);
  $version=CreditApprovalSecurity::verify($p,$actor,$password);$p->beginTransaction();
  try{
   CreditApprovalSecurity::assertVersion($p,$version);$q=self::query($p,'SELECT * FROM customer_cash_adjustment_quotes WHERE id=? AND actor_user_id=?'.self::lock($p),[$id,$actor])->fetch(PDO::FETCH_ASSOC);
   $existing=self::query($p,'SELECT * FROM customer_cash_adjustments WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if($existing){$p->commit();return self::receipt($existing);}
   $lockedUsers=array_unique([$actor,(int)$q['user_id']]);sort($lockedUsers,SORT_NUMERIC);foreach($lockedUsers as$uid){self::query($p,'SELECT id FROM users WHERE id=?'.self::lock($p),[$uid])->fetchColumn();}
   self::actor($p,$actor);self::customer($p,(int)$q['user_id']);if((int)$q['expires_at']<time())throw new CashAdjustmentException('This review expired. Review the amount again before confirming.',409);
   $calc=self::calculation($p,(int)$q['user_id'],$q['source_kind'],$q['order_reference']??'',$q['bank_reference'],(int)$q['cleared_cents']);
   if($calc['order_id']!==($q['order_id']===null?null:(int)$q['order_id'])||(int)$calc['amount_cents']!==(int)$q['amount_cents']||(int)$calc['recorded_cents']!==(int)$q['recorded_cents'])throw new CashAdjustmentException('The original payment changed. Start a fresh review before crediting.',409);
   $before=CustomerCashBalance::snapshot($p,(int)$q['user_id'])['balance_cents'];if($before>PHP_INT_MAX-(int)$q['amount_cents'])throw new CashAdjustmentException('Rand balance is outside the supported range.',409);
   $row=array_intersect_key($q,array_flip(['id','actor_user_id','user_id','source_kind','order_id','order_reference','bank_reference','bank_hash','cleared_cents','recorded_cents','amount_cents','reason']));$row+=['balance_before_cents'=>$before,'balance_after_cents'=>$before+(int)$q['amount_cents'],'security_version'=>$version,'created_at'=>date('Y-m-d H:i:s')];
   self::query($p,'INSERT INTO customer_cash_adjustments('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));CreditApprovalSecurity::event($p,$actor,'rand_credit_recorded',$q['order_id']===null?null:(int)$q['order_id'],$version);
   $p->commit();return self::receipt($row);
  }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
 }
 private static function quoteId(string $id):void{if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new CashAdjustmentException('Choose a valid adjustment review.');}
 private static function receipt(array $row):array{$out=array_intersect_key($row,array_flip(['id','user_id','source_kind','order_reference','bank_reference','cleared_cents','recorded_cents','amount_cents','balance_before_cents','balance_after_cents','reason','created_at']));foreach(['user_id','cleared_cents','recorded_cents','amount_cents','balance_before_cents','balance_after_cents']as$key)$out[$key]=(int)$out[$key];return $out+['reference'=>'RAND-'.strtoupper($row['id']),'status'=>'completed'];}
 public static function accounts(PDO $p,int $actor,string $search):array{self::actor($p,$actor);self::ready($p);$search=trim($search);if(strlen($search)<2||strlen($search)>120)return [];$pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';return self::query($p,"SELECT id,name,email FROM users WHERE role<>'admin' AND is_active=1 AND email_verified_at IS NOT NULL AND (name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!') ORDER BY email,id LIMIT 15",[$pattern,$pattern])->fetchAll(PDO::FETCH_ASSOC);}
 public static function status(PDO $p,int $actor,string $id):array{self::actor($p,$actor);self::quoteId($id);if(!self::available($p))throw new CashAdjustmentException('Rand-credit history requires database setup.',503);$q=self::query($p,'SELECT * FROM customer_cash_adjustment_quotes WHERE id=? AND actor_user_id=?',[$id,$actor])->fetch(PDO::FETCH_ASSOC);if(!$q)throw new CashAdjustmentException('Adjustment review not found.',404);$row=self::query($p,'SELECT * FROM customer_cash_adjustments WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);return $row?['status'=>'completed','adjustment'=>self::receipt($row)]:['status'=>'not_completed','quote'=>self::presentQuote($p,$q),'expired'=>(int)$q['expires_at']<time()];}
 public static function history(PDO $p,int $actor,?int $user=null,int $page=1):array{self::actor($p,$actor);$available=self::available($p);if(!$available)return ['enabled'=>false,'adjustments'=>[],'total'=>0,'total_credited_cents'=>0,'page'=>1,'cash_balance'=>null];$where=$user===null?'1=1':'a.user_id=?';$args=$user===null?[]:[$user];$total=(int)self::query($p,'SELECT COUNT(*) FROM customer_cash_adjustments a WHERE '.$where,$args)->fetchColumn();$page=max(1,min(10000,$page));$rows=self::query($p,'SELECT a.*,u.name customer_name,u.email customer_email FROM customer_cash_adjustments a JOIN users u ON u.id=a.user_id WHERE '.$where.' ORDER BY a.created_at DESC,a.id DESC LIMIT 20 OFFSET '.(($page-1)*20),$args)->fetchAll(PDO::FETCH_ASSOC);return ['enabled'=>env('CASH_BALANCE_ADJUSTMENTS_ENABLED','false')==='true','adjustments'=>array_map(fn($r)=>self::receipt($r)+['customer_name'=>$r['customer_name'],'customer_email'=>$r['customer_email']],$rows),'total'=>$total,'total_credited_cents'=>(int)self::query($p,'SELECT COALESCE(SUM(a.amount_cents),0) FROM customer_cash_adjustments a WHERE '.$where,$args)->fetchColumn(),'page'=>$page,'cash_balance'=>$user===null?null:CustomerCashBalance::snapshot($p,$user)];}
}
