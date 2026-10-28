<?php

require_once __DIR__.'/../domain/CreditPurchasePricing.php';
require_once __DIR__.'/../domain/CreditPurchaseSettlement.php';
require_once __DIR__.'/../domain/CustomerCashBalance.php';
require_once __DIR__.'/../domain/SmsPricing.php';
require_once __DIR__.'/../domain/PaymentProofFile.php';
require_once __DIR__.'/../domain/CreditApprovalSecurity.php';
require_once __DIR__.'/../services/EmailService.php';
require_once __DIR__.'/../services/PaymentReceiptService.php';
require_once __DIR__.'/../services/CreditPurchaseMailer.php';

final class CreditPurchaseController
{
    private const PROOF_EMAIL = 'sms@ieosuia.com';

    public static function bankDetails(): ?array
    {
        // Same account used in IEOSUIA's quote/payment banking documents.
        // Legacy EFT_* values may be placeholders; only use explicitly configured overrides.
        if (strtolower((string)env('SMS_BANK_TRANSFER_ENABLED','true'))!=='true') return null;
        if (strtolower((string)env('SMS_BANK_TRANSFER_USE_ENV','false'))!=='true') {
            $path=__DIR__.'/../config/bank-transfer.json';
            if (!is_readable($path)) return null;
            $bank=json_decode(file_get_contents($path),true);
            if (!is_array($bank)) return null;
            foreach (['bank_name','account_name','account_number','branch_code'] as $field) if (!is_string($bank[$field]??null) || trim($bank[$field])==='') return null;
            return $bank;
        }
        $bank=[];
        foreach (['bank_name'=>'EFT_BANK_NAME','account_name'=>'EFT_ACCOUNT_NAME','account_number'=>'EFT_ACCOUNT_NUMBER','branch_code'=>'EFT_BRANCH_CODE'] as $field=>$key) {
            $value=trim((string)env($key,''));
            if ($value==='') return null;
            $bank[$field]=$value;
        }
        if (!preg_match('/\bFNB\b|First\s*National\s*Bank/i',$bank['bank_name'])) return null;
        return $bank;
    }

    public function options(): void
    {
        header('Cache-Control: no-store');
        Response::success(['rules'=>CreditPurchasePricing::rules(),
            'purchases_available'=>$this->schemaReady(),
            'direct_transfer_available'=>$this->schemaReady() && self::bankDetails()!==null,
            'cash_balance'=>CustomerCashBalance::snapshot(db(),(int)Auth::id()),'proof_email'=>self::PROOF_EMAIL]);
    }

    /** Public payment instructions only: no account, order or database access. */
    public function publicBanking(): void
    {
        header('Cache-Control: no-store');
        $bank=self::bankDetails();
        $public=$bank?array_intersect_key($bank,array_flip(['bank_name','account_name','account_number','account_type','branch_code','swift_code'])):null;
        Response::success(['available'=>$public!==null,'bank_details'=>$public,'proof_email'=>self::PROOF_EMAIL,'sales_email'=>CreditPurchasePricing::rules()['sales_email'],'reference_prefix'=>'SMS-']);
    }

    private function ready(): void
    {
        if (!$this->schemaReady()) Response::error('Credit purchases are being updated. Please try again shortly.',503);
    }

    private function schemaReady(): bool
    {
        $pdo=db();
        $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ? "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('credit_purchase_orders','credit_payment_proofs','credit_purchase_mail_outbox')"
            : "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('credit_purchase_orders','credit_payment_proofs','credit_purchase_mail_outbox')";
        return (int)$pdo->query($sql)->fetchColumn()===3;
    }

    public function quote():void {
        $this->ready();$balance=CustomerCashBalance::snapshot(db(),(int)Auth::id());
        try {CreditPurchasePricing::quote(Request::input('credits'),(string)Request::input('payment_method'));$quote=CustomerCashBalance::quote((int)Request::input('credits'),(string)Request::input('payment_method'),(string)Request::input('balance_choice','keep_credits'),$balance['available_cents']);}
        catch(InvalidArgumentException $e){Response::error($e->getMessage(),422);}
        Response::success(['quote'=>$quote,'cash_balance'=>$balance]);
    }

