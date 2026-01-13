<?php
/**
 * Payment Webhook Controller
 * Handles PayFast, Paystack, and Ozow payment notifications
 */

class PaymentWebhookController {
    
    /**
     * PayFast ITN (Instant Transaction Notification) Handler
     */
    public function payfastItn(): void {
        // Log incoming request
        error_log("PayFast ITN received: " . json_encode($_POST));
        
        // Get POST data
        $data = $_POST;
        
        // Validate required fields
        $requiredFields = ['m_payment_id', 'payment_status', 'amount_gross', 'pf_payment_id'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                error_log("PayFast ITN: Missing field $field");
                http_response_code(400);
                echo "Missing field: $field";
                return;
            }
        }
        
        $reference = $data['m_payment_id'];
        $status = $data['payment_status'];
        $amount = (float) $data['amount_gross'];
        $payfastPaymentId = $data['pf_payment_id'];
        
        // Verify signature
        if (!$this->verifyPayfastSignature($data)) {
            error_log("PayFast ITN: Invalid signature for $reference");
            http_response_code(403);
            echo "Invalid signature";
            return;
        }
        
        // Find the pending transaction
        $transaction = table('wallet_transactions')
            ->where('reference', $reference)
            ->where('status', 'pending')
            ->first();
        
        if (!$transaction) {
            error_log("PayFast ITN: Transaction not found for $reference");
            http_response_code(404);
            echo "Transaction not found";
            return;
        }
        
        $wallet = table('wallets')->where('id', $transaction['wallet_id'])->first();
        
        if (!$wallet) {
            error_log("PayFast ITN: Wallet not found for transaction $reference");
            http_response_code(404);
            echo "Wallet not found";
            return;
        }
        
