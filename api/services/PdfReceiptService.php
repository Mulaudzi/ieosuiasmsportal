<?php
/**
 * PDF Receipt Generator Service
 * Generates simple HTML-based PDF receipts without external libraries
 */

class PdfReceiptService {
    
    /**
     * Generate a payment receipt PDF
     */
    public static function generateReceipt(array $payment, array $user): string {
        $companyName = env('COMPANY_NAME', 'IEOSUIA SMS Portal');
        $companyAddress = env('COMPANY_ADDRESS', '');
        $companyVat = env('COMPANY_VAT', '');
        $companyEmail = env('COMPANY_EMAIL', 'support@ieosuia.co.za');
        $companyPhone = env('COMPANY_PHONE', '');
        
        $receiptNumber = 'RCP-' . str_pad($payment['id'], 6, '0', STR_PAD_LEFT);
        $paymentDate = date('d F Y', strtotime($payment['created_at']));
        $processedDate = $payment['processed_at'] ? date('d F Y H:i', strtotime($payment['processed_at'])) : '-';
        
        $amount = number_format((float)$payment['amount'], 2);
        $vatRate = 0.15;
        $vatAmount = (float)$payment['amount'] * $vatRate;
        $subtotal = (float)$payment['amount'] - $vatAmount;
        
        $gatewayLabels = [
            'payfast' => 'PayFast',
            'paystack' => 'Paystack',
            'ozow' => 'Ozow',
            'eft' => 'EFT Bank Transfer',
        ];
        $gateway = $gatewayLabels[$payment['gateway']] ?? ucfirst($payment['gateway']);
        
        $statusColors = [
            'completed' => '#10b981',
            'pending' => '#f59e0b',
            'failed' => '#ef4444',
            'cancelled' => '#6b7280',
            'refunded' => '#8b5cf6',
        ];
        $statusColor = $statusColors[$payment['status']] ?? '#6b7280';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt ' . $receiptNumber . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #1f2937; line-height: 1.6; }
        .container { max-width: 800px; margin: 0 auto; padding: 40px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; border-bottom: 2px solid #3b82f6; padding-bottom: 20px; }
        .logo { font-size: 24px; font-weight: bold; color: #3b82f6; }
        .receipt-info { text-align: right; }
        .receipt-number { font-size: 18px; font-weight: bold; color: #1f2937; }
        .receipt-date { color: #6b7280; margin-top: 5px; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; color: white; background-color: ' . $statusColor . '; margin-top: 10px; }
        .parties { display: flex; justify-content: space-between; margin-bottom: 40px; }
        .party { flex: 1; }
        .party-title { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; }
        .party-name { font-weight: bold; font-size: 16px; }
        .party-details { color: #6b7280; font-size: 13px; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .items-table th { background-color: #f3f4f6; padding: 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #6b7280; border-bottom: 2px solid #e5e7eb; }
        .items-table td { padding: 15px 12px; border-bottom: 1px solid #e5e7eb; }
        .items-table .amount { text-align: right; }
        .totals { margin-left: auto; width: 300px; }
        .totals-row { display: flex; justify-content: space-between; padding: 8px 0; }
        .totals-row.total { border-top: 2px solid #1f2937; margin-top: 10px; padding-top: 15px; font-weight: bold; font-size: 18px; }
        .payment-details { background-color: #f9fafb; padding: 20px; border-radius: 8px; margin-top: 30px; }
        .payment-details h3 { font-size: 14px; color: #6b7280; margin-bottom: 15px; text-transform: uppercase; }
        .payment-row { display: flex; justify-content: space-between; margin-bottom: 8px; }
        .payment-label { color: #6b7280; }
        .footer { margin-top: 50px; text-align: center; color: #9ca3af; font-size: 12px; border-top: 1px solid #e5e7eb; padding-top: 20px; }
        .credits-highlight { background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white; padding: 15px 20px; border-radius: 8px; text-align: center; margin: 20px 0; }
        .credits-number { font-size: 28px; font-weight: bold; }
        .credits-label { font-size: 12px; opacity: 0.9; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <div class="logo">' . htmlspecialchars($companyName) . '</div>
                <div style="color: #6b7280; font-size: 13px; margin-top: 5px;">Payment Receipt</div>
            </div>
            <div class="receipt-info">
                <div class="receipt-number">' . $receiptNumber . '</div>
                <div class="receipt-date">' . $paymentDate . '</div>
                <div class="status-badge">' . strtoupper($payment['status']) . '</div>
            </div>
        </div>
        
        <div class="parties">
            <div class="party">
                <div class="party-title">From</div>
                <div class="party-name">' . htmlspecialchars($companyName) . '</div>
                <div class="party-details">
                    ' . ($companyAddress ? htmlspecialchars($companyAddress) . '<br>' : '') . '
                    ' . ($companyVat ? 'VAT: ' . htmlspecialchars($companyVat) . '<br>' : '') . '
                    ' . htmlspecialchars($companyEmail) . '
                </div>
            </div>
            <div class="party" style="text-align: right;">
                <div class="party-title">Bill To</div>
                <div class="party-name">' . htmlspecialchars($user['name'] ?? 'Customer') . '</div>
                <div class="party-details">
                    ' . htmlspecialchars($user['email'] ?? '') . '
                </div>
            </div>
        </div>
        
        <table class="items-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align: center;">Qty</th>
                    <th style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>SMS Credits Purchase</strong><br>
                        <span style="color: #6b7280; font-size: 12px;">Payment via ' . $gateway . '</span>
                    </td>
                    <td style="text-align: center;">' . number_format($payment['credits_added']) . ' credits</td>
                    <td class="amount">R ' . $amount . '</td>
                </tr>
            </tbody>
        </table>
        
        <div class="totals">
            <div class="totals-row">
                <span>Subtotal</span>
                <span>R ' . number_format($subtotal, 2) . '</span>
            </div>
            <div class="totals-row">
                <span>VAT (15%)</span>
                <span>R ' . number_format($vatAmount, 2) . '</span>
            </div>
            <div class="totals-row total">
                <span>Total</span>
                <span>R ' . $amount . '</span>
            </div>
        </div>
        
        ' . ($payment['credits_added'] > 0 ? '
        <div class="credits-highlight">
            <div class="credits-number">' . number_format($payment['credits_added']) . '</div>
            <div class="credits-label">SMS Credits Added to Your Account</div>
        </div>
        ' : '') . '
        
        <div class="payment-details">
            <h3>Payment Details</h3>
            <div class="payment-row">
                <span class="payment-label">Reference</span>
                <span>' . htmlspecialchars($payment['merchant_reference']) . '</span>
            </div>
            <div class="payment-row">
                <span class="payment-label">Gateway Reference</span>
                <span>' . htmlspecialchars($payment['gateway_reference'] ?? '-') . '</span>
            </div>
            <div class="payment-row">
                <span class="payment-label">Payment Method</span>
                <span>' . $gateway . '</span>
            </div>
            <div class="payment-row">
                <span class="payment-label">Processed</span>
                <span>' . $processedDate . '</span>
            </div>
        </div>
        
        <div class="footer">
            <p>Thank you for your purchase!</p>
            <p style="margin-top: 10px;">This is a computer-generated receipt and does not require a signature.</p>
            <p style="margin-top: 10px;">' . htmlspecialchars($companyName) . ' | ' . htmlspecialchars($companyEmail) . '</p>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
}
