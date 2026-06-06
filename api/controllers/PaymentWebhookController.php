<?php
/**
 * Payment Webhook Controller
 * Handles PayOS payment notifications
 */

require_once __DIR__ . '/../services/PayOSService.php';

class PaymentWebhookController {
    public function payosCallback(): void {
        $rawBody = file_get_contents('php://input') ?: '';
        $signature = $_SERVER['HTTP_X_PAYOS_SIGNATURE'] ?? '';
        $event = $_SERVER['HTTP_X_PAYOS_EVENT'] ?? '';
        $payOS = new PayOSService();

        if (!$payOS->verifyCallbackSignature($rawBody, $signature)) {
            Response::error('Invalid PayOS signature', 403);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            Response::error('Invalid payload', 400);
        }

        $externalOrderId = $payload['external_order_id'] ?? null;
        if (!$externalOrderId) {
            Response::error('Missing external_order_id', 400);
        }

        $transaction = table('wallet_transactions')->where('reference', $externalOrderId)->first();
        if (!$transaction) {
            Response::error('Transaction not found', 404);
        }

        $wallet = table('wallets')->where('id', $transaction['wallet_id'])->first();
        if (!$wallet) {
            Response::error('Wallet not found', 404);
        }

        $internalReference = $payOS->getInternalReference($payload);
        if ($internalReference && $this->hasColumn('wallet_transactions', 'payos_reference')) {
            table('wallet_transactions')->where('id', $transaction['id'])->update([
                'payos_reference' => $internalReference,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $amount = (float) ($payload['amount'] ?? $transaction['amount']);
        $paymentStatus = $payOS->mapStatus($payload['status'] ?? '');
        $paymentId = $this->upsertPayOSPayment($transaction, $wallet, $payload, $paymentStatus, $amount, $internalReference, $event);

        if ($paymentStatus === 'completed') {
            if (($transaction['status'] ?? null) !== 'completed') {
                $requestedCredits = $this->extractRequestedCredits($transaction, $payload);
                $this->processSuccessfulPayment($transaction, $wallet, $amount, $paymentId, $requestedCredits);
            } else {
                table('payments')->where('id', $paymentId)->update([
                    'status' => 'completed',
                    'processed_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } elseif ($paymentStatus === 'pending') {
            table('payments')->where('id', $paymentId)->update([
                'status' => 'pending',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } elseif ($paymentStatus === 'cancelled') {
            $this->updateFailedPaymentState($transaction, $paymentId, 'cancelled', 'Payment cancelled');
        } elseif ($paymentStatus === 'refunded') {
            table('payments')->where('id', $paymentId)->update([
                'status' => 'refunded',
                'processed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $this->updateFailedPaymentState($transaction, $paymentId, 'failed', 'Payment failed');
        }

        Response::success([
            'received' => true,
            'reference' => $externalOrderId,
            'status' => $paymentStatus,
        ]);
    }
    
    /**
     * Process a successful payment
     */
    private function processSuccessfulPayment(array $transaction, array $wallet, float $amount, int $paymentId, ?int $requestedCredits = null): void {
        $pdo = db();
        
        try {
            $pdo->beginTransaction();
            
            $credits = $this->resolveCreditsToAdd($transaction, $amount, $requestedCredits);
            
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
        $this->updateFailedPaymentState($transaction, $paymentId, 'failed', $errorMessage ?? "Payment $status");
        error_log("Payment failed: {$transaction['reference']} - $status");
    }

    private function updateFailedPaymentState(array $transaction, int $paymentId, string $paymentStatus, ?string $errorMessage = null): void {
        table('wallet_transactions')->where('id', $transaction['id'])->update([
            'status' => 'failed',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        table('payments')->where('id', $paymentId)->update([
            'status' => $paymentStatus,
            'error_message' => $errorMessage,
            'processed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function resolveCreditsToAdd(array $transaction, float $amount, ?int $requestedCredits = null): int {
        if ($requestedCredits !== null && $requestedCredits > 0) {
            return $requestedCredits;
        }

        if (isset($transaction['requested_credits']) && is_numeric($transaction['requested_credits']) && (int) $transaction['requested_credits'] > 0) {
            return (int) $transaction['requested_credits'];
        }

        $pricePerCredit = (float) env('SMS_PRICE_PER_CREDIT', 0.27);
        return (int) floor($amount / $pricePerCredit);
    }

    private function extractRequestedCredits(array $transaction, array $payload): ?int {
        if (isset($payload['metadata']['requested_credits']) && is_numeric($payload['metadata']['requested_credits'])) {
            return (int) $payload['metadata']['requested_credits'];
        }

        if (isset($transaction['requested_credits']) && is_numeric($transaction['requested_credits'])) {
            return (int) $transaction['requested_credits'];
        }

        return null;
    }

    private function upsertPayOSPayment(array $transaction, array $wallet, array $payload, string $paymentStatus, float $amount, ?string $internalReference, string $event): int {
        $existing = null;

        if ($internalReference) {
            $existing = table('payments')
                ->where('gateway', 'payos')
                ->where('gateway_reference', $internalReference)
                ->first();
        }

        if (!$existing) {
            $existing = table('payments')
                ->where('gateway', 'payos')
                ->where('merchant_reference', $transaction['reference'])
                ->first();
        }

        $paymentData = [
            'user_id' => $wallet['user_id'],
            'wallet_id' => $wallet['id'],
            'transaction_id' => $transaction['id'],
            'gateway' => 'payos',
            'gateway_reference' => $internalReference,
            'merchant_reference' => $transaction['reference'],
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'ZAR',
            'status' => $paymentStatus,
            'gateway_status' => $payload['status'] ?? null,
            'payment_method' => 'hosted_checkout',
            'payer_email' => $payload['customer_email'] ?? ($payload['metadata']['customer_email'] ?? null),
            'payer_name' => $payload['customer_name'] ?? null,
            'metadata' => json_encode([
                'event' => $event,
                'payload' => $payload,
            ]),
            'webhook_received_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            table('payments')->where('id', $existing['id'])->update($paymentData);
            return (int) $existing['id'];
        }

        $paymentData['created_at'] = date('Y-m-d H:i:s');
        return table('payments')->insert($paymentData);
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
    
}