        // Create payment record
        $paymentId = table('payments')->insert([
            'user_id' => $wallet['user_id'],
            'wallet_id' => $wallet['id'],
            'transaction_id' => $transaction['id'],
            'gateway' => 'payfast',
            'gateway_reference' => $payfastPaymentId,
            'merchant_reference' => $reference,
            'amount' => $amount,
            'currency' => 'ZAR',
            'status' => 'pending',
            'gateway_status' => $status,
            'payment_method' => $data['payment_method'] ?? 'card',
            'payer_email' => $data['email_address'] ?? null,
            'payer_name' => ($data['name_first'] ?? '') . ' ' . ($data['name_last'] ?? ''),
            'metadata' => json_encode($data),
            'webhook_received_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Process based on status
        if ($status === 'COMPLETE') {
            $this->processSuccessfulPayment($transaction, $wallet, $amount, $paymentId);
            echo "OK";
        } elseif (in_array($status, ['FAILED', 'CANCELLED'])) {
            $this->processFailedPayment($transaction, $paymentId, $status);
            echo "OK";
        } else {
            // Pending or other status - just log
            error_log("PayFast ITN: Status $status for $reference");
            echo "OK";
        }
    }
    
    /**
     * Paystack Webhook Handler
     */
    public function paystackWebhook(): void {
        // Get the raw POST body
        $input = file_get_contents('php://input');
        error_log("Paystack webhook received: " . $input);
        
        $data = json_decode($input, true);
        
        if (!$data || !isset($data['event'])) {
            http_response_code(400);
            echo "Invalid payload";
            return;
        }
        
        // Verify signature
        $paystackSignature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
        $secretKey = env('PAYSTACK_SECRET_KEY');
        
        if (!$this->verifyPaystackSignature($input, $paystackSignature, $secretKey)) {
            error_log("Paystack webhook: Invalid signature");
            http_response_code(403);
            echo "Invalid signature";
            return;
        }
        
        $event = $data['event'];
        $paymentData = $data['data'];
        
        if ($event === 'charge.success') {
            $reference = $paymentData['reference'];
            $amount = (float) $paymentData['amount'] / 100; // Paystack sends amount in kobo/cents
            $paystackReference = $paymentData['id'];
            
            // Find the pending transaction
            $transaction = table('wallet_transactions')
                ->where('reference', $reference)
                ->where('status', 'pending')
                ->first();
            
            if (!$transaction) {
                error_log("Paystack webhook: Transaction not found for $reference");
                http_response_code(200);
                echo "Transaction not found";
                return;
            }
            
            $wallet = table('wallets')->where('id', $transaction['wallet_id'])->first();
            
            // Create payment record
            $paymentId = table('payments')->insert([
                'user_id' => $wallet['user_id'],
                'wallet_id' => $wallet['id'],
                'transaction_id' => $transaction['id'],
                'gateway' => 'paystack',
                'gateway_reference' => $paystackReference,
                'merchant_reference' => $reference,
                'amount' => $amount,
                'currency' => $paymentData['currency'] ?? 'ZAR',
                'status' => 'pending',
                'gateway_status' => $paymentData['status'],
                'payment_method' => $paymentData['channel'] ?? 'card',
                'payer_email' => $paymentData['customer']['email'] ?? null,
                'payer_name' => $paymentData['customer']['first_name'] ?? null,
                'metadata' => json_encode($paymentData),
                'webhook_received_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            $this->processSuccessfulPayment($transaction, $wallet, $amount, $paymentId);
        }
        
        http_response_code(200);
        echo "OK";
    }
    
    /**
     * Ozow Notification Handler
     */
    public function ozowNotify(): void {
        error_log("Ozow notification received: " . json_encode($_POST));
        
        $data = $_POST;
        
        $transactionId = $data['TransactionId'] ?? null;
        $transactionReference = $data['TransactionReference'] ?? null;
        $amount = (float) ($data['Amount'] ?? 0);
        $status = $data['Status'] ?? '';
        $statusMessage = $data['StatusMessage'] ?? '';
        
        if (!$transactionReference) {
            http_response_code(400);
            echo "Missing transaction reference";
            return;
        }
        
        // Verify hash
        if (!$this->verifyOzowHash($data)) {
            error_log("Ozow notification: Invalid hash for $transactionReference");
            http_response_code(403);
            echo "Invalid hash";
            return;
        }
        
        // Find the pending transaction
        $transaction = table('wallet_transactions')
            ->where('reference', $transactionReference)
            ->where('status', 'pending')
            ->first();
        
        if (!$transaction) {
            error_log("Ozow notification: Transaction not found for $transactionReference");
            http_response_code(200);
            echo "Transaction not found";
            return;
        }
        
        $wallet = table('wallets')->where('id', $transaction['wallet_id'])->first();
        
        // Create payment record
        $paymentId = table('payments')->insert([
            'user_id' => $wallet['user_id'],
            'wallet_id' => $wallet['id'],
            'transaction_id' => $transaction['id'],
            'gateway' => 'ozow',
            'gateway_reference' => $transactionId,
            'merchant_reference' => $transactionReference,
            'amount' => $amount,
            'currency' => 'ZAR',
            'status' => 'pending',
            'gateway_status' => $status,
            'payment_method' => 'eft',
            'metadata' => json_encode($data),
            'webhook_received_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        if ($status === 'Complete') {
            $this->processSuccessfulPayment($transaction, $wallet, $amount, $paymentId);
        } elseif (in_array($status, ['Error', 'Cancelled', 'Abandoned'])) {
            $this->processFailedPayment($transaction, $paymentId, $status, $statusMessage);
        }
        
        http_response_code(200);
        echo "OK";
    }
    
    /**
     * Process a successful payment
     */
    private function processSuccessfulPayment(array $transaction, array $wallet, float $amount, int $paymentId): void {
        $pdo = db();
        
        try {
            $pdo->beginTransaction();
            
            // Calculate credits (R0.27 per SMS)
            $pricePerCredit = (float) env('SMS_PRICE_PER_CREDIT', 0.27);
            $credits = (int) floor($amount / $pricePerCredit);
            
            // Update wallet balance
            table('wallets')->where('id', $wallet['id'])->update([
                'balance' => (float) $wallet['balance'] + $credits,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            // Update transaction status
            table('wallet_transactions')->where('id', $transaction['id'])->update([
                'status' => 'completed',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            // Update payment record
            table('payments')->where('id', $paymentId)->update([
                'status' => 'completed',
                'credits_added' => $credits,
                'processed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            $pdo->commit();
            
            error_log("Payment processed successfully: {$transaction['reference']} - $credits credits added");
            
        } catch (\Exception $e) {
            $pdo->rollBack();
            error_log("Payment processing error: " . $e->getMessage());
            
            table('payments')->where('id', $paymentId)->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
    
    /**
     * Process a failed payment
     */
    private function processFailedPayment(array $transaction, int $paymentId, string $status, ?string $errorMessage = null): void {
        table('wallet_transactions')->where('id', $transaction['id'])->update([
            'status' => 'failed',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        table('payments')->where('id', $paymentId)->update([
            'status' => 'failed',
            'error_message' => $errorMessage ?? "Payment $status",
            'processed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        error_log("Payment failed: {$transaction['reference']} - $status");
    }
    
    /**
     * Verify PayFast signature
     */
    private function verifyPayfastSignature(array $data): bool {
        $passphrase = env('PAYFAST_PASSPHRASE', '');
        
        // Remove signature from data
        $signature = $data['signature'] ?? '';
        unset($data['signature']);
        
        // Build string
        $signatureString = '';
        foreach ($data as $key => $val) {
            if ($val !== '') {
                $signatureString .= $key . '=' . urlencode(stripslashes($val)) . '&';
            }
        }
        $signatureString = rtrim($signatureString, '&');
        
        if ($passphrase) {
            $signatureString .= '&passphrase=' . urlencode($passphrase);
        }
        
        $calculatedSignature = md5($signatureString);
        
        return $calculatedSignature === $signature;
    }
    
    /**
     * Verify Paystack signature
     */
    private function verifyPaystackSignature(string $input, string $signature, string $secretKey): bool {
        $calculatedSignature = hash_hmac('sha512', $input, $secretKey);
        return hash_equals($calculatedSignature, $signature);
    }
    
    /**
     * Verify Ozow hash
     */
    private function verifyOzowHash(array $data): bool {
        $privateKey = env('OZOW_PRIVATE_KEY', '');
        
        $hash = $data['Hash'] ?? '';
        unset($data['Hash']);
        
        // Ozow hash is calculated from specific fields
        $hashFields = [
            'SiteCode', 'TransactionId', 'TransactionReference', 'Amount',
            'Status', 'Optional1', 'Optional2', 'Optional3', 'Optional4', 'Optional5',
            'CurrencyCode', 'IsTest', 'StatusMessage'
        ];
        
        $hashString = '';
        foreach ($hashFields as $field) {
            $hashString .= ($data[$field] ?? '');
        }
        $hashString .= $privateKey;
        
        $calculatedHash = strtolower(hash('sha512', strtolower($hashString)));
        
        return $calculatedHash === strtolower($hash);
    }
}