    public function enableCashBalances():void {
        $this->admin();$this->ready();$pdo=db();
        try {CreditApprovalSecurity::verify($pdo,(int)Auth::id(),Request::input('master_password'));}
        catch(CreditApprovalException $e){Response::error($e->getMessage(),$e->status);}
        $lock=$pdo->prepare('SELECT GET_LOCK(?,5)');$lock->execute(['ieosuia-cash-balance-schema-017']);
        if ((int)$lock->fetchColumn()!==1) Response::error('Finance setup is already running. Try again shortly.',409);
        try {
            // Deliberately do not run the general migration runner: unrelated saved
            // drafts and historical migrations are outside this release.
            $migration=require __DIR__.'/../migrations/017_customer_cash_balances.php';$migration($pdo);
            if (!CustomerCashBalance::available($pdo)) throw new RuntimeException('Cash ledger schema unavailable');
            $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations(version VARCHAR(100) PRIMARY KEY,applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->prepare("INSERT INTO schema_migrations(version,applied_at) VALUES('017_customer_cash_balances',NOW()) ON DUPLICATE KEY UPDATE version=VALUES(version)")->execute();
            CreditApprovalSecurity::event($pdo,(int)Auth::id(),'cash_balances_enabled',null,0);
        } catch(Throwable $e){error_log('Cash balance schema setup unavailable');Response::error('Finance setup could not complete. Existing payments and SMS credits were not changed. Contact support.',503);}
        finally {$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['ieosuia-cash-balance-schema-017']);}
        Response::success(['message'=>'Rand balances enabled. No historical payments or SMS credits were repriced.']);
    }

    private function assertRepeatedPurchase(PDO $pdo,array $order,int $requested,string $method,string $choice):void {
        $allocation=null;
        if (CustomerCashBalance::available($pdo)) {$q=$pdo->prepare('SELECT requested_credits,choice FROM credit_cash_allocations WHERE order_id=?');$q->execute([$order['id']]);$allocation=$q->fetch(PDO::FETCH_ASSOC);}
        if ((int)($allocation['requested_credits']??$order['credits'])!==$requested || $order['payment_method']!==$method || ($allocation && $allocation['choice']!==$choice)) throw new DomainException('This request key belongs to a different purchase.');
    }

