<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Wallet Controller
 * 
 * Handles wallet balance, transactions, and credit purchases.
 */
class WalletController extends Controller
{
    /**
     * Get wallet balance and recent transactions
     * 
     * GET /api/wallet
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $user->wallet;

        if (!$wallet) {
            $wallet = Wallet::create([
                'user_id' => $user->id,
                'balance' => 0,
                'reserved' => 0,
            ]);
        }

        $recentTransactions = WalletTransaction::where('wallet_id', $wallet->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => (float) $tx->amount,
                'description' => $tx->description,
                'reference' => $tx->reference,
                'paymentMethod' => $tx->payment_method,
                'createdAt' => $tx->created_at->toISOString(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'balance' => (float) $wallet->balance,
                'reserved' => (float) $wallet->reserved,
                'available' => (float) ($wallet->balance - $wallet->reserved),
                'currency' => $wallet->currency,
                'recentTransactions' => $recentTransactions,
            ],
        ]);
    }

    /**
     * Get full transaction history
     * 
     * GET /api/wallet/history
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $user->wallet;
        $perPage = (int) $request->query('per_page', 20);

        $transactions = WalletTransaction::where('wallet_id', $wallet->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    /**
     * Initiate credit purchase
     * 
     * POST /api/wallet/buy
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function buy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:50'], // Minimum R50
            'payment_method' => ['required', 'string', 'in:payfast,ozow,eft'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $wallet = $user->wallet;
        $amount = (float) $request->input('amount');
        $paymentMethod = $request->input('payment_method');

        // Calculate credits (R0.38 per credit)
        $pricePerCredit = (float) config('sms.price_per_credit', 0.38);
        $credits = floor($amount / $pricePerCredit);
        
        // Calculate VAT (15%)
        $vatRate = 0.15;
        $subtotal = $amount / (1 + $vatRate);
        $vat = $amount - $subtotal;

        // Generate reference
        $reference = strtoupper(config('eft.reference_prefix', 'SMS') . '-' . Str::random(8));

        try {
            DB::beginTransaction();

            // Create pending transaction
            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_after' => $wallet->balance, // Will update on confirmation
                'description' => "Purchase {$credits} SMS credits",
                'reference' => $reference,
                'payment_method' => $paymentMethod,
                'payment_status' => 'pending',
                'metadata' => json_encode([
                    'credits' => $credits,
                    'subtotal' => $subtotal,
                    'vat' => $vat,
                    'price_per_credit' => $pricePerCredit,
                ]),
            ]);

            DB::commit();

            // Generate payment URL based on method
            $paymentData = match($paymentMethod) {
                'payfast' => $this->generatePayFastPayment($user, $amount, $reference, $transaction->id),
                'ozow' => $this->generateOzowPayment($user, $amount, $reference, $transaction->id),
                'eft' => $this->generateEftDetails($amount, $reference),
                default => throw new \Exception('Invalid payment method'),
            };

            return response()->json([
                'success' => true,
                'data' => [
                    'transactionId' => $transaction->id,
                    'reference' => $reference,
                    'amount' => $amount,
                    'credits' => $credits,
                    'subtotal' => round($subtotal, 2),
                    'vat' => round($vat, 2),
                    'paymentMethod' => $paymentMethod,
                    'payment' => $paymentData,
                ],
                'message' => 'Payment initiated',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to initiate payment',
            ], 500);
        }
    }

    /**
     * Generate PayFast payment form data
     */
    private function generatePayFastPayment($user, float $amount, string $reference, int $transactionId): array
    {
        $merchantId = config('payfast.merchant_id');
        $merchantKey = config('payfast.merchant_key');
        $passphrase = config('payfast.passphrase');
        $sandbox = config('payfast.sandbox', true);

        $data = [
            'merchant_id' => $merchantId,
            'merchant_key' => $merchantKey,
            'return_url' => config('app.frontend_url') . '/wallet?payment=success',
            'cancel_url' => config('app.frontend_url') . '/wallet?payment=cancelled',
            'notify_url' => config('payfast.itn_url'),
            'name_first' => $user->name,
            'email_address' => $user->email,
            'm_payment_id' => $reference,
            'amount' => number_format($amount, 2, '.', ''),
            'item_name' => 'SMS Credits',
            'custom_int1' => $transactionId,
        ];

        // Generate signature
        $signatureString = '';
        foreach ($data as $key => $value) {
            if ($value !== '') {
                $signatureString .= $key . '=' . urlencode(trim($value)) . '&';
            }
        }
        $signatureString = rtrim($signatureString, '&');
        if ($passphrase) {
            $signatureString .= '&passphrase=' . urlencode($passphrase);
        }
        $data['signature'] = md5($signatureString);

        return [
            'url' => $sandbox 
                ? 'https://sandbox.payfast.co.za/eng/process'
                : 'https://www.payfast.co.za/eng/process',
            'formData' => $data,
        ];
    }

