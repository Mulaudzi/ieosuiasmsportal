<?php

require_once __DIR__.'/WalletService.php';
require_once __DIR__.'/CustomerCashBalance.php';

final class CreditPurchaseSettlement
{
    public static function available(PDO $pdo): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='credit_purchase_orders'")->fetchColumn();
        }
        return (bool)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_purchase_orders'")->fetchColumn();
    }

    public static function order(PDO $pdo, int $paymentId, bool $lock = false): ?array
    {
        if (!self::available($pdo)) return null; // Existing payments remain compatible before migration.
        $q=$pdo->prepare('SELECT * FROM credit_purchase_orders WHERE payment_id=?'.($lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $q->execute([$paymentId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function credit(PDO $pdo, array $payment, float $paidAmount, bool $verifiedEftDifference=false): int
    {
        if (!$pdo->inTransaction()) throw new LogicException('Payment settlement requires a transaction.');
        $allowDifference=$verifiedEftDifference && ($payment['gateway']??'')==='eft' && CustomerCashBalance::available($pdo);
        if (!$allowDifference && abs((float)$payment['amount']-$paidAmount)>.0001) throw new DomainException('Payment amount does not match the order.');
        $order=self::order($pdo,(int)$payment['id'],true);
        if ($order) {
            if ((!$allowDifference && (int)$order['total_cents'] !== (int)round($paidAmount*100)) || (int)$order['user_id'] !== (int)$payment['user_id'] || $order['reference'] !== $payment['merchant_reference']) {
                throw new DomainException('Purchase order does not match the payment.');
            }
            if ($order['status']==='rejected') throw new DomainException('This purchase was rejected and needs review.');
            if ($order['status']==='voided') throw new DomainException('This purchase was voided. Reconcile any received funds separately before creating a new purchase.');
            $credits=(int)$order['credits'];
            $walletAmount=(float)$order['wallet_amount'];
        } else {
            $walletAmount=$paidAmount;
            $credits=max(0,(int)floor(($paidAmount+.000001)/SmsPricing::pricePerSegment()));
        }
        if ($order && CustomerCashBalance::available($pdo)) {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {$q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$payment['user_id']]);}
            CustomerCashBalance::settle($pdo,$order,(int)round($paidAmount*100));
        }
        WalletService::creditPayment($pdo,(int)$payment['user_id'],(int)$payment['id'],$walletAmount,(string)$payment['merchant_reference']);
        if ($order) $pdo->prepare("UPDATE credit_purchase_orders SET status='completed',updated_at=NOW() WHERE id=?")->execute([$order['id']]);
        return $credits;
    }
}