    public function buy(): void
    {
        $this->ready();
        if (Request::input('credits')===null) Response::error('Credit pricing has been updated. Refresh this page and choose the number of credits to buy.',409);
        $method=Request::input('payment_method','');
        if (!is_string($method)) Response::error('Choose a supported payment method.',422);
        try { $quote=CreditPurchasePricing::quote(Request::input('credits'),$method); }
        catch (InvalidArgumentException $e) { Response::error($e->getMessage(),422); }
        if (!is_int(Request::input('quoted_total_cents')) || Request::input('pricing_version')!==$quote['pricing_version']) {
            Response::error('Credit pricing has changed. Refresh this page, then review the total before paying.',409);
        }
        $key=Request::input('idempotency_key','');
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$key)) Response::error('A valid purchase request key is required.',422);
        $userId=(int)Auth::id();$pdo=db();
        $existing=$pdo->prepare('SELECT * FROM credit_purchase_orders WHERE user_id=? AND idempotency_key=?');
        $existing->execute([$userId,$key]);$order=$existing->fetch(PDO::FETCH_ASSOC);
        $requestedCredits=$quote['credits'];$choice=Request::input('balance_choice','keep_credits');
        if (!is_string($choice) || !in_array($choice,['keep_credits','keep_payment'],true)) Response::error('Choose how to use your cash balance.',422);
        if ($order) {try {$this->assertRepeatedPurchase($pdo,$order,$requestedCredits,$method,$choice);}catch(DomainException $e){Response::error($e->getMessage(),409);}}
        if (!$order) {
            $bank=$quote['payment_method']==='eft'?self::bankDetails():null;
            if ($quote['payment_method']==='eft' && !$bank) Response::error('Direct bank transfers are currently unavailable. Contact sales at '.CreditPurchasePricing::rules()['sales_email'].' for assistance.',503);
            try {
                $pdo->beginTransaction();
                // Serialize purchase creation for this account, including repeated requests.
                $q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$userId]);
                $existing->execute([$userId,$key]);$order=$existing->fetch(PDO::FETCH_ASSOC);
                if ($order) $this->assertRepeatedPurchase($pdo,$order,$requestedCredits,$method,$choice);
                if (!$order) {
                    $balance=CustomerCashBalance::snapshot($pdo,$userId);
                    if ($balance['available_cents']!==0 && Request::input('balance_choice')===null) throw new DomainException('Review your cash balance and choose whether to keep your SMS quantity or payment budget.');
                    $quote=CustomerCashBalance::quote($requestedCredits,$method,$choice,$balance['available_cents']);
                    if ($balance['available_cents']!==0 && (Request::input('quoted_credits')!==$quote['credits'] || Request::input('quoted_cash_applied_cents')!==$quote['cash_applied_cents'])) throw new DomainException('Review the SMS quantity and cash adjustment before confirming this order.');
                    if (Request::input('quoted_total_cents')!==$quote['total_cents'] || (Request::input('quoted_credits')!==null && Request::input('quoted_credits')!==$quote['credits']) || (Request::input('quoted_cash_applied_cents')!==null && Request::input('quoted_cash_applied_cents')!==$quote['cash_applied_cents'])) throw new DomainException('Your price or cash balance changed. Refresh the quote and review it before paying.');
                    $wallet=table('wallets')->where('user_id',$userId)->first();
                    if (!$wallet) {
                        $id=table('wallets')->insert(['user_id'=>$userId,'balance'=>0,'reserved'=>0,'currency'=>'ZAR','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
                        $wallet=table('wallets')->where('id',$id)->first();
                    }
                    $reference='SMS-'.strtoupper(bin2hex(random_bytes(8)));
                    $amount=$quote['total_cents']/100;$now=date('Y-m-d H:i:s');
                    $transactionId=table('wallet_transactions')->insert(['wallet_id'=>$wallet['id'],'amount'=>$amount,'type'=>'credit','description'=>'Purchase of '.$quote['credits'].' SMS credits','reference'=>$reference,'status'=>'pending','created_at'=>$now,'updated_at'=>$now]);
                    $paymentId=table('payments')->insert(['user_id'=>$userId,'wallet_id'=>$wallet['id'],'transaction_id'=>$transactionId,'gateway'=>$quote['payment_method'],'merchant_reference'=>$reference,'amount'=>$amount,'currency'=>'ZAR','status'=>'pending','payment_method'=>$quote['payment_method']==='eft'?'bank_transfer':'hosted_checkout','credits_added'=>0,'created_at'=>$now,'updated_at'=>$now]);
                    $q=$pdo->prepare('INSERT INTO credit_purchase_orders(user_id,payment_id,reference,idempotency_key,pricing_version,credits,unit_cents,subtotal_cents,discount_cents,total_cents,wallet_amount,payment_method,bank_details_json,expires_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $q->execute([$userId,$paymentId,$reference,$key,$quote['pricing_version'],$quote['credits'],$quote['unit_cents'],$quote['subtotal_cents'],$quote['discount_cents'],$quote['total_cents'],round($quote['credits']*SmsPricing::pricePerSegment(),4),$quote['payment_method'],$bank?json_encode($bank):null,date('Y-m-d H:i:s',time()+72*3600),$now,$now]);
                    $existing->execute([$userId,$key]);$order=$existing->fetch(PDO::FETCH_ASSOC);
                    if ($balance['enabled']) CustomerCashBalance::reserve($pdo,(int)$order['id'],$userId,$quote);
                    if ($order['payment_method']==='eft') $this->sendInstructions($order);
                }
                $pdo->commit();
            } catch (DomainException|InvalidArgumentException $e) {if($pdo->inTransaction())$pdo->rollBack();Response::error($e->getMessage(),409);}
            catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack();throw $e; }
        }
        try {$this->assertRepeatedPurchase($pdo,$order,$requestedCredits,$method,$choice);}catch(DomainException $e){Response::error($e->getMessage(),409);}
        if ($order['status']==='awaiting_payment' && $order['payment_method']==='payos' && !$order['checkout_url']) {
            if (strtotime($order['expires_at'])<time()) Response::error('This checkout has expired. Create a new purchase.',409);
            $payment=table('payments')->where('id',$order['payment_id'])->first();
            $wallet=table('wallets')->where('id',$payment['wallet_id'])->first();
            $transaction=table('wallet_transactions')->where('id',$payment['transaction_id'])->first();
            require_once __DIR__.'/WalletController.php';
            try {
                $checkout=(new WalletController())->createPayOSCheckout($wallet,$transaction,$order);
                $pdo->prepare('UPDATE credit_purchase_orders SET checkout_url=?,updated_at=NOW() WHERE id=?')->execute([$checkout['payment_url'],$order['id']]);
                $order['checkout_url']=$checkout['payment_url'];
            } catch (Throwable $e) {
                error_log('Credit checkout could not be created for '.$order['reference']);
                Response::error('Online checkout could not start. Your order is saved; retry this purchase without paying twice.',502);
            }
        }
        Response::success(['order'=>$this->present($order),'reference'=>$order['reference'],'payment_url'=>$order['checkout_url'],'instructions_email_status'=>$order['payment_method']==='eft'?'queued':null]);
    }

    private function present(array $order, bool $admin = false): array
    {
        if (CustomerCashBalance::available(db())) {$q=db()->prepare('SELECT choice,applied_cents,sms_total_cents,payment_cents,received_cents,status FROM credit_cash_allocations WHERE order_id=?');$q->execute([$order['id']]);$order['cash_adjustment']=$q->fetch(PDO::FETCH_ASSOC)?:null;}
        unset($order['idempotency_key'],$order['wallet_amount']);
        if (!$admin) unset($order['bank_reference'],$order['reviewer_name']);
        $order['bank_details']=$order['bank_details_json']?json_decode($order['bank_details_json'],true):null;
        unset($order['bank_details_json']);
        $order['proof_email']=self::PROOF_EMAIL;
        $order['expired']=strtotime($order['expires_at'])<time();
        $q=db()->prepare('SELECT id,mime_type,byte_size,created_at FROM credit_payment_proofs WHERE order_id=? ORDER BY id DESC');$q->execute([$order['id']]);$order['proofs']=$q->fetchAll(PDO::FETCH_ASSOC);
        return $order;
    }

    public function orders(): void
    {
        $this->ready();header('Cache-Control: no-store');
        $admin=Request::query('review')==='1';
        if ($admin) $this->admin();
        $page=max(1,(int)Request::query('page',1));$offset=($page-1)*20;
        $where=$admin?"o.payment_method='eft'":"o.user_id=? AND o.payment_method='eft'";
        $args=$admin?[]:[Auth::id()];
        if ($admin) {
            $status=Request::query('status','pending');$search=Request::query('search','');
            if (!is_string($search) || strlen($search)>200 || !is_string($status) || !in_array($status,['pending','completed','rejected','voided','all'],true)) Response::error('Choose a valid review filter and search of up to 200 characters.',422);
            if ($status==='pending') $where.=" AND o.status IN ('awaiting_payment','awaiting_verification')";
            elseif ($status!=='all') { $where.=' AND o.status=?';$args[]=$status; }
            if (trim($search)!=='') { $where.=' AND (u.email LIKE ? OR u.name LIKE ? OR o.reference LIKE ?)';$term='%'.trim($search).'%';array_push($args,$term,$term,$term); }
        }
        $count=db()->prepare("SELECT COUNT(*) FROM credit_purchase_orders o JOIN users u ON u.id=o.user_id WHERE $where");$count->execute($args);
        $q=db()->prepare("SELECT o.*,u.email customer_email,u.name customer_name,rv.name reviewer_name FROM credit_purchase_orders o JOIN users u ON u.id=o.user_id LEFT JOIN users rv ON rv.id=o.reviewed_by WHERE $where ORDER BY o.id DESC LIMIT 20 OFFSET $offset");$q->execute($args);
        Response::success(['orders'=>array_map(fn($o)=>$this->present($o,$admin),$q->fetchAll(PDO::FETCH_ASSOC)),'total'=>(int)$count->fetchColumn(),'page'=>$page]);
    }

    public function uploadProof(array $params): void
    {
        $this->ready();$id=(int)$params['id'];$pdo=db();
        $q=$pdo->prepare("SELECT * FROM credit_purchase_orders WHERE id=? AND user_id=? AND payment_method='eft'");$q->execute([$id,Auth::id()]);$order=$q->fetch(PDO::FETCH_ASSOC);
        if (!$order) Response::error('Purchase not found.',404);
        $file=Request::file('proof');
        if (!$file || ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) Response::error('Choose a PDF, JPG or PNG proof of payment, up to 5 MB.',422);
        $size=filesize($file['tmp_name']);
        if ($size<1 || $size>5*1024*1024) Response::error('Proof of payment must be between 1 byte and 5 MB.',422);
        $bytes=file_get_contents($file['tmp_name']);$mime=PaymentProofFile::mime($bytes);
        if ($mime===null) Response::error('Choose a valid PDF, JPG or PNG document.',422);
        $hash=hash('sha256',$bytes);
        try {
            $pdo->beginTransaction();$q=$pdo->prepare('SELECT status FROM credit_purchase_orders WHERE id=? FOR UPDATE');$q->execute([$id]);
            if (!in_array($q->fetchColumn(),['awaiting_payment','awaiting_verification'],true)) throw new DomainException('This order is already closed.');
            $q=$pdo->prepare('SELECT id FROM credit_payment_proofs WHERE order_id=? AND sha256=?');$q->execute([$id,$hash]);
            if (!$q->fetchColumn()) {
                $q=$pdo->prepare('SELECT COUNT(*) FROM credit_payment_proofs WHERE order_id=?');$q->execute([$id]);
                if ((int)$q->fetchColumn()>=3) throw new DomainException('Three documents are already attached. Email any further documents to '.self::PROOF_EMAIL.'.');
                $pdo->prepare('INSERT INTO credit_payment_proofs(order_id,mime_type,byte_size,sha256,document,created_at) VALUES(?,?,?,?,?,NOW())')->execute([$id,$mime,$size,$hash,$bytes]);
            }
            $pdo->prepare("UPDATE credit_purchase_orders SET status='awaiting_verification',updated_at=NOW() WHERE id=?")->execute([$id]);
            CreditPurchaseMailer::enqueue($pdo,'proof:'.$id.':'.$hash,self::PROOF_EMAIL,'Proof of payment uploaded: '.$order['reference'],$this->instructionsHtml($order).'<p>A proof of payment is available in Platform Operations, Finance. Verify cleared funds before approving.</p>');
            $pdo->commit();
        } catch (DomainException $e) { if ($pdo->inTransaction()) $pdo->rollBack();Response::error($e->getMessage(),409); }
        catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack();throw $e; }
        $order['status']='awaiting_verification';
        Response::success(['order'=>$this->present($order),'message'=>'Proof received. Credits will be added after the payment clears and is verified.']);
    }

    public function proof(array $params): void
    {
        $this->ready();
        $q=db()->prepare('SELECT p.*,o.user_id FROM credit_payment_proofs p JOIN credit_purchase_orders o ON o.id=p.order_id WHERE p.id=?');$q->execute([(int)$params['id']]);$proof=$q->fetch(PDO::FETCH_ASSOC);
        if (!$proof || ((int)$proof['user_id']!==(int)Auth::id() && (Auth::user()['role']??'')!=='admin')) Response::error('Document not found.',404);
        $ext=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'][$proof['mime_type']];
        header('Content-Type: '.$proof['mime_type']);header('Content-Length: '.$proof['byte_size']);
        header('Content-Disposition: attachment; filename="payment-proof-'.$proof['id'].'.'.$ext.'"');
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");
        echo $proof['document'];exit;
    }

    private function admin(): void
    {
        if ((Auth::user()['role']??'')!=='admin') Response::error('Unauthorized.',403);
    }

    public function review(array $params): void
    {
        $this->admin();$this->ready();$pdo=db();$id=(int)$params['id'];
        $decision=Request::input('decision');$note=Request::input('note','');
        if (!is_string($note)) Response::error('Enter a review note.',422);
        $note=trim($note);
        if (!in_array($decision,['approve','reject','void'],true) || $note==='' || strlen($note)>1000) Response::error('Choose a decision and enter a review note of up to 1,000 characters.',422);
        if ($decision==='void' && Request::input('funds_not_received')!==true) Response::error('Confirm that you checked the bank account and no funds were received for this request.',422);
        $bankReference=Request::input('bank_reference','');$amount=Request::input('amount_received','');
        if (!is_string($bankReference) || (!is_string($amount) && !is_int($amount) && !is_float($amount))) Response::error('Enter the bank transaction reference and amount.',422);
        $bankReference=strtoupper(preg_replace('/\s+/','',trim($bankReference)));
        $amount=(string)$amount;
        if ($decision==='approve' && (Request::input('funds_verified')!==true || strlen($bankReference)<4 || strlen($bankReference)>120 || !preg_match('/^\d{1,9}(\.\d{1,2})?$/D',$amount))) Response::error('Confirm cleared funds, the exact amount received and the unique bank transaction reference.',422);
        $q=$pdo->prepare("SELECT o.*,p.transaction_id FROM credit_purchase_orders o JOIN payments p ON p.id=o.payment_id WHERE o.id=? AND o.payment_method='eft'");$q->execute([$id]);$order=$q->fetch(PDO::FETCH_ASSOC);
        if (!$order) Response::error('Bank transfer not found.',404);
        $securityVersion=null;
        if ($decision==='approve') {
            try { $securityVersion=CreditApprovalSecurity::verify($pdo,(int)Auth::id(),Request::input('master_password')); }
            catch (CreditApprovalException $e) { Response::error($e->getMessage(),$e->status); }
        }
        try {
            $pdo->beginTransaction();
            if ($securityVersion!==null) CreditApprovalSecurity::assertVersion($pdo,$securityVersion);
            $q=$pdo->prepare('SELECT * FROM wallet_transactions WHERE id=? FOR UPDATE');$q->execute([$order['transaction_id']]);$transaction=$q->fetch(PDO::FETCH_ASSOC);
            $q=$pdo->prepare('SELECT * FROM payments WHERE id=? FOR UPDATE');$q->execute([$order['payment_id']]);$payment=$q->fetch(PDO::FETCH_ASSOC);
            $order=CreditPurchaseSettlement::order($pdo,(int)$order['payment_id'],true);
            if (!$transaction || !$payment || !$order) throw new DomainException('Payment records are incomplete. Contact support before reviewing.');
            if ($order['status']==='completed' && $decision==='approve') { $pdo->commit();Response::success(['message'=>'This purchase was already credited.']); }
            if ($order['status']==='voided' && $decision==='void') { $pdo->commit();Response::success(['message'=>'This unpaid request was already voided. No credits were changed.']); }
            if (!in_array($order['status'],['awaiting_payment','awaiting_verification'],true)) throw new DomainException('This purchase has already been reviewed.');
            if ($decision==='void') {
                $ledger=$pdo->prepare('SELECT COUNT(*) FROM wallet_ledger WHERE payment_id=?');$ledger->execute([$payment['id']]);
                if ($payment['status']!=='pending' || $transaction['status']!=='pending' || $payment['gateway']!=='eft' || (int)$payment['credits_added']!==0 || !empty($order['bank_reference']) || (int)$ledger->fetchColumn()>0 || (int)$payment['user_id']!==(int)$order['user_id'] || $payment['merchant_reference']!==$order['reference'] || $transaction['reference']!==$order['reference'] || (int)$transaction['wallet_id']!==(int)$payment['wallet_id']) throw new DomainException('Only an unpaid, uncredited EFT request can be voided. Reconcile any received funds separately.');
            }
            if ($decision==='approve') {
                $receivedCents=(int)round((float)$amount*100);
                if ($receivedCents<1) throw new DomainException('Enter a positive cleared payment amount.');
                if ($receivedCents!==(int)$order['total_cents'] && Request::input('confirm_cash_difference')!==true) throw new DomainException('Confirm the displayed overpayment credit or shortfall before adding SMS credits.');
                $credits=CreditPurchaseSettlement::credit($pdo,$payment,(float)$amount,true);
                $pdo->prepare('UPDATE payments SET amount=? WHERE id=?')->execute([$receivedCents/100,$payment['id']]);
                $pdo->prepare('UPDATE wallet_transactions SET amount=? WHERE id=?')->execute([$receivedCents/100,$transaction['id']]);
                $pdo->prepare("UPDATE payments SET status='completed',credits_added=?,processed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$credits,$payment['id']]);
                $pdo->prepare("UPDATE wallet_transactions SET status='completed',updated_at=NOW() WHERE id=?")->execute([$transaction['id']]);
            } else {
                CustomerCashBalance::release($pdo,$id);
                $pdo->prepare('UPDATE credit_purchase_orders SET status=?,updated_at=NOW() WHERE id=?')->execute([$decision==='void'?'voided':'rejected',$id]);
                $pdo->prepare('UPDATE payments SET status=?,error_message=?,updated_at=NOW() WHERE id=?')->execute([$decision==='void'?'cancelled':'failed',$note,$payment['id']]);
                $pdo->prepare("UPDATE wallet_transactions SET status='failed',updated_at=NOW() WHERE id=?")->execute([$transaction['id']]);
            }
            $pdo->prepare('UPDATE credit_purchase_orders SET bank_reference=?,reviewed_by=?,review_note=?,reviewed_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$decision==='approve'?$bankReference:null,Auth::id(),$note,$id]);
            if (CreditApprovalSecurity::available($pdo)) CreditApprovalSecurity::event($pdo,(int)Auth::id(),match($decision){'approve'=>'payment_approved','void'=>'payment_voided',default=>'payment_rejected'},$id,$securityVersion??0);
            if ($decision==='approve') PaymentReceiptService::enqueue($pdo,(int)$payment['id'],(int)$payment['user_id']);
            else {
                $user=table('users')->where('id',$payment['user_id'])->first();
                $voided=$decision==='void';
                CreditPurchaseMailer::enqueue($pdo,'order:'.$id.':'.($voided?'voided':'rejected'),$user['email'],($voided?'SMS unpaid request voided: ':'SMS bank transfer needs attention: ').$order['reference'],'<p>Your transfer order '.htmlspecialchars($order['reference']).($voided?' was voided because no payment was confirmed. Do not pay against this closed request. No credits were added or removed.':' was not approved.').'</p><p>'.htmlspecialchars($note).'</p><p>'.($voided?'If you already paid, contact ':'Contact ').self::PROOF_EMAIL.' with your payment reference'.($voided?' and proof so we can reconcile the funds.':'.').'</p>');
            }
            $pdo->commit();
        } catch (CreditApprovalException $e) { if ($pdo->inTransaction()) $pdo->rollBack();Response::error($e->getMessage(),$e->status); }
        catch (DomainException $e) { if ($pdo->inTransaction()) $pdo->rollBack();Response::error($e->getMessage(),409); }
        catch (PDOException $e) { if ($pdo->inTransaction()) $pdo->rollBack();if ($e->getCode()==='23000') Response::error('This bank transaction has already been used for another purchase.',409);throw $e; }
        catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack();throw $e; }
        Response::success(['message'=>match($decision){'approve'=>'Payment verified and credits added.','void'=>'Unpaid EFT request voided. No credits were added or removed.',default=>'Purchase rejected. No credits added.'}]);
    }

    private function sendInstructions(array $order): void
    {
        $user=Auth::user();$html=$this->instructionsHtml($order);
        CreditPurchaseMailer::enqueue(db(),'order:'.$order['id'].':customer',$user['email'],'Your SMS bank transfer order: '.$order['reference'],$html);
        CreditPurchaseMailer::enqueue(db(),'order:'.$order['id'].':staff',self::PROOF_EMAIL,'New SMS bank transfer order: '.$order['reference'],$html.'<p>Customer: '.htmlspecialchars($user['email']).'</p>');
    }

    private function instructionsHtml(array $order): string
    {
        $esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
        $bank=json_decode($order['bank_details_json']??'{}',true)?:[];
        $rows=['Credits'=>number_format((int)$order['credits']),'Standard price'=>'R'.number_format($order['subtotal_cents']/100,2),'Bank transfer saving'=>'R'.number_format($order['discount_cents']/100,2),'Amount to transfer'=>'R'.number_format($order['total_cents']/100,2),'Payment reference'=>$order['reference'],'Bank'=>$bank['bank_name']??'','Account holder'=>$bank['account_name']??'','Account number'=>$bank['account_number']??'','Branch code'=>$bank['branch_code']??''];
        $html='<div style="background:#f3f6fb;padding:24px;font-family:Arial,sans-serif"><div style="max-width:600px;margin:auto;background:white;border-radius:12px;padding:28px"><h1 style="color:#143c66;font-size:24px">IEOSUIA SMS Portal</h1><h2>Your bank transfer order</h2><table style="width:100%;border-collapse:collapse">';
        foreach ($rows as $label=>$value) $html.='<tr><td style="padding:10px 0;border-bottom:1px solid #e5e7eb">'.$esc($label).'</td><td style="padding:10px 0;text-align:right;border-bottom:1px solid #e5e7eb"><strong>'.$esc($value).'</strong></td></tr>';
        return $html.'</table><p>We do not charge VAT.</p><p>Use the payment reference above. If your bank cannot accept it, use your account email address and include the order reference with your proof.</p><p>Upload your proof in Wallet or email it to <a href="mailto:'.self::PROOF_EMAIL.'">'.self::PROOF_EMAIL.'</a>. Select immediate payment in your banking app if you need faster clearance; your bank may charge a fee.</p><p>Pay within 72 hours. Credits are added after cleared funds have been verified. A proof of payment alone does not confirm clearance.</p><a href="'.htmlspecialchars(rtrim((string)env('FRONTEND_URL','https://sms.ieosuia.com'),'/')).'/wallet">Open your wallet</a></div></div>';
    }
}
