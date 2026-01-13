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
        error_log("PayFast ITN received: " . json_encode($_POST));
        
        $data = $_POST;
        
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
        
        if (!$this->verifyPayfastSignature($data)) {
            error_log("PayFast ITN: Invalid signature for $reference");
            http_response_code(403);
            echo "Invalid signature";
            return;
        }
        
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
        
        if ($status === 'COMPLETE') {
            $this->processSuccessfulPayment($transaction, $wallet, $amount, $paymentId);
            echo "OK";
        } elseif (in_array($status, ['FAILED', 'CANCELLED'])) {
            $this->processFailedPayment($transaction, $paymentId, $status);
            echo "OK";
        } else {
            error_log("PayFast ITN: Status $status for $reference");
            echo "OK";
        }
    }
    
    /**
     * PayFast Subscription ITN Handler
     */
    public function payfastSubscriptionItn(): void {
        error_log("PayFast Subscription ITN received: " . json_encode($_POST));
        
        $data = $_POST;
        
        if (!$this->verifyPayfastSignature($data)) {
            error_log("PayFast Subscription ITN: Invalid signature");
            http_response_code(403);
            echo "Invalid signature";
            return;
        }
        
        $subscriptionType = $data['item_name'] ?? '';
        $tokenValue = $data['token'] ?? null;
        $billingDate = $data['billing_date'] ?? null;
        $amount = (float) ($data['amount_gross'] ?? 0);
        $status = $data['payment_status'] ?? '';
        $email = $data['email_address'] ?? null;
        
        if (!$email) {
            http_response_code(400);
            echo "Missing email";
            return;
        }
        
        $user = table('users')->where('email', $email)->first();
        if (!$user) {
            error_log("PayFast Subscription: User not found for email $email");
            http_response_code(200);
            echo "User not found";
            return;
        }
        
        // Handle subscription status changes
        if ($status === 'COMPLETE') {
            // Recurring payment successful
            $wallet = table('wallets')->where('user_id', $user['id'])->first();
            if ($wallet) {
                $pricePerCredit = (float) env('SMS_PRICE_PER_CREDIT', 0.27);
                $credits = (int) floor($amount / $pricePerCredit);
                
                table('wallets')->where('id', $wallet['id'])->update([
                    'balance' => (float) $wallet['balance'] + $credits,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                
                table('payments')->insert([
                    'user_id' => $user['id'],
                    'wallet_id' => $wallet['id'],
                    'gateway' => 'payfast',
                    'gateway_reference' => $tokenValue,
                    'merchant_reference' => 'SUB-' . time(),
                    'amount' => $amount,
                    'currency' => 'ZAR',
                    'status' => 'completed',
                    'gateway_status' => $status,
                    'payment_method' => 'subscription',
                    'payer_email' => $email,
                    'credits_added' => $credits,
                    'metadata' => json_encode($data),
                    'webhook_received_at' => date('Y-m-d H:i:s'),
                    'processed_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                
                $this->sendSubscriptionEmail($user, 'renewed', $subscriptionType, $amount);
            }
            
            table('audit_logs')->insert([
                'user_id' => $user['id'],
                'action' => 'subscription_renewed',
                'entity_type' => 'subscription',
                'details' => json_encode(['gateway' => 'payfast', 'amount' => $amount, 'token' => $tokenValue]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } elseif ($status === 'CANCELLED') {
            table('audit_logs')->insert([
                'user_id' => $user['id'],
                'action' => 'subscription_cancelled',
                'entity_type' => 'subscription',
                'details' => json_encode(['gateway' => 'payfast', 'token' => $tokenValue]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            
            $this->sendSubscriptionEmail($user, 'cancelled', $subscriptionType, 0);
        } elseif ($status === 'FAILED') {
            table('audit_logs')->insert([
                'user_id' => $user['id'],
                'action' => 'subscription_payment_failed',
                'entity_type' => 'subscription',
                'details' => json_encode(['gateway' => 'payfast', 'amount' => $amount]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            
            $this->sendSubscriptionEmail($user, 'payment_failed', $subscriptionType, $amount);
        }
        
        http_response_code(200);
        echo "OK";
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
        
        // Handle one-time payments
        if ($event === 'charge.success') {
            $this->handlePaystackChargeSuccess($paymentData);
        }
        
        // Handle subscription events
        if ($event === 'subscription.create') {
            $this->handlePaystackSubscriptionCreate($paymentData);
        }
        
        if ($event === 'subscription.disable' || $event === 'subscription.not_renew') {
            $this->handlePaystackSubscriptionCancel($paymentData);
        }
        
        if ($event === 'invoice.payment_failed') {
            $this->handlePaystackInvoiceFailed($paymentData);
        }
        
        if ($event === 'invoice.create' || $event === 'invoice.update') {
            $this->handlePaystackInvoice($paymentData, $event);
        }
        
        http_response_code(200);
        echo "OK";
    }
    
    /**
     * Handle Paystack charge success
     */
    private function handlePaystackChargeSuccess(array $paymentData): void {
        $reference = $paymentData['reference'];
        $amount = (float) $paymentData['amount'] / 100;
        $paystackReference = $paymentData['id'];
        
        $transaction = table('wallet_transactions')
            ->where('reference', $reference)
            ->where('status', 'pending')
            ->first();
        
        if (!$transaction) {
            error_log("Paystack webhook: Transaction not found for $reference");
            return;
        }
        
        $wallet = table('wallets')->where('id', $transaction['wallet_id'])->first();
        
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
    
    /**
     * Handle Paystack subscription creation
     */
    private function handlePaystackSubscriptionCreate(array $data): void {
        error_log("Paystack subscription created: " . json_encode($data));
        
        $customerEmail = $data['customer']['email'] ?? null;
        $subscriptionCode = $data['subscription_code'] ?? null;
        $planCode = $data['plan']['plan_code'] ?? null;
        $amount = (float) ($data['amount'] ?? 0) / 100;
        
        if (!$customerEmail) return;
        
        $user = table('users')->where('email', $customerEmail)->first();
        if (!$user) return;
        
        // Log subscription event
        table('audit_logs')->insert([
            'user_id' => $user['id'],
            'action' => 'subscription_created',
            'entity_type' => 'subscription',
            'entity_id' => null,
            'details' => json_encode([
                'gateway' => 'paystack',
                'subscription_code' => $subscriptionCode,
                'plan_code' => $planCode,
                'amount' => $amount,
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Send notification email
        $this->sendSubscriptionEmail($user, 'created', $planCode, $amount);
    }
    
    /**
     * Handle Paystack subscription cancellation
     */
    private function handlePaystackSubscriptionCancel(array $data): void {
        error_log("Paystack subscription cancelled: " . json_encode($data));
        
        $customerEmail = $data['customer']['email'] ?? null;
        $subscriptionCode = $data['subscription_code'] ?? null;
        
        if (!$customerEmail) return;
        
        $user = table('users')->where('email', $customerEmail)->first();
        if (!$user) return;
        
        table('audit_logs')->insert([
            'user_id' => $user['id'],
            'action' => 'subscription_cancelled',
            'entity_type' => 'subscription',
            'entity_id' => null,
            'details' => json_encode([
                'gateway' => 'paystack',
                'subscription_code' => $subscriptionCode,
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        $this->sendSubscriptionEmail($user, 'cancelled', null, 0);
    }
    
    /**
     * Handle Paystack invoice payment failed
     */
    private function handlePaystackInvoiceFailed(array $data): void {
        error_log("Paystack invoice failed: " . json_encode($data));
        
        $customerEmail = $data['customer']['email'] ?? null;
        $amount = (float) ($data['amount'] ?? 0) / 100;
        
        if (!$customerEmail) return;
        
        $user = table('users')->where('email', $customerEmail)->first();
        if (!$user) return;
        
        table('audit_logs')->insert([
            'user_id' => $user['id'],
            'action' => 'subscription_payment_failed',
            'entity_type' => 'subscription',
            'entity_id' => null,
            'details' => json_encode([
                'gateway' => 'paystack',
                'amount' => $amount,
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        $this->sendSubscriptionEmail($user, 'payment_failed', null, $amount);
    }
    
    /**
     * Handle Paystack invoice events
     */
    private function handlePaystackInvoice(array $data, string $event): void {
        error_log("Paystack invoice event $event: " . json_encode($data));
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
            
            // Send payment confirmation email
            $this->sendPaymentConfirmationEmail($wallet['user_id'], $amount, $credits, $transaction['reference']);
            
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
     * Send payment confirmation email to user
     */
    private function sendPaymentConfirmationEmail(int $userId, float $amount, int $credits, string $reference): void {
        try {
            $user = table('users')->where('id', $userId)->first();
            
            if (!$user || empty($user['email'])) {
                error_log("Cannot send payment email: User $userId not found or has no email");
                return;
            }
            
            require_once __DIR__ . '/../services/EmailService.php';
            
            $result = EmailService::sendPaymentConfirmationEmail(
                $user['email'],
                $user['name'] ?? 'Customer',
                $amount,
                $credits,
                $reference
            );
            
            if ($result['success']) {
                error_log("Payment confirmation email sent to: {$user['email']}");
            } else {
                error_log("Failed to send payment confirmation email: " . ($result['error'] ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            error_log("Error sending payment confirmation email: " . $e->getMessage());
        }
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
    
    /**
     * Send subscription-related email notifications
     */
    private function sendSubscriptionEmail(array $user, string $type, ?string $planName, float $amount): void {
        try {
            require_once __DIR__ . '/../services/EmailService.php';
            
            $email = $user['email'] ?? null;
            $name = $user['name'] ?? 'Customer';
            
            if (!$email) return;
            
            $subjects = [
                'created' => 'Subscription Activated - ' . env('COMPANY_NAME', 'IEOSUIA SMS Portal'),
                'renewed' => 'Subscription Renewed - ' . env('COMPANY_NAME', 'IEOSUIA SMS Portal'),
                'cancelled' => 'Subscription Cancelled - ' . env('COMPANY_NAME', 'IEOSUIA SMS Portal'),
                'payment_failed' => 'Subscription Payment Failed - Action Required',
            ];
            
            $bodies = [
                'created' => "Hi $name,\n\nYour subscription has been successfully activated.\n\nPlan: $planName\nAmount: R" . number_format($amount, 2) . "\n\nThank you for subscribing!",
                'renewed' => "Hi $name,\n\nYour subscription has been successfully renewed.\n\nPlan: $planName\nAmount: R" . number_format($amount, 2) . "\n\nCredits have been added to your account.",
                'cancelled' => "Hi $name,\n\nYour subscription has been cancelled.\n\nYou will continue to have access until the end of your current billing period.\n\nWe're sorry to see you go. If you change your mind, you can resubscribe at any time.",
                'payment_failed' => "Hi $name,\n\nWe were unable to process your subscription payment of R" . number_format($amount, 2) . ".\n\nPlease update your payment method to avoid service interruption.\n\nIf you need assistance, please contact support.",
            ];
            
            $subject = $subjects[$type] ?? 'Subscription Update';
            $body = $bodies[$type] ?? "Hi $name,\n\nYour subscription has been updated.";
            
            EmailService::sendRawEmail($email, $subject, $body);
            
            error_log("Subscription email sent to $email: $type");
        } catch (\Exception $e) {
            error_log("Failed to send subscription email: " . $e->getMessage());
        }
    }
}
