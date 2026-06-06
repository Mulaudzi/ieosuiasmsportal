<?php
/**
 * Wallet Controller
 */

require_once __DIR__ . '/../services/PayOSService.php';

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
        
        Response::success([
            'wallet' => [
                'id' => $wallet['id'],
                'balance' => (float) $wallet['balance'],
                'reserved' => (float) $wallet['reserved'],
                'available' => (float) $wallet['balance'] - (float) $wallet['reserved'],
                'currency' => $wallet['currency'],
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
        
        // Calculate used this month
        $startOfMonth = date('Y-m-01 00:00:00');
        $usedThisMonth = table('wallet_transactions')
            ->where('wallet_id', $wallet['id'])
            ->where('type', 'debit')
            ->where('created_at', '>=', $startOfMonth)
            ->sum('amount');
        
        // Calculate total spent (all time debits)
        $totalSpent = table('wallet_transactions')
            ->where('wallet_id', $wallet['id'])
            ->where('type', 'debit')
            ->where('status', 'completed')
            ->sum('amount');
        
        Response::success([
            'balance' => (float) $wallet['balance'],
            'used_this_month' => abs((float) $usedThisMonth),
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
            ];
        }, $payments);
        
        Response::paginate($formattedPayments, $total, $page, $perPage);
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
    
    /**
     * Get available credit packages
     */
    public function packages(): void {
        // Return hardcoded packages for now
        // Can be made dynamic from database later
        $packages = [
            [
                'id' => 1,
                'credits' => 1000,
                'price' => 270,
                'currency' => 'ZAR',
                'price_per_credit' => 0.27,
                'popular' => false,
            ],
            [
                'id' => 2,
                'credits' => 5000,
                'price' => 1350,
                'currency' => 'ZAR',
                'price_per_credit' => 0.27,
                'popular' => true,
            ],
            [
                'id' => 3,
                'credits' => 10000,
                'price' => 2700,
                'currency' => 'ZAR',
                'price_per_credit' => 0.27,
                'popular' => false,
            ],
            [
                'id' => 4,
                'credits' => 25000,
                'price' => 6750,
                'currency' => 'ZAR',
                'price_per_credit' => 0.27,
                'popular' => false,
            ],
        ];
        
        Response::success(['packages' => $packages]);
    }
    
    public function buy(): void {
        $data = Request::validate([
            'amount' => 'required|numeric|min:10',
            'payment_method' => 'required|in:payos,eft',
            'requested_credits' => 'numeric|min:1',
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
        $requestedCredits = isset($data['requested_credits']) ? (int) $data['requested_credits'] : null;
        $requestedCredits = $requestedCredits !== null && $requestedCredits > 0 ? $requestedCredits : null;
        $reference = 'WALLET-SMS-' . date('YmdHis') . '-' . rand(100, 999);
        $hostedMethod = $data['payment_method'] === 'eft' ? 'eft' : 'payos';
        
        // Create pending transaction
        $transactionData = [
            'wallet_id' => $wallet['id'],
            'amount' => $amount,
            'type' => 'credit',
            'description' => "Credit purchase via {$hostedMethod}",
            'reference' => $reference,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($requestedCredits !== null && $this->hasColumn('wallet_transactions', 'requested_credits')) {
            $transactionData['requested_credits'] = $requestedCredits;
        }

        if ($this->hasColumn('wallet_transactions', 'checkout_initiated_at')) {
            $transactionData['checkout_initiated_at'] = date('Y-m-d H:i:s');
        }

        $transactionId = table('wallet_transactions')->insert($transactionData);
        
        $paymentUrl = null;
        $bankDetails = null;
        
        if ($hostedMethod === 'eft') {
            $bankDetails = [
                'bank_name' => env('EFT_BANK_NAME', 'First National Bank'),
                'account_name' => env('EFT_ACCOUNT_NAME', 'IEOSUIA PTY LTD'),
                'account_number' => env('EFT_ACCOUNT_NUMBER', '62000000000'),
                'branch_code' => env('EFT_BRANCH_CODE', '250655'),
                'reference' => $reference,
            ];
        } else {
            $transaction = table('wallet_transactions')->where('id', $transactionId)->first();
            $response = $this->createPayOSCheckout($wallet, $transaction, $requestedCredits);
            $paymentUrl = $response['payment_url'];

            if (!empty($response['internal_reference']) && $this->hasColumn('wallet_transactions', 'payos_reference')) {
                table('wallet_transactions')->where('id', $transactionId)->update([
                    'payos_reference' => $response['internal_reference'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
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

    private function createPayOSCheckout(array $wallet, array $transaction, ?int $requestedCredits): array {
        $user = Auth::user();
        $account = table('accounts')->where('user_id', $user['id'])->first();
        $payOS = new PayOSService();
        $reference = $transaction['reference'];
        $requestedCredits = $requestedCredits ?? $this->getRequestedCreditsFromTransaction($transaction);

        $payload = [
            'tenant_identifier' => env('PAYOS_TENANT_IDENTIFIER', 'sms-ieosuia'),
            'external_order_id' => $reference,
            'amount' => round((float) $transaction['amount'], 2),
            'currency' => 'ZAR',
            'customer_name' => $user['name'] ?? 'Customer',
            'customer_email' => $user['email'] ?? null,
            'customer_phone' => $user['phone'] ?? null,
            'item_name' => 'SMS wallet top-up',
            'item_description' => 'Wallet top-up for prepaid SMS credits',
            'source_website' => env('FRONTEND_URL', 'https://sms.ieosuia.com'),
            'return_url' => env('PAYOS_RETURN_URL', env('FRONTEND_URL', 'https://sms.ieosuia.com') . '/payment/success') . '?reference=' . urlencode($reference),
            'cancel_url' => env('PAYOS_CANCEL_URL', env('FRONTEND_URL', 'https://sms.ieosuia.com') . '/payment/failed') . '?cancelled=1&reference=' . urlencode($reference),
            'metadata' => [
                'platform' => env('PAYOS_TENANT_IDENTIFIER', 'sms-ieosuia'),
                'wallet_reference' => $reference,
                'wallet_id' => (int) $wallet['id'],
                'user_id' => (int) $user['id'],
                'requested_credits' => $requestedCredits,
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

        return [
            'payment_url' => $payOS->getCheckoutUrl($response),
            'internal_reference' => $payOS->getInternalReference($response),
            'raw_response' => $response,
        ];
    }

    private function getRequestedCreditsFromTransaction(array $transaction): ?int {
        if (isset($transaction['requested_credits']) && is_numeric($transaction['requested_credits'])) {
            return (int) $transaction['requested_credits'];
        }

        return null;
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
}
