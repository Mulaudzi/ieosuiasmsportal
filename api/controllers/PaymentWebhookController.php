<?php
/**
 * Payment Webhook Controller
 * Handles PayOS payment notifications
 */

require_once __DIR__ . '/../services/PayOSService.php';
require_once __DIR__ . '/../domain/WalletService.php';
require_once __DIR__ . '/../domain/SmsPricing.php';
require_once __DIR__ . '/../domain/PaymentState.php';
require_once __DIR__ . '/../services/PaymentReceiptService.php';

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
        if (strtoupper((string)($payload['currency'] ?? 'ZAR')) !== 'ZAR') {
            Response::error('Unsupported payment currency', 422);
        }
        $paymentStatus = $payOS->mapStatus($payload['status'] ?? '');
        $paymentId = $this->upsertPayOSPayment($transaction, $wallet, $payload, $paymentStatus, $amount, $internalReference, $event);
        $storedPayment = table('payments')->where('id', $paymentId)->first();
        $paymentStatus = PaymentState::merge($storedPayment['status'] ?? null, $paymentStatus);

        if ($paymentStatus === 'completed') {
            if (($transaction['status'] ?? null) !== 'completed') {
                $this->processSuccessfulPayment($transaction, $wallet, $amount, $paymentId);
            } else {
                table('payments')->where('id', $paymentId)->update([
                    'status' => 'completed',
                    'processed_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            PaymentReceiptService::enqueue(db(),$paymentId,(int)$wallet['user_id']);
            $receiptEmail=PaymentReceiptService::dispatch($paymentId);
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
            'receipt_email_status' => $receiptEmail['status'] ?? null,
        ]);
    }
    
    /**
     * Process a successful payment
     */
    private function processSuccessfulPayment(array $transaction, array $wallet, float $amount, int $paymentId): void {
        $pdo = db();
        
        try {
            $pdo->beginTransaction();
            
            $lock = $pdo->prepare('SELECT * FROM wallet_transactions WHERE id = ? FOR UPDATE');
            $lock->execute([$transaction['id']]);
            $currentTransaction = $lock->fetch();
            if (!$currentTransaction || $currentTransaction['status'] === 'completed') {
                $pdo->commit();
                return;
            }
            if (abs((float)$currentTransaction['amount'] - $amount) > 0.0001) {
                throw new DomainException('Callback amount does not match the initiated transaction');
            }
            $paymentLock = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $paymentLock->execute([$paymentId]);
            $payment = $paymentLock->fetch();
            if (!$payment || abs((float)$payment['amount'] - $amount) > 0.0001 || (string)$payment['merchant_reference'] !== (string)$transaction['reference']) {
                throw new DomainException('Payment amount or reference mismatch');
            }

            // Canonical wallet units are currency amounts. Commercial SMS pricing
            // converts that balance into segments during campaign preview.
            WalletService::creditPayment($pdo, (int)$wallet['user_id'], $paymentId, $amount, (string)$transaction['reference']);
            
            // Update transaction status
            table('wallet_transactions')->where('id', $transaction['id'])->update([
                'status' => 'completed',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            // Update payment record
            table('payments')->where('id', $paymentId)->update([
                'status' => 'completed',
                'credits_added' => max(0, (int) floor(($amount + 0.000001) / SmsPricing::pricePerSegment())),
                'processed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            $pdo->commit();
            
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Payment processing error: " . $e->getMessage());
            
            table('payments')->where('id', $paymentId)->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            PaymentReceiptService::enqueue($pdo,$paymentId,(int)$wallet['user_id']);
            throw $e;
        }

        error_log("Payment processed successfully: {$transaction['reference']}");
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

        $effectiveStatus = PaymentState::merge($existing['status'] ?? null, $paymentStatus);
        $paymentData = [
            'user_id' => $wallet['user_id'],
            'wallet_id' => $wallet['id'],
            'transaction_id' => $transaction['id'],
            'gateway' => 'payos',
            'gateway_reference' => $internalReference,
            'merchant_reference' => $transaction['reference'],
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'ZAR',
            'status' => $effectiveStatus,
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
    
}
