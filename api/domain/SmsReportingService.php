<?php

final class SmsReportingService
{
    private const RANGES = ['24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    public static function period(string $range):array
    {
        if (!isset(self::RANGES[$range])) {
            throw new InvalidArgumentException('Invalid reporting range');
        }

        $days = self::RANGES[$range];
        $end = new DateTimeImmutable('now');
        $start = $end->modify("-{$days} days");

        return [
            'range' => $range,
            'days' => $days,
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ];
    }

    public static function summary(int $userId,string $range):array
    {
        $period=self::period($range);$stmt=db()->prepare("SELECT
            COUNT(*) total_messages,
            COALESCE(SUM(state='delivered'),0) delivered,
            COALESCE(SUM(state='failed'),0) failed,
            COALESCE(SUM(state IN ('pending','queued','retry','processing','sent')),0) pending,
            COALESCE(SUM(state='sent' AND (last_error IS NULL OR last_error NOT LIKE 'DLR lookup:%')),0) awaiting_delivery,
            COALESCE(SUM(state='sent' AND last_error LIKE 'DLR lookup:%'),0) dlr_unavailable,
            COALESCE(SUM(CASE WHEN actual_charge>0 THEN segment_count ELSE 0 END),0) credits_used,
            AVG(CASE WHEN delivered_at IS NOT NULL AND sent_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND,sent_at,delivered_at) END) avg_delivery_seconds
            FROM sms_messages WHERE user_id=? AND state<>'skipped' AND created_at>=? AND created_at<=?");
        $stmt->execute([$userId,$period['start'],$period['end']]);$row=$stmt->fetch()?:[];$total=(int)($row['total_messages']??0);$delivered=(int)($row['delivered']??0);
        return array_merge($period,['total_messages'=>$total,'delivered'=>$delivered,'failed'=>(int)($row['failed']??0),'pending'=>(int)($row['pending']??0),'awaiting_delivery'=>(int)($row['awaiting_delivery']??0),'dlr_unavailable'=>(int)($row['dlr_unavailable']??0),'credits_used'=>(int)($row['credits_used']??0),'avg_delivery_seconds'=>$row['avg_delivery_seconds']===null?null:(float)$row['avg_delivery_seconds'],'delivery_rate'=>$total>0?round($delivered/$total*100,1):0]);
    }

    public static function daily(int $userId,string $range):array
    {
        $period=self::period($range);$stmt=db()->prepare("SELECT DATE(created_at) date,COUNT(*) total_messages,SUM(state='delivered') delivered,SUM(state='failed') failed,SUM(state IN ('pending','queued','retry','processing','sent')) pending FROM sms_messages WHERE user_id=? AND state<>'skipped' AND created_at>=? AND created_at<=? GROUP BY DATE(created_at) ORDER BY date");$stmt->execute([$userId,$period['start'],$period['end']]);return $stmt->fetchAll();
    }

    public static function rows(int $userId,string $range):array
    {
        $period=self::period($range);$stmt=db()->prepare("SELECT sc.name campaign,sm.destination,sm.state,sm.segment_count,sm.actual_charge,sm.sent_at,sm.delivered_at,sm.failed_at,sm.last_error,sm.created_at FROM sms_messages sm JOIN sms_campaigns sc ON sc.id=sm.campaign_id AND sc.user_id=sm.user_id WHERE sm.user_id=? AND sm.state<>'skipped' AND sm.created_at>=? AND sm.created_at<=? ORDER BY sm.created_at DESC,sm.id DESC");$stmt->execute([$userId,$period['start'],$period['end']]);return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    public static function breakdown(int $userId,string $range):array
    {
        $period=self::period($range);
        $stmt=db()->prepare("SELECT CASE
            WHEN state='sent' AND last_error LIKE 'DLR lookup:%' THEN 'Provider DLR unavailable'
            WHEN state='sent' THEN 'Awaiting delivery report'
            WHEN state IN ('pending','queued','retry','processing') THEN 'Pending dispatch'
            WHEN state='delivered' THEN 'Delivered'
            WHEN state='failed' THEN 'Failed'
            ELSE CONCAT(UPPER(LEFT(state,1)),SUBSTRING(state,2))
        END status,COUNT(*) count
        FROM sms_messages
        WHERE user_id=? AND state<>'skipped' AND created_at>=? AND created_at<=?
        GROUP BY status ORDER BY status");
        $stmt->execute([$userId,$period['start'],$period['end']]);
        return $stmt->fetchAll();
    }
}
