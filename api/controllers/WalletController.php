<?php
/**
 * Wallet Controller
 */

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
            'payment_method' => 'required|in:payfast,paystack,ozow,eft',
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
        $reference = 'SMS' . time() . rand(1000, 9999);
        
        // Create pending transaction
        $transactionId = table('wallet_transactions')->insert([
            'wallet_id' => $wallet['id'],
            'amount' => $amount,
            'type' => 'credit',
            'description' => "Credit purchase via {$data['payment_method']}",
            'reference' => $reference,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $paymentUrl = null;
        $bankDetails = null;
        
        switch ($data['payment_method']) {
            case 'payfast':
                $paymentUrl = $this->generatePayFastUrl($amount, $reference);
                break;
            case 'paystack':
                $paymentUrl = $this->generatePaystackUrl($amount, $reference);
                break;
            case 'ozow':
                $paymentUrl = $this->generateOzowUrl($amount, $reference);
                break;
            case 'eft':
                $bankDetails = [
                    'bank_name' => env('EFT_BANK_NAME', 'First National Bank'),
                    'account_name' => env('EFT_ACCOUNT_NAME', 'IEOSUIA PTY LTD'),
                    'account_number' => env('EFT_ACCOUNT_NUMBER', '62000000000'),
                    'branch_code' => env('EFT_BRANCH_CODE', '250655'),
                    'reference' => $reference,
                ];
                break;
        }
        
        Response::success([
            'transaction_id' => $transactionId,
            'reference' => $reference,
            'amount' => $amount,
            'payment_url' => $paymentUrl,
            'bank_details' => $bankDetails,
        ]);
    }
    
    private function generatePayFastUrl(float $amount, string $reference): string {
        $merchantId = env('PAYFAST_MERCHANT_ID');
        $merchantKey = env('PAYFAST_MERCHANT_KEY');
        $sandbox = env('PAYFAST_SANDBOX', true);
        
        $user = Auth::user();
        
        $data = [
            'merchant_id' => $merchantId,
            'merchant_key' => $merchantKey,
            'return_url' => env('FRONTEND_URL') . '/payment/success?reference=' . $reference,
            'cancel_url' => env('FRONTEND_URL') . '/payment/failed?cancelled=1&reference=' . $reference,
            'notify_url' => env('APP_URL') . '/payments/payfast/itn',
            'name_first' => explode(' ', $user['name'])[0],
            'email_address' => $user['email'],
            'amount' => number_format($amount, 2, '.', ''),
            'm_payment_id' => $reference,
            'item_name' => 'SMS Credits',
        ];
        
        // Generate signature
        $signatureString = '';
        foreach ($data as $key => $val) {
            $signatureString .= $key . '=' . urlencode($val) . '&';
        }
        $signatureString = rtrim($signatureString, '&');
        
        if ($passphrase = env('PAYFAST_PASSPHRASE')) {
            $signatureString .= '&passphrase=' . urlencode($passphrase);
        }
        
        $data['signature'] = md5($signatureString);
        
        $baseUrl = $sandbox ? 'https://sandbox.payfast.co.za/eng/process' : 'https://www.payfast.co.za/eng/process';
        
        return $baseUrl . '?' . http_build_query($data);
    }
    
    private function generateOzowUrl(float $amount, string $reference): string {
        $siteCode = env('OZOW_SITE_CODE');
        $privateKey = env('OZOW_PRIVATE_KEY');
        $sandbox = env('OZOW_SANDBOX', true);
        
        $data = [
            'SiteCode' => $siteCode,
            'CountryCode' => 'ZA',
            'CurrencyCode' => 'ZAR',
            'Amount' => number_format($amount, 2, '.', ''),
            'TransactionReference' => $reference,
            'BankReference' => $reference,
            'Optional1' => '',
            'Optional2' => '',
            'Optional3' => '',
            'Optional4' => '',
            'Optional5' => '',
            'Customer' => Auth::user()['email'],
            'CancelUrl' => env('FRONTEND_URL') . '/payment/failed?cancelled=1&reference=' . $reference,
            'ErrorUrl' => env('FRONTEND_URL') . '/payment/failed?reference=' . $reference,
            'SuccessUrl' => env('FRONTEND_URL') . '/payment/success?reference=' . $reference,
            'NotifyUrl' => env('APP_URL') . '/payments/ozow/notify',
            'IsTest' => $sandbox ? 'true' : 'false',
        ];
        
        $hashString = implode('', array_values($data)) . $privateKey;
        $data['HashCheck'] = strtolower(hash('sha512', strtolower($hashString)));
        
        $baseUrl = $sandbox ? 'https://pay.ozow.com' : 'https://pay.ozow.com';
        
        return $baseUrl . '?' . http_build_query($data);
    }
    
    /**
     * Generate Paystack payment URL
     */
    private function generatePaystackUrl(float $amount, string $reference): string {
        $publicKey = env('PAYSTACK_PUBLIC_KEY');
        $secretKey = env('PAYSTACK_SECRET_KEY');
        
        $user = Auth::user();
        
        // Initialize transaction via Paystack API
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.paystack.co/transaction/initialize',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'email' => $user['email'],
                'amount' => (int) ($amount * 100),
                'reference' => $reference,
                'callback_url' => env('FRONTEND_URL') . '/payment/success?reference=' . $reference,
                'metadata' => [
                    'user_id' => $user['id'],
                    'user_name' => $user['name'],
                    'cancel_action' => env('FRONTEND_URL') . '/payment/failed?cancelled=1&reference=' . $reference,
                ],
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/json',
            ],
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if ($result && $result['status'] && isset($result['data']['authorization_url'])) {
            return $result['data']['authorization_url'];
        }
        
        // Fallback to manual URL construction
        return 'https://paystack.com/pay/' . $reference;
    }
}