    /**
     * Generate Ozow payment URL
     */
    private function generateOzowPayment($user, float $amount, string $reference, int $transactionId): array
    {
        $siteCode = config('ozow.site_code');
        $privateKey = config('ozow.private_key');
        $sandbox = config('ozow.sandbox', true);

        $data = [
            'SiteCode' => $siteCode,
            'CountryCode' => 'ZA',
            'CurrencyCode' => 'ZAR',
            'Amount' => number_format($amount, 2, '.', ''),
            'TransactionReference' => $reference,
            'BankReference' => $reference,
            'Optional1' => $transactionId,
            'Customer' => $user->email,
            'CancelUrl' => config('app.frontend_url') . '/wallet?payment=cancelled',
            'ErrorUrl' => config('app.frontend_url') . '/wallet?payment=error',
            'SuccessUrl' => config('app.frontend_url') . '/wallet?payment=success',
            'NotifyUrl' => config('ozow.notify_url'),
            'IsTest' => $sandbox ? 'true' : 'false',
        ];

        // Generate hash
        $hashString = implode('', $data) . $privateKey;
        $data['HashCheck'] = hash('sha512', strtolower($hashString));

        return [
            'url' => 'https://pay.ozow.com',
            'formData' => $data,
        ];
    }

    /**
     * Generate EFT bank details
     */
    private function generateEftDetails(float $amount, string $reference): array
    {
        return [
            'bankName' => config('eft.bank_name', 'First National Bank'),
            'accountName' => config('eft.account_name', 'IEOSUIA PTY LTD'),
            'accountNumber' => config('eft.account_number'),
            'branchCode' => config('eft.branch_code', '250655'),
            'reference' => $reference,
            'amount' => $amount,
            'instructions' => 'Please use the reference above when making payment. Credits will be added within 24-48 hours of receiving payment.',
        ];
    }

    /**
     * Handle PayFast ITN (Instant Transaction Notification)
     * 
     * POST /api/payments/payfast/itn
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function payfastItn(Request $request): JsonResponse
    {
        // Verify PayFast signature
        $passphrase = config('payfast.passphrase');
        $pfData = $request->except('signature');
        
        $pfParamString = '';
        foreach ($pfData as $key => $value) {
            if ($key !== 'signature') {
                $pfParamString .= $key . '=' . urlencode(trim($value)) . '&';
            }
        }
        $pfParamString = rtrim($pfParamString, '&');
        if ($passphrase) {
            $pfParamString .= '&passphrase=' . urlencode($passphrase);
        }
        
        $signature = md5($pfParamString);

        if ($signature !== $request->input('signature')) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $transactionId = $request->input('custom_int1');
        $paymentStatus = $request->input('payment_status');
        $amount = (float) $request->input('amount_gross');

        $transaction = WalletTransaction::find($transactionId);

        if (!$transaction) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        if ($paymentStatus === 'COMPLETE') {
            $this->confirmPayment($transaction, $amount);
        } else {
            $transaction->payment_status = 'failed';
            $transaction->save();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Handle Ozow notification
     * 
     * POST /api/payments/ozow/notify
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function ozowNotify(Request $request): JsonResponse
    {
        // Verify Ozow hash
        $privateKey = config('ozow.private_key');
        $data = $request->all();
        $receivedHash = $data['Hash'] ?? '';
        unset($data['Hash']);

        $hashString = implode('', $data) . $privateKey;
        $calculatedHash = hash('sha512', strtolower($hashString));

        if (strtolower($receivedHash) !== $calculatedHash) {
            return response()->json(['error' => 'Invalid hash'], 403);
        }

        $transactionId = $request->input('Optional1');
        $status = $request->input('Status');
        $amount = (float) $request->input('Amount');

        $transaction = WalletTransaction::find($transactionId);

        if (!$transaction) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        if ($status === 'Complete') {
            $this->confirmPayment($transaction, $amount);
        } else {
            $transaction->payment_status = 'failed';
            $transaction->save();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Confirm payment and add credits
     */
    private function confirmPayment(WalletTransaction $transaction, float $amount): void
    {
        if ($transaction->payment_status === 'completed') {
            return; // Already processed
        }

        $wallet = $transaction->wallet;
        
        DB::transaction(function () use ($wallet, $transaction, $amount) {
            $wallet->balance += $amount;
            $wallet->save();

            $transaction->balance_after = $wallet->balance;
            $transaction->payment_status = 'completed';
            $transaction->save();
        });
    }

    /**
     * Manually confirm EFT payment (admin only)
     * 
     * POST /api/wallet/confirm-eft
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function confirmEft(Request $request): JsonResponse
    {
        // Check if user is admin
        $user = $request->user();
        if (!$user->hasRole('admin')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'reference' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $transaction = WalletTransaction::where('reference', $request->input('reference'))
            ->where('payment_status', 'pending')
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'error' => 'Transaction not found or already processed',
            ], 404);
        }

        $this->confirmPayment($transaction, (float) $request->input('amount'));

        return response()->json([
            'success' => true,
            'message' => 'Payment confirmed and credits added',
        ]);
    }
}
