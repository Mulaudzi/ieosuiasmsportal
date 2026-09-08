<?php

final class WalletService
{
    public static function reserve(PDO $pdo, int $userId, int $campaignId, float $amount): void
    {
        $wallet = self::lockWallet($pdo, $userId);
        $available = round((float) $wallet['balance'] - (float) $wallet['reserved'], 4);
        if ($amount <= 0 || $available < $amount) {
            throw new DomainException('Insufficient available balance');
        }
        self::ledger($pdo, $userId, (int) $wallet['id'], $campaignId, 'reservation', 'reserve', $amount, "campaign:{$campaignId}:reserve");
        $stmt = $pdo->prepare('UPDATE wallets SET reserved = reserved + ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$amount, $wallet['id']]);
    }

    public static function reserveRetry(PDO $pdo, int $userId, int $campaignId, float $amount, string $retryKey): void
    {
        if ($amount <= 0) return;
        $wallet = self::lockWallet($pdo, $userId);
        $key = "campaign:{$campaignId}:retry:{$retryKey}";
        if (self::ledgerExists($pdo, $key)) return;
        $available = round((float)$wallet['balance'] - (float)$wallet['reserved'], 4);
        if ($available < $amount) throw new DomainException('Insufficient available balance to retry failed messages');
        self::ledger($pdo,$userId,(int)$wallet['id'],$campaignId,'retry_reservation','reserve',$amount,$key);
        $stmt=$pdo->prepare('UPDATE wallets SET reserved=reserved+?,updated_at=NOW() WHERE id=?');
        $stmt->execute([$amount,$wallet['id']]);
    }

    public static function settleMessage(PDO $pdo, array $message, float $amount): void
    {
        $wallet = self::lockWallet($pdo, (int) $message['user_id']);
        $key = "sms-message:{$message['id']}:settle";
        if (self::ledgerExists($pdo, $key)) return;
        if ((float) $wallet['balance'] < $amount || (float) $wallet['reserved'] < $amount) {
            throw new DomainException('Reserved funds are unavailable');
        }
        self::ledger($pdo, (int) $message['user_id'], (int) $wallet['id'], (int) $message['campaign_id'], 'sms_settlement', 'debit', $amount, $key);
        $stmt = $pdo->prepare('UPDATE wallets SET balance = balance - ?, reserved = GREATEST(0, reserved - ?), updated_at = NOW() WHERE id = ?');
        $stmt->execute([$amount, $amount, $wallet['id']]);
    }

    public static function release(PDO $pdo, int $userId, int $campaignId, float $amount, string $reason): void
    {
        if ($amount <= 0) return;
        $wallet = self::lockWallet($pdo, $userId);
        $key = "campaign:{$campaignId}:release:{$reason}";
        if (self::ledgerExists($pdo, $key)) return;
        $release = min($amount, (float) $wallet['reserved']);
        self::ledger($pdo, $userId, (int) $wallet['id'], $campaignId, 'reservation_release', 'release', $release, $key);
        $stmt = $pdo->prepare('UPDATE wallets SET reserved = GREATEST(0, reserved - ?), updated_at = NOW() WHERE id = ?');
        $stmt->execute([$release, $wallet['id']]);
    }

    public static function creditPayment(PDO $pdo, int $userId, int $paymentId, float $amount, string $reference): void
    {
        $wallet = self::lockWallet($pdo, $userId);
        $key = "payment:{$reference}:credit";
        if (self::ledgerExists($pdo, $key)) return;
        self::ledger($pdo, $userId, (int) $wallet['id'], null, 'payment_credit', 'credit', $amount, $key, $paymentId);
        $stmt = $pdo->prepare('UPDATE wallets SET balance = balance + ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$amount, $wallet['id']]);
    }

    private static function lockWallet(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch();
        if (!$wallet) throw new DomainException('Wallet not found');
        return $wallet;
    }

    private static function ledgerExists(PDO $pdo, string $key): bool
    {
        $stmt = $pdo->prepare('SELECT id FROM wallet_ledger WHERE idempotency_key = ?');
        $stmt->execute([$key]);
        return (bool) $stmt->fetchColumn();
    }

    private static function ledger(PDO $pdo, int $userId, int $walletId, ?int $campaignId, string $operation, string $direction, float $amount, string $key, ?int $paymentId = null): void
    {
        $stmt = $pdo->prepare('INSERT INTO wallet_ledger (user_id,wallet_id,campaign_id,payment_id,operation,direction,amount,idempotency_key,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
        $stmt->execute([$userId, $walletId, $campaignId, $paymentId, $operation, $direction, $amount, $key]);
    }
}
