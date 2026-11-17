<?php
require_once __DIR__.'/SmsPricing.php';
require_once __DIR__.'/CreditApprovalSecurity.php';
require_once __DIR__.'/../services/CreditTransferReceiptService.php';

final class CreditTransferException extends RuntimeException
{
    public function __construct(string $message,public readonly int $status=422){parent::__construct($message);}
}

/** Authorized movement of existing credits. No purchases, SMS or cash ledger mutations. */
final class CreditTransferService
{
    public const MAX_CREDITS=1000000;
    private const MAX_UNITS=999999999900; // Common DECIMAL(10,2) wallet ceiling, in 1/10,000 ZAR.
    private static function query(PDO $pdo,string $sql,array $args=[]): PDOStatement
    { $q=$pdo->prepare($sql);$q->execute($args);return $q; }
    private static function lock(PDO $pdo): string{return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    public static function available(PDO $pdo): bool
    {
        $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ?"SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('credit_transfer_quotes','credit_transfers','credit_transfer_security')"
            :"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('credit_transfer_quotes','credit_transfers','credit_transfer_security')";
        return (int)$pdo->query($sql)->fetchColumn()===3;
    }
    private static function initiator(PDO $pdo,int $id,bool $customer): array
    {
        if(!$customer)return self::actor($pdo,$id);
        $u=self::query($pdo,'SELECT id,name,email,role,is_active,email_verified_at FROM users WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$u||$u['role']==='admin'||(int)$u['is_active']!==1||empty($u['email_verified_at']))throw new CreditTransferException('An active, verified customer account is required.',403);
        return $u;
    }
    public static function actor(PDO $pdo,int $actor): array
    {
        $u=self::query($pdo,'SELECT id,name,email,role,is_active,email_verified_at FROM users WHERE id=?',[$actor])->fetch(PDO::FETCH_ASSOC);
        if(!$u||$u['role']!=='admin'||(int)$u['is_active']!==1||empty($u['email_verified_at']))throw new CreditTransferException('A verified active administrator is required.',403);
        return $u;
    }
    private static function ready(PDO $pdo): void
    {
        if(env('CREDIT_TRANSFERS_ENABLED','false')!=='true'||!self::available($pdo))throw new CreditTransferException('Credit transfers are not enabled yet. No credits have moved.',503);
    }
    public static function units(mixed $amount): int
    {
        if(!is_string($amount)&&!is_int($amount))throw new CreditTransferException('Wallet reconciliation is required before transferring.',409);
        if(!preg_match('/^(\d{1,10})(?:\.(\d{1,4}))?$/D',(string)$amount,$m))throw new CreditTransferException('Wallet reconciliation is required before transferring.',409);
        $units=(int)$m[1]*10000+(int)str_pad($m[2]??'',4,'0');
        if($units>self::MAX_UNITS)throw new CreditTransferException('Wallet reconciliation is required before transferring.',409);
        return $units;
    }
    public static function money(int $units): string{return sprintf('%d.%04d',intdiv($units,10000),$units%10000);}
    private static function rate(): int
    {
        $units=self::units(number_format(SmsPricing::pricePerSegment(),4,'.',''));
        if($units<=0||$units%100!==0)throw new CreditTransferException('The SMS credit rate must be exactly representable in wallet cents.',503);
        return $units;
    }
    private static function id(mixed $id): int
    {
        if((!is_string($id)&&!is_int($id))||!preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id))throw new CreditTransferException('Choose valid source and destination accounts.');
        return (int)$id;
    }
    private static function quoteId(string $id): void {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new CreditTransferException('Transfer review not found.',404);}
    private static function users(PDO $pdo,array $ids,bool $lock): array
    {
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);$out=[];
        foreach($ids as$id){$u=self::query($pdo,'SELECT id,name,email,role,is_active,email_verified_at FROM users WHERE id=?'.($lock?self::lock($pdo):''),[$id])->fetch(PDO::FETCH_ASSOC);if(!$u)throw new CreditTransferException('An account no longer exists.',409);$out[(int)$u['id']]=$u;}
        return $out;
    }
    private static function customer(array $u): void
    {
        if($u['role']==='admin'||(int)$u['is_active']!==1||empty($u['email_verified_at']))throw new CreditTransferException('Both customers must have active, verified accounts.',409);
    }
    private static function lockedInitiator(array $u,bool $customer): void
    {
        // Use the locking-read row, not a repeatable-read snapshot established
        // earlier while checking for an existing receipt.
        if((int)$u['is_active']!==1||empty($u['email_verified_at'])||($customer?$u['role']==='admin':$u['role']!=='admin'))throw new CreditTransferException('The initiating account is no longer authorized.',403);
    }
    private static function wallets(PDO $pdo,int $source,int $destination,bool $lock): array
    {
        $ids=[$source,$destination];sort($ids,SORT_NUMERIC);$rows=[];
        // Lock all selected wallets in one deterministic order; reject legacy duplicates.
        $found=self::query($pdo,'SELECT id,user_id,balance,reserved,currency FROM wallets WHERE user_id IN (?,?) ORDER BY id'.($lock?self::lock($pdo):''),$ids)->fetchAll(PDO::FETCH_ASSOC);
        foreach($found as$w){$uid=(int)$w['user_id'];if(isset($rows[$uid]))throw new CreditTransferException('An account has multiple wallets. Reconcile it before transferring.',409);$rows[$uid]=$w;}
        foreach($ids as$id){if(!isset($rows[$id]))throw new CreditTransferException('Both accounts need an existing SMS wallet. Open the missing account’s wallet first.',409);$w=$rows[$id];if($w['currency']!=='ZAR'||self::units((string)$w['reserved'])>self::units((string)$w['balance']))throw new CreditTransferException('Wallet reconciliation is required before transferring.',409);}
        return $rows;
    }
    public static function quote(PDO $pdo,int $actor,array $input,bool $customer=false): array
    {
        self::initiator($pdo,$actor,$customer);self::ready($pdo);
        if(array_diff(array_keys($input),['source_user_id','destination_user_id','credits','reason','authorization_confirmed','request_id']))throw new CreditTransferException('Unsupported transfer fields.');
        $source=self::id($input['source_user_id']??null);$destination=self::id($input['destination_user_id']??null);
        if($customer&&$source!==$actor)throw new CreditTransferException('Customers can transfer only their own credits.',403);
        if($source===$destination)throw new CreditTransferException('Choose two different customer accounts.');
        $credits=$input['credits']??null;
        if((!is_int($credits)&&!is_string($credits))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$credits)||(int)$credits>self::MAX_CREDITS)throw new CreditTransferException('Transfer a whole number from 1 to 1,000,000 SMS credits.');
        $credits=(int)$credits;$reason=$input['reason']??null;$request=$input['request_id']??'';
        if(!is_string($reason)||!preg_match('//u',$reason)||preg_match('/[\x00-\x1F\x7F]/',$reason)||strlen(trim($reason))<10||strlen(trim($reason))>500)throw new CreditTransferException('Enter an authorization reason of 10–500 bytes without line breaks.');
        if(($input['authorization_confirmed']??null)!==true)throw new CreditTransferException('Confirm that the source account authorized this transfer.');
        if(!is_string($request)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$request))throw new CreditTransferException('A stable transfer request reference is required.');
        $reason=trim($reason);$rate=self::rate();$amount=$credits*$rate;if($amount>self::MAX_UNITS)throw new CreditTransferException('This transfer exceeds the supported wallet amount.');
        $pdo->beginTransaction();
        try{
            $users=self::users($pdo,[$actor,$source,$destination],true);self::lockedInitiator($users[$actor],$customer);self::customer($users[$source]);self::customer($users[$destination]);
            $old=self::query($pdo,'SELECT * FROM credit_transfer_quotes WHERE actor_user_id=? AND request_id=?',[$actor,$request])->fetch(PDO::FETCH_ASSOC);
            if($old){if((int)$old['source_user_id']!==$source||(int)$old['destination_user_id']!==$destination||(int)$old['credits']!==$credits||$old['reason']!==$reason||$old['authorization_mode']!==($customer?'customer':'admin'))throw new CreditTransferException('This request reference already belongs to a different transfer.',409);$pdo->commit();return self::presentQuote($old,$customer);}
            $wallets=self::wallets($pdo,$source,$destination,true);$from=$wallets[$source];$to=$wallets[$destination];
            $available=self::units((string)$from['balance'])-self::units((string)$from['reserved']);
            if($amount>$available)throw new CreditTransferException('Not enough unreserved SMS credits. Credits reserved for sending cannot be transferred.',409);
            if(self::units((string)$to['balance'])+$amount>self::MAX_UNITS)throw new CreditTransferException('The destination wallet cannot accept this amount.',409);
            $id=bin2hex(random_bytes(16));$now=date('Y-m-d H:i:s');
            $row=['id'=>$id,'actor_user_id'=>$actor,'request_id'=>$request,'source_user_id'=>$source,'destination_user_id'=>$destination,'source_wallet_id'=>$from['id'],'destination_wallet_id'=>$to['id'],'credits'=>$credits,'unit_amount'=>self::money($rate),'amount'=>self::money($amount),'reason'=>$reason,'source_name'=>$users[$source]['name'],'source_email'=>$users[$source]['email'],'destination_name'=>$users[$destination]['name'],'destination_email'=>$users[$destination]['email'],'source_available'=>self::money($available),'destination_available'=>self::money(self::units((string)$to['balance'])-self::units((string)$to['reserved'])),'expires_at'=>time()+600,'created_at'=>$now];
            $row['authorization_mode']=$customer?'customer':'admin';
            self::query($pdo,'INSERT INTO credit_transfer_quotes('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));$pdo->commit();return self::presentQuote($row,$customer);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    private static function presentQuote(array $q,bool $customer=false): array
    {
        $rate=self::units((string)$q['unit_amount']);$amount=self::units((string)$q['amount']);
        $out=['id'=>$q['id'],'source'=>['id'=>(string)$q['source_user_id'],'name'=>$q['source_name'],'email'=>$q['source_email']],'destination'=>['id'=>$customer?'recipient':(string)$q['destination_user_id'],'name'=>$q['destination_name'],'email'=>$q['destination_email']],'credits'=>(int)$q['credits'],'amount'=>self::money($amount),'unit_amount'=>self::money($rate),'currency'=>'ZAR','reason'=>$q['reason'],'source_available_credits'=>intdiv(self::units((string)$q['source_available']),$rate),'source_available_after'=>intdiv(self::units((string)$q['source_available'])-$amount,$rate),'expires_at'=>(int)$q['expires_at'],'quote_only'=>true];
        if(!$customer)$out['destination_available_after']=intdiv(self::units((string)$q['destination_available'])+$amount,$rate);
        return $out;
    }
    private static function receipt(PDO $pdo,array $t): array
    {
        $q=self::query($pdo,'SELECT source_name,source_email,destination_name,destination_email,reason FROM credit_transfer_quotes WHERE id=?',[$t['id']])->fetch(PDO::FETCH_ASSOC);
        return ['id'=>$t['id'],'reference'=>'TRF-'.strtoupper($t['id']),'credits'=>(int)$t['credits'],'amount'=>$t['amount'],'unit_amount'=>$t['unit_amount'],'currency'=>'ZAR','status'=>'completed','source_user_id'=>(string)$t['source_user_id'],'destination_user_id'=>(string)$t['destination_user_id'],'source_name'=>$q['source_name'],'source_email'=>$q['source_email'],'destination_name'=>$q['destination_name'],'destination_email'=>$q['destination_email'],'reason'=>$q['reason'],'source_available_after'=>intdiv(self::units((string)$t['source_after'])-self::units((string)$t['source_reserved']),self::units((string)$t['unit_amount'])),'destination_available_after'=>intdiv(self::units((string)$t['destination_after'])-self::units((string)$t['destination_reserved']),self::units((string)$t['unit_amount'])),'created_at'=>$t['created_at'],'reserved_credits_unchanged'=>true];
    }
    public static function execute(PDO $pdo,int $actor,string $id,#[SensitiveParameter] mixed $password,bool $confirmed,bool $customer=false): array
    {
        self::initiator($pdo,$actor,$customer);self::ready($pdo);self::quoteId($id);
        if(!$confirmed)throw new CreditTransferException('Confirm the reviewed source, destination and credit amount.');
        $q=self::query($pdo,'SELECT * FROM credit_transfer_quotes WHERE id=? AND actor_user_id=?',[$id,$actor])->fetch(PDO::FETCH_ASSOC);
        if(!$q||$q['authorization_mode']!==($customer?'customer':'admin')||($customer&&(int)$q['source_user_id']!==$actor))throw new CreditTransferException('Transfer review not found.',404);
        $existing=self::query($pdo,'SELECT * FROM credit_transfers WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if($existing)return self::safeReceipt($pdo,$existing,$customer); // Read-only response-loss recovery; never repeats a debit.
        if((int)$q['expires_at']<time())throw new CreditTransferException('This transfer review expired. Review a fresh quote.',409);
        $securityVersion=$customer?0:CreditApprovalSecurity::verify($pdo,$actor,$password);
        if($customer)self::prepareSecurity($pdo,$actor);
        $pdo->beginTransaction();
        try{
            if($customer)$guard=self::security($pdo,$actor);else CreditApprovalSecurity::assertVersion($pdo,$securityVersion);
            $q=self::query($pdo,'SELECT * FROM credit_transfer_quotes WHERE id=? AND actor_user_id=?'.self::lock($pdo),[$id,$actor])->fetch(PDO::FETCH_ASSOC);
            if(!$q)throw new CreditTransferException('Transfer review not found.',404);
            $source=(int)$q['source_user_id'];$destination=(int)$q['destination_user_id'];
            $existing=self::query($pdo,'SELECT * FROM credit_transfers WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
            if($existing){$pdo->commit();return self::safeReceipt($pdo,$existing,$customer);}
            if($customer){
                if((int)$guard['blocked_until']>time())throw new CreditTransferException('Too many incorrect codes. Try again in 15 minutes.',429);
                if(empty($q['code_hash'])||(int)$q['code_expires_at']<time())throw new CreditTransferException('Request a fresh verification code for this review.',422);
                if(!is_string($password)||!preg_match('/^[0-9]{6}$/D',$password)||!password_verify($password,$q['code_hash'])){
                    $start=time()-(int)$guard['window_started']<900?(int)$guard['window_started']:time();
                    $failures=$start===(int)$guard['window_started']?(int)$guard['failures']+1:1;$blocked=$failures>=5?time()+900:0;
                    self::query($pdo,'UPDATE credit_transfer_security SET failures=?,window_started=?,blocked_until=? WHERE user_id=?',[$failures,$start,$blocked,$actor]);
                    $pdo->commit();throw new CreditTransferException($blocked?'Too many incorrect codes. Try again in 15 minutes.':'The verification code is incorrect. No credits moved.',$blocked?429:422);
                }
            }
            $users=self::users($pdo,[$actor,$source,$destination],true);self::lockedInitiator($users[$actor],$customer);
            self::customer($users[$source]);self::customer($users[$destination]);
            if((int)$q['expires_at']<time()||self::rate()!==self::units((string)$q['unit_amount']))throw new CreditTransferException('The review expired or pricing changed. Review the transfer again.',409);
            foreach(['source'=>$source,'destination'=>$destination]as$prefix=>$uid)if($users[$uid]['email']!==$q[$prefix.'_email']||$users[$uid]['name']!==$q[$prefix.'_name'])throw new CreditTransferException('Account details changed. Review the transfer again.',409);
            $wallets=self::wallets($pdo,$source,$destination,true);$from=$wallets[$source];$to=$wallets[$destination];
            if((int)$from['id']!==(int)$q['source_wallet_id']||(int)$to['id']!==(int)$q['destination_wallet_id'])throw new CreditTransferException('An account wallet changed. Review the transfer again.',409);
            $amount=self::units((string)$q['amount']);$beforeFrom=self::units((string)$from['balance']);$beforeTo=self::units((string)$to['balance']);
            $reservedFrom=self::units((string)$from['reserved']);$reservedTo=self::units((string)$to['reserved']);
            if($amount!==self::rate()*(int)$q['credits']||$amount<=0)throw new CreditTransferException('Transfer reconciliation is required.',409);
            if($beforeFrom-$reservedFrom<$amount)throw new CreditTransferException('Available credits changed. Review again; reserved credits cannot be transferred.',409);
            if($beforeTo+$amount>self::MAX_UNITS)throw new CreditTransferException('The destination wallet cannot accept this amount.',409);
            $key='credit-transfer:'.$id;$pairs=self::query($pdo,'SELECT COUNT(*) FROM wallet_ledger WHERE idempotency_key IN (?,?)',[$key.':out',$key.':in'])->fetchColumn();
            if((int)$pairs!==0)throw new CreditTransferException('Transfer ledger reconciliation is required. No new transfer was applied.',409);
            $now=date('Y-m-d H:i:s');$meta=json_encode(['transfer_id'=>$id,'actor_user_id'=>$actor,'source_user_id'=>$source,'destination_user_id'=>$destination,'credits'=>(int)$q['credits'],'unit_amount'=>$q['unit_amount']],JSON_THROW_ON_ERROR);
            foreach([[$source,$from,'credit_transfer_out','debit','out'],[$destination,$to,'credit_transfer_in','credit','in']]as[$uid,$w,$operation,$direction,$suffix])self::query($pdo,'INSERT INTO wallet_ledger(user_id,wallet_id,campaign_id,payment_id,operation,direction,amount,idempotency_key,metadata_json,created_at) VALUES(?,?,NULL,NULL,?,?,?,?,?,?)',[$uid,$w['id'],$operation,$direction,$q['amount'],$key.':'.$suffix,$meta,$now]);
            self::query($pdo,'UPDATE wallets SET balance=?,updated_at=? WHERE id=? AND user_id=?',[self::money($beforeFrom-$amount),$now,$from['id'],$source]);
            self::query($pdo,'UPDATE wallets SET balance=?,updated_at=? WHERE id=? AND user_id=?',[self::money($beforeTo+$amount),$now,$to['id'],$destination]);
            $after=self::wallets($pdo,$source,$destination,false);
            if(self::units((string)$after[$source]['balance'])!==$beforeFrom-$amount||self::units((string)$after[$destination]['balance'])!==$beforeTo+$amount||self::units((string)$after[$source]['reserved'])!==$reservedFrom||self::units((string)$after[$destination]['reserved'])!==$reservedTo)throw new CreditTransferException('Wallet verification failed. The complete transfer was rolled back.',409);
            $t=['id'=>$id,'actor_user_id'=>$actor,'source_user_id'=>$source,'destination_user_id'=>$destination,'source_wallet_id'=>$from['id'],'destination_wallet_id'=>$to['id'],'credits'=>(int)$q['credits'],'unit_amount'=>$q['unit_amount'],'amount'=>$q['amount'],'source_before'=>$from['balance'],'source_after'=>self::money($beforeFrom-$amount),'source_reserved'=>$from['reserved'],'destination_before'=>$to['balance'],'destination_after'=>self::money($beforeTo+$amount),'destination_reserved'=>$to['reserved'],'created_at'=>$now];
            self::query($pdo,'INSERT INTO credit_transfers('.implode(',',array_keys($t)).') VALUES('.implode(',',array_fill(0,count($t),'?')).')',array_values($t));
            CreditTransferReceiptService::enqueue($pdo,$t);
            if($customer){self::query($pdo,'UPDATE credit_transfer_quotes SET code_hash=NULL WHERE id=?',[$id]);self::query($pdo,'UPDATE credit_transfer_security SET failures=0,blocked_until=0 WHERE user_id=?',[$actor]);}
            CreditApprovalSecurity::event($pdo,$actor,$customer?'customer_credits_transferred':'credits_transferred',null,$securityVersion);
            $receipt=self::safeReceipt($pdo,$t,$customer);$pdo->commit();return $receipt;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public static function accounts(PDO $pdo,int $actor,string $search): array
    {
        self::actor($pdo,$actor);self::ready($pdo);$search=trim($search);
        if(strlen($search)<2||strlen($search)>120)return [];
        $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
        $rows=self::query($pdo,"SELECT u.id,u.name,u.email FROM users u WHERE u.role<>'admin' AND u.is_active=1 AND u.email_verified_at IS NOT NULL AND (u.name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!') ORDER BY u.email,u.id LIMIT 15",[$pattern,$pattern])->fetchAll(PDO::FETCH_ASSOC);$rate=self::rate();$out=[];
        foreach($rows as$u){$w=self::query($pdo,'SELECT id,balance,reserved,currency FROM wallets WHERE user_id=? ORDER BY id LIMIT 2',[$u['id']])->fetchAll(PDO::FETCH_ASSOC);$available=null;$eligible=count($w)===1&&$w[0]['currency']==='ZAR';if($eligible){try{$units=self::units((string)$w[0]['balance'])-self::units((string)$w[0]['reserved']);$eligible=$units>=0;if($eligible)$available=intdiv($units,$rate);}catch(CreditTransferException){$eligible=false;}}$out[]=['id'=>(string)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'available_credits'=>$available,'eligible'=>$eligible];}
        return $out;
    }
    public static function history(PDO $pdo,int $user,bool $admin,int $page=1): array
    {
        if($admin)self::actor($pdo,$user);$enabled=env('CREDIT_TRANSFERS_ENABLED','false')==='true'&&self::available($pdo);
        if(!self::available($pdo))return ['enabled'=>false,'transfers'=>[],'total'=>0,'page'=>1,'last_page'=>1];
        $page=max(1,min(10000,$page));$where=$admin?'1=1':'(source_user_id=? OR destination_user_id=?)';$args=$admin?[]:[$user,$user];
        $total=(int)self::query($pdo,'SELECT COUNT(*) FROM credit_transfers WHERE '.$where,$args)->fetchColumn();
        $rows=self::query($pdo,'SELECT * FROM credit_transfers WHERE '.$where.' ORDER BY created_at DESC,id DESC LIMIT 20 OFFSET '.(($page-1)*20),$args)->fetchAll(PDO::FETCH_ASSOC);$out=[];
        foreach($rows as$t){if($admin)$out[]=self::receipt($pdo,$t);else $out[]=['id'=>$t['id'],'reference'=>'TRF-'.strtoupper($t['id']),'credits'=>(int)$t['credits'],'direction'=>(int)$t['source_user_id']===$user?'sent':'received','status'=>'completed','created_at'=>$t['created_at']];}
        return ['enabled'=>$enabled,'transfers'=>$out,'total'=>$total,'page'=>$page,'last_page'=>max(1,(int)ceil($total/20))];
    }
    public static function status(PDO $pdo,int $actor,string $id,bool $customer=false): array
    {
        self::initiator($pdo,$actor,$customer);self::quoteId($id);
        if(!self::available($pdo))throw new CreditTransferException('Transfer history is not available yet.',503);
        $pdo->beginTransaction();
        try{
            // Wait for any already-running transaction on this exact review. A
            // fresh review is safe only once it has expired without completion;
            // a late old request will then fail its in-transaction expiry check.
            $q=self::query($pdo,'SELECT * FROM credit_transfer_quotes WHERE id=? AND actor_user_id=?'.self::lock($pdo),[$id,$actor])->fetch(PDO::FETCH_ASSOC);
            if(!$q||$q['authorization_mode']!==($customer?'customer':'admin')||($customer&&(int)$q['source_user_id']!==$actor))throw new CreditTransferException('Transfer review not found.',404);
            $t=self::query($pdo,'SELECT * FROM credit_transfers WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
            $result=$t?['status'=>'completed','transfer'=>self::safeReceipt($pdo,$t,$customer)]:['status'=>'not_completed','quote'=>self::presentQuote($q,$customer),'new_review_allowed'=>(int)$q['expires_at']<time()];
            $pdo->commit();return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    private static function safeReceipt(PDO $pdo,array $t,bool $customer): array
    {
        $out=self::receipt($pdo,$t);
        if($customer)unset($out['destination_available_after'],$out['source_user_id'],$out['destination_user_id']);
        $out['amount']=self::money(self::units((string)$out['amount']));$out['unit_amount']=self::money(self::units((string)$out['unit_amount']));
        return $out;
    }
    private static function security(PDO $pdo,int $actor): array
    {
        $row=self::query($pdo,'SELECT * FROM credit_transfer_security WHERE user_id=?'.self::lock($pdo),[$actor])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new CreditTransferException('Transfer verification is unavailable. No credits moved.',503);
        return $row;
    }
    private static function prepareSecurity(PDO $pdo,int $actor): void
    {
        if($pdo->inTransaction())throw new LogicException('Initialize transfer verification before the financial transaction.');
        // Initialization has its own short autocommit. Inserting an existing FK
        // row inside the wallet transaction takes a premature users-row lock.
        if(!self::query($pdo,'SELECT user_id FROM credit_transfer_security WHERE user_id=?',[$actor])->fetchColumn()){
            $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE INTO credit_transfer_security(user_id) VALUES(?)':'INSERT OR IGNORE INTO credit_transfer_security(user_id) VALUES(?)';
            self::query($pdo,$sql,[$actor]);
        }
    }
    public static function customerQuote(PDO $pdo,int $actor,array $input): array
    {
        self::initiator($pdo,$actor,true);self::ready($pdo);
        if(array_diff(array_keys($input),['recipient_email','credits','reason','authorization_confirmed','request_id']))throw new CreditTransferException('Customers can transfer only their own credits; unsupported fields.');
        $email=$input['recipient_email']??null;
        if(!is_string($email)||strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new CreditTransferException('Enter the recipient account email.');
        $matches=self::query($pdo,"SELECT id FROM users WHERE LOWER(email)=? AND role<>'admin' AND is_active=1 AND email_verified_at IS NOT NULL",[strtolower(trim($email))])->fetchAll(PDO::FETCH_COLUMN);
        if(count($matches)!==1)throw new CreditTransferException('This recipient is not eligible for a transfer. Check their account email.',422);
        $reason=$input['reason']??'Customer-authorized credit transfer';
        if($reason==='')$reason='Customer-authorized credit transfer';
        return self::quote($pdo,$actor,['source_user_id'=>$actor,'destination_user_id'=>$matches[0],'credits'=>$input['credits']??null,'reason'=>$reason,'authorization_confirmed'=>$input['authorization_confirmed']??false,'request_id'=>$input['request_id']??null],true);
    }
    /** Callback sends a transactional code to the source's current verified email only. Never returns it. */
    public static function challenge(PDO $pdo,int $actor,string $id,callable $send): array
    {
        self::initiator($pdo,$actor,true);self::ready($pdo);self::quoteId($id);
        self::prepareSecurity($pdo,$actor);
        $pdo->beginTransaction();
        try{
            $guard=self::security($pdo,$actor);
            if((int)$guard['blocked_until']>time())throw new CreditTransferException('Too many incorrect codes. Try again in 15 minutes.',429);
            $q=self::query($pdo,"SELECT * FROM credit_transfer_quotes WHERE id=? AND actor_user_id=? AND source_user_id=? AND authorization_mode='customer'".self::lock($pdo),[$id,$actor,$actor])->fetch(PDO::FETCH_ASSOC);
            if(!$q)throw new CreditTransferException('Transfer review not found.',404);
            if((int)$q['expires_at']<time()||self::query($pdo,'SELECT COUNT(*) FROM credit_transfers WHERE id=?',[$id])->fetchColumn())throw new CreditTransferException('This review expired or is already completed. Check its status.',409);
            $u=self::initiator($pdo,$actor,true);
            if($u['email']!==$q['source_email'])throw new CreditTransferException('Your account email changed. Review again.',409);
            $count=(int)self::query($pdo,'SELECT COUNT(*) FROM credit_transfer_quotes WHERE actor_user_id=? AND code_requested_at>?',[$actor,time()-900])->fetchColumn();
            if($count>=3||!empty($q['code_requested_at']))throw new CreditTransferException('A code was already requested. Use that code, or wait for a fresh review; at most three codes per 15 minutes.',429);
            $code=(string)random_int(100000,999999);
            self::query($pdo,'UPDATE credit_transfer_quotes SET code_hash=?,code_expires_at=?,code_requested_at=? WHERE id=?',[password_hash($code,PASSWORD_DEFAULT),min(time()+600,(int)$q['expires_at']),time(),$id]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        // Never hold wallet or database locks while waiting on SMTP. A failed/uncertain
        // mail request does not transfer funds or regenerate the same code.
        $result=$send($u['email'],$code,(int)$q['credits'],$q['destination_email'],$id);
        if(!is_array($result)||empty($result['success']))throw new CreditTransferException('The verification email could not be confirmed. No credits moved. Check your inbox before reviewing again after expiry.',503);
        return ['code_requested'=>true,'expires_at'=>min(time()+600,(int)$q['expires_at'])];
    }
}
