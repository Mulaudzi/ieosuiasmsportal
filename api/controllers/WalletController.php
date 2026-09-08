<?php
/**
 * Wallet Controller
 */

require_once __DIR__ . '/../services/PayOSService.php';
require_once __DIR__ . '/../domain/SmsPricing.php';

class WalletController {
    public function index(): void {
        $wallet = table('wallets')->where('user_id', Auth::id())->first();
        
        if (!$wallet) {
            // Create wallet if doesn't exist
            $walletId = table('wallets')->insert([
                'user_id' => Auth::id(),
                'balance' => 0,
                'reserved' => 0,
                'currency' => 'ZAR',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $wallet = table('wallets')->where('id', $walletId)->first();
        }
        
        $pricePerCredit = SmsPricing::pricePerSegment();
        Response::success([
            'wallet' => [
                'id' => $wallet['id'],
                'balance' => (float) $wallet['balance'],
                'reserved' => (float) $wallet['reserved'],
                'available' => (float) $wallet['balance'] - (float) $wallet['reserved'],
                'currency' => $wallet['currency'],
                'sms_credits' => max(0, (int) floor((((float) $wallet['balance'] - (float) $wallet['reserved']) + 0.000001) / $pricePerCredit)),
                'price_per_credit' => $pricePerCredit,
            ]
        ]);
    }
    
    public function stats(): void {
        $userId = Auth::id();
        $wallet = table('wallets')->where('user_id', $userId)->first();
        
        if (!$wallet) {
            // Create wallet if doesn't exist
            $walletId = table('wallets')->insert([
                'user_id' => $userId,
                'balance' => 0,
                'reserved' => 0,
                'currency' => 'ZAR',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $wallet = table('wallets')->where('id', $walletId)->first();
        }
        
        // Credits are billable SMS segments, sourced from the canonical message ledger.
        $startOfMonth = date('Y-m-01 00:00:00');
        $usedStatement=db()->prepare('SELECT COALESCE(SUM(segment_count),0) FROM sms_messages WHERE user_id=? AND actual_charge>0 AND sent_at>=?');$usedStatement->execute([$userId,$startOfMonth]);$usedThisMonth=(int)$usedStatement->fetchColumn();
        
        // Calculate total spent (all time debits)
        $totalSpent = table('wallet_transactions')
            ->where('wallet_id', $wallet['id'])
            ->where('type', 'debit')
            ->where('status', 'completed')
            ->sum('amount');
        
        $pricePerCredit = SmsPricing::pricePerSegment();
        Response::success([
            'balance' => (float) $wallet['balance'],
            'sms_credits' => max(0, (int) floor((((float) $wallet['balance'] - (float) ($wallet['reserved'] ?? 0)) + 0.000001) / $pricePerCredit)),
            'price_per_credit' => $pricePerCredit,
            'used_this_month' => $usedThisMonth,
            'total_spent' => abs((float) $totalSpent),
        ]);
    }
    
    public function transactions(): void {
        $wallet = table('wallets')->where('user_id', Auth::id())->first();
        
        if (!$wallet) {
            Response::error('Wallet not found', 404);
        }
        
        $page = (int) Request::query('page', 1);
        $perPage = (int) Request::query('per_page', 20);
        
        $total = table('wallet_transactions')
            ->where('wallet_id', $wallet['id'])
            ->count();
        
        $transactions = table('wallet_transactions')
            ->where('wallet_id', $wallet['id'])
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();
        
        Response::paginate($transactions, $total, $page, $perPage);
    }
    
    /**
     * Get payment history from payments table
     */
    public function payments(): void {
        $userId = Auth::id();
        
        $page = (int) Request::query('page', 1);
        $perPage = (int) Request::query('per_page', 20);
        $status = Request::query('status');
        $gateway = Request::query('gateway');
        
        $query = table('payments')->where('user_id', $userId);
        
        if ($status) {
            $query->where('status', $status);
        }
        
        if ($gateway) {
            $query->where('gateway', $gateway);
        }
        
        $total = $query->count();
        
        $payments = table('payments')
            ->where('user_id', $userId);
            
        if ($status) {
            $payments->where('status', $status);
        }
        
        if ($gateway) {
            $payments->where('gateway', $gateway);
        }
        
        $payments = $payments
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();
        
        // Format payments for response
        $formattedPayments = array_map(function($payment) {
            $receipt = null;
            if ($this->hasTable('payment_receipt_outbox')) $receipt = table('payment_receipt_outbox')->where('payment_id', $payment['id'])->first();
            return [
                'id' => $payment['id'],
                'gateway' => $payment['gateway'],
                'gateway_reference' => $payment['gateway_reference'],
                'merchant_reference' => $payment['merchant_reference'],
                'amount' => (float) $payment['amount'],
                'currency' => $payment['currency'],
                'status' => $payment['status'],
                'gateway_status' => $payment['gateway_status'],
                'payment_method' => $payment['payment_method'],
                'credits_added' => (int) $payment['credits_added'],
                'created_at' => $payment['created_at'],
                'processed_at' => $payment['processed_at'],
                'error_message' => $payment['error_message'],
                'receipt_email_status' => $receipt['status'] ?? null,
                'receipt_email_sent_at' => $receipt['sent_at'] ?? null,
            ];
        }, $payments);
        
        Response::paginate($formattedPayments, $total, $page, $perPage);
    }

    public function exportPayments(): void {
        $userId=(int)Auth::id();
        $status=trim((string)Request::query('status',''));
        $gateway=trim((string)Request::query('gateway',''));
        $allowedStatuses=['pending','completed','failed','cancelled','refunded'];
        $allowedGateways=['payos','eft'];
        if($status!==''&&!in_array($status,$allowedStatuses,true))Response::error('Invalid payment status',422);
        if($gateway!==''&&!in_array($gateway,$allowedGateways,true))Response::error('Invalid payment gateway',422);

        $sql='SELECT gateway,gateway_reference,merchant_reference,amount,currency,status,gateway_status,payment_method,created_at,processed_at,error_message FROM payments WHERE user_id=?';
        $args=[$userId];
        if($status!==''){$sql.=' AND status=?';$args[]=$status;}
        if($gateway!==''){$sql.=' AND gateway=?';$args[]=$gateway;}
        $sql.=' ORDER BY created_at DESC';
        $stmt=db()->prepare($sql);$stmt->execute($args);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="payment-history-'.date('Y-m-d').'.csv"');
        $out=fopen('php://output','w');
        fputcsv($out,['Gateway','Gateway Reference','Merchant Reference','Amount','Currency','Status','Gateway Status','Payment Method','Created At','Processed At','Error']);
        $safe=static fn($value)=>is_string($value)&&preg_match('/^[=+\-@]/',$value)?"'".$value:$value;
        while($row=$stmt->fetch(PDO::FETCH_NUM))fputcsv($out,array_map($safe,$row));
        fclose($out);exit;
    }
    
    /**
     * Generate PDF receipt for a payment
     */
    public function receipt(): void {
        $paymentId = (int) Request::query('id');
        
        if (!$paymentId) {
            Response::error('Payment ID is required', 400);
        }
        
        $userId = Auth::id();
        
        $payment = table('payments')
            ->where('id', $paymentId)
            ->where('user_id', $userId)
            ->first();
        
        if (!$payment) {
            Response::error('Payment not found', 404);
        }
        
        if ($payment['status'] !== 'completed') {
            Response::error('Receipt only available for completed payments', 400);
        }
        
        $user = Auth::user();
        
        require_once __DIR__ . '/../services/PdfReceiptService.php';
        
        $html = PdfReceiptService::generateReceipt($payment, $user);
        
        // Return HTML for browser rendering/printing
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: inline; filename="receipt-' . $payment['merchant_reference'] . '.html"');
        echo $html;
        exit;
    }
    
    public function buy(): void {
        $data = Request::validate([
            'amount' => 'required|numeric|min:10',
            'payment_method' => 'required|in:payos,eft',
        ]);
        
        $wallet = table('wallets')->where('user_id', Auth::id())->first();
        
        if (!$wallet) {
            // Create wallet if doesn't exist
            $walletId = table('wallets')->insert([
                'user_id' => Auth::id(),
                'balance' => 0,
                'reserved' => 0,
                'currency' => 'ZAR',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $wallet = table('wallets')->where('id', $walletId)->first();
        }
        
        $amount = (float) $data['amount'];
        $reference = 'WALLET-SMS-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(8)));
        $hostedMethod = $data['payment_method'] === 'eft' ? 'eft' : 'payos';
        
        // Create pending transaction
        $transactionData = [
            'wallet_id' => $wallet['id'],
            'amount' => $amount,
            'type' => 'credit',
            'description' => "SMS credit purchase via {$hostedMethod}",
            'reference' => $reference,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->hasColumn('wallet_transactions', 'checkout_initiated_at')) {
            $transactionData['checkout_initiated_at'] = date('Y-m-d H:i:s');
        }

        $transactionId = table('wallet_transactions')->insert($transactionData);
        
        $paymentUrl = null;
        $bankDetails = null;
        
        if ($hostedMethod === 'eft') {
            $bankDetails = [
                'bank_name' => Config::required('EFT_BANK_NAME'),
                'account_name' => Config::required('EFT_ACCOUNT_NAME'),
                'account_number' => Config::required('EFT_ACCOUNT_NUMBER'),
                'branch_code' => Config::required('EFT_BRANCH_CODE'),
                'reference' => $reference,
            ];
        } else {
            try {
                $transaction = table('wallet_transactions')->where('id', $transactionId)->first();
                $response = $this->createPayOSCheckout($wallet, $transaction);
                $paymentUrl = $response['payment_url'];

                if (!empty($response['internal_reference']) && $this->hasColumn('wallet_transactions', 'payos_reference')) {
                    table('wallet_transactions')->where('id', $transactionId)->update([
                        'payos_reference' => $response['internal_reference'],
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            } catch (Throwable $e) {
                table('wallet_transactions')->where('id',$transactionId)->update([
                    'status'=>'failed',
                    'updated_at'=>date('Y-m-d H:i:s'),
                ]);
                error_log('PayOS checkout creation failed for ' . $reference . ': ' . $e->getMessage());
                Response::error('PayOS could not start the checkout. Please verify the PayOS tenant credentials and try again.', 502);
            }
        }
        
        Response::success([
            'transaction_id' => $transactionId,
            'reference' => $reference,
            'amount' => $amount,
            'payment_url' => $paymentUrl,
            'bank_details' => $bankDetails,
            'payment_method' => $hostedMethod,
        ]);
    }

    public function paymentStatus(): void {
        $reference = Request::query('reference');

        if (!$reference) {
            Response::error('Reference is required', 400);
        }

        $wallet = table('wallets')->where('user_id', Auth::id())->first();
        if (!$wallet) {
            Response::error('Wallet not found', 404);
        }

        $transaction = table('wallet_transactions')
            ->where('wallet_id', $wallet['id'])
            ->where('reference', $reference)
            ->first();

        if (!$transaction) {
            Response::error('Transaction not found', 404);
        }

        $payment = table('payments')
            ->where('transaction_id', $transaction['id'])
            ->orderBy('created_at', 'DESC')
            ->first();

        Response::success([
            'reference' => $reference,
            'transaction_status' => $transaction['status'],
            'payment_status' => $payment['status'] ?? null,
            'gateway_status' => $payment['gateway_status'] ?? null,
            'gateway_reference' => $payment['gateway_reference'] ?? null,
        ]);
    }

    private function createPayOSCheckout(array $wallet, array $transaction): array {
        $user = Auth::user();
        $account = table('accounts')->where('user_id', $user['id'])->first();
        $payOS = new PayOSService();
        $reference = $transaction['reference'];

        $payload = [
            'tenant_identifier' => env('PAYOS_TENANT_IDENTIFIER', 'ieosuia-sms-portal'),
            'external_order_id' => $reference,
            'amount' => round((float) $transaction['amount'], 2),
            'currency' => 'ZAR',
            'customer_name' => $user['name'] ?? 'Customer',
            'customer_email' => $user['email'] ?? null,
            'customer_phone' => $user['phone'] ?? null,
            'item_name' => 'SMS credit purchase',
            'item_description' => 'Prepaid billable SMS segment credits',
            'source_website' => env('FRONTEND_URL', 'https://sms.ieosuia.com'),
            'return_url' => env('PAYOS_RETURN_URL', env('FRONTEND_URL', 'https://sms.ieosuia.com') . '/payment/success') . '?reference=' . urlencode($reference),
            'cancel_url' => env('PAYOS_CANCEL_URL', env('FRONTEND_URL', 'https://sms.ieosuia.com') . '/payment/failed') . '?cancelled=1&reference=' . urlencode($reference),
            'metadata' => [
                'platform' => env('PAYOS_TENANT_IDENTIFIER', 'ieosuia-sms-portal'),
                'wallet_reference' => $reference,
                'wallet_id' => (int) $wallet['id'],
                'user_id' => (int) $user['id'],
                'checkout_source' => 'web',
                'account_id' => $account['id'] ?? null,
                'company_name' => $account['company_name'] ?? null,
            ],
        ];

        $branding = [
            'store_name' => env('PAYOS_STORE_NAME', env('APP_NAME', 'IEOSUIA SMS Portal')),
            'brand_color' => env('PAYOS_BRAND_COLOR', '#0f425b'),
        ];

        $logoUrl = env('PAYOS_LOGO_URL', '');
        if ($logoUrl !== '') {
            $branding['logo_url'] = $logoUrl;
        }

        $payload['branding'] = $branding;

        $response = $payOS->createCheckout($payload);
        $paymentUrl = $payOS->getCheckoutUrl($response);
        if ($paymentUrl === null) {
            throw new RuntimeException('PayOS response did not contain a checkout URL');
        }

        return [
            'payment_url' => $paymentUrl,
            'internal_reference' => $payOS->getInternalReference($response),
            'raw_response' => $response,
        ];
    }

    private function hasColumn(string $table, string $column): bool {
        static $cache = [];
        $key = $table . '.' . $column;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $sql = 'SELECT COUNT(*) AS count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?';
        $stmt = db()->prepare($sql);
        $stmt->execute([env('DB_DATABASE'), $table, $column]);

        $cache[$key] = (int) ($stmt->fetch()['count'] ?? 0) > 0;
        return $cache[$key];
    }

    private function hasTable(string $table): bool {
        static $cache=[];
        if(array_key_exists($table,$cache))return $cache[$table];
        $stmt=db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
        $stmt->execute([env('DB_DATABASE'),$table]);
        return $cache[$table]=(int)$stmt->fetchColumn()>0;
    }
}
