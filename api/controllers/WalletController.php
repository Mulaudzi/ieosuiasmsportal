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
            'return_url' => env('FRONTEND_URL') . '/wallet?success=1',
            'cancel_url' => env('FRONTEND_URL') . '/wallet?cancelled=1',
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
            'CancelUrl' => env('FRONTEND_URL') . '/wallet?cancelled=1',
            'ErrorUrl' => env('FRONTEND_URL') . '/wallet?error=1',
            'SuccessUrl' => env('FRONTEND_URL') . '/wallet?success=1',
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
                'amount' => (int) ($amount * 100), // Paystack uses kobo/cents
                'reference' => $reference,
                'callback_url' => env('FRONTEND_URL') . '/wallet?success=1',
                'metadata' => [
                    'user_id' => $user['id'],
                    'user_name' => $user['name'],
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
