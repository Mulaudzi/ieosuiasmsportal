<?php

require_once __DIR__.'/SmsPricing.php';
require_once __DIR__.'/SmsMessageState.php';

/** Read-only admin projections using the same wallet units as customer checkout. */
final class AdminSmsSummary
{
    public static function credits(array $wallet, float $price): array
    {
        if (!is_finite($price) || $price <= 0) throw new InvalidArgumentException('Invalid SMS credit unit');
        $balance = (float)($wallet['balance'] ?? 0);
        $reserved = (float)($wallet['reserved'] ?? 0);
        $whole = static fn(float $amount): int => max(0, (int)floor(($amount + 0.000001) / $price));
        return ['sms_credits_total'=>$whole($balance), 'sms_credits_reserved'=>$whole($reserved),
            'sms_credits_available'=>$whole($balance-$reserved), 'sms_credit_unit_value'=>$price];
    }

    public static function customers(array $rows, float $price): array
    {
        return array_map(static fn(array $row): array => array_merge($row, self::credits($row, $price)), $rows);
    }

    public static function dispatch(PDO $pdo): array
    {
        $jobs = $pdo->query("SELECT COUNT(*) active,SUM(state='queued') queued,SUM(state='processing') processing FROM sms_campaigns WHERE state IN ('queued','processing')")->fetch(PDO::FETCH_ASSOC);
        $accepted = SmsMessageState::acceptedSql('m');
        // Only dispatchable campaigns count as waiting. Scheduled and terminal
        // campaigns, and messages awaiting delivery confirmation, are not sending.
        $q = $pdo->query("SELECT
            COALESCE(SUM(m.state IN ('pending','queued','retry') AND (m.next_attempt_at IS NULL OR m.next_attempt_at<>'9999-12-31 23:59:59')),0) waiting,
            COALESCE(SUM(m.state='processing'),0) processing,
            COALESCE(SUM(m.state='retry' AND m.next_attempt_at='9999-12-31 23:59:59'),0) held
            FROM sms_messages m JOIN sms_campaigns c ON c.id=m.campaign_id AND c.user_id=m.user_id
            WHERE c.state IN ('queued','processing') AND NOT $accepted");
        return ['jobs'=>array_map('intval',$jobs), 'messages'=>array_map('intval',$q->fetch(PDO::FETCH_ASSOC)), 'observed_at'=>date('c')];
    }
}
