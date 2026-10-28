<?php
require_once __DIR__.'/SmsDeliveryPresentation.php';
require_once __DIR__.'/SmsMessageState.php';

final class SmsReportingService
{
    /** Derive display counters from message rows, never from maintained campaign counters. */
    public static function campaignRecords(int $userId, array $campaigns): array
    {
        if (!$campaigns) return [];
        $ids=array_column($campaigns,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
        $accepted=SmsMessageState::acceptedSql();$networkFailure=SmsMessageState::networkFailureSql();$submissionFailure=SmsMessageState::submissionFailureSql();
        $query=db()->prepare("SELECT campaign_id,COUNT(*) recipient_count,
            SUM($accepted) sent_count,SUM(state='delivered') delivered_count,
            SUM($networkFailure) failed_count,SUM($submissionFailure) failed_submission_count,SUM(state IN ('pending','queued','retry')) queued_count,
            SUM(state='processing') processing_count,SUM(state='skipped') skipped_count,
            SUM(state='retry' AND next_attempt_at='9999-12-31 23:59:59') held_submission_count,
            SUM(state='dlr_unavailable') dlr_unavailable_count,
            SUM(state='sent') awaiting_delivery_count,
            COALESCE(SUM(segment_count),0) total_segments,
            COALESCE(SUM(CASE WHEN actual_charge>0 THEN segment_count ELSE 0 END),0) credits_used,
            COALESCE(SUM(actual_charge),0) actual_cost
            FROM sms_messages WHERE user_id=? AND campaign_id IN ($marks) GROUP BY campaign_id");
        $query->execute(array_merge([$userId],$ids));$metrics=[];
        foreach($query->fetchAll() as $row)$metrics[(int)$row['campaign_id']]=$row;
        foreach($campaigns as &$campaign){
            $values=$metrics[(int)$campaign['id']]??[];
            foreach(['recipient_count','sent_count','delivered_count','failed_count','failed_submission_count','held_submission_count','queued_count','processing_count','skipped_count','dlr_unavailable_count','awaiting_delivery_count','total_segments','credits_used'] as $field)$campaign[$field]=(int)($values[$field]??0);
            $campaign['actual_cost']=(float)($values['actual_cost']??0);
            $campaign['amount_spent']=$campaign['actual_cost'];$campaign['status']=$campaign['state']??$campaign['status']??'unknown';
        }
        unset($campaign);return $campaigns;
    }

    private const RANGES = ['24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    public static function period(string $range, ?string $from=null, ?string $to=null):array
    {
        if ($range === 'custom') {
            $parse = static function (?string $value): DateTimeImmutable {
                $date = $value ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Choose valid start and end dates');
                return $date;
            };
            $start=$parse($from);$end=$parse($to);
            $days=(int)$start->diff($end)->format('%r%a')+1;
            if ($days<1 || $days>366) throw new InvalidArgumentException('Choose an ordered date range of up to 366 days');
            return ['range'=>$range,'days'=>$days,'start'=>$start->format('Y-m-d H:i:s'),'end'=>$end->setTime(23,59,59)->format('Y-m-d H:i:s')];
        }
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

    public static function summary(int $userId,string $range,?string $from=null,?string $to=null):array
    {
        $period=self::period($range,$from,$to);$accepted=SmsMessageState::acceptedSql();$networkFailure=SmsMessageState::networkFailureSql();$submissionFailure=SmsMessageState::submissionFailureSql();
        $stmt=db()->prepare("SELECT
            COUNT(*) recipient_count,COALESCE(SUM($accepted),0) total_messages,
            COALESCE(SUM(state='delivered'),0) delivered,
            COALESCE(SUM($networkFailure),0) failed,
            COALESCE(SUM($submissionFailure),0) failed_submissions,
            COALESCE(SUM(state IN ('pending','queued','retry','processing')),0) waiting_to_send,
            COALESCE(SUM(state IN ('sent','dlr_unavailable')),0) pending,
            COALESCE(SUM(state='sent'),0) awaiting_delivery,
            COALESCE(SUM(state='dlr_unavailable'),0) dlr_unavailable,
            COALESCE(SUM(state='skipped'),0) skipped,
            COALESCE(SUM(CASE WHEN state<>'skipped' AND actual_charge>0 THEN segment_count ELSE 0 END),0) credits_used,
            AVG(CASE WHEN state<>'skipped' AND delivered_at IS NOT NULL AND sent_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND,sent_at,delivered_at) END) avg_delivery_seconds
            FROM sms_messages WHERE user_id=? AND created_at>=? AND created_at<=?");
        $stmt->execute([$userId,$period['start'],$period['end']]);$row=$stmt->fetch()?:[];$total=(int)($row['total_messages']??0);$delivered=(int)($row['delivered']??0);
        // All counters share one read snapshot. Skipped is excluded from accepted
        // totals, charged segments and the existing average-time calculation.
        return array_merge($period,['skipped'=>(int)($row['skipped']??0),'recipient_count'=>(int)($row['recipient_count']??0),'failed_submissions'=>(int)($row['failed_submissions']??0),'waiting_to_send'=>(int)($row['waiting_to_send']??0),'total_messages'=>$total,'delivered'=>$delivered,'failed'=>(int)($row['failed']??0),'pending'=>(int)($row['pending']??0),'awaiting_delivery'=>(int)($row['awaiting_delivery']??0),'dlr_unavailable'=>(int)($row['dlr_unavailable']??0),'credits_used'=>(int)($row['credits_used']??0),'avg_delivery_seconds'=>($row['avg_delivery_seconds']??null)===null?null:(float)$row['avg_delivery_seconds'],'delivery_rate'=>$total>0?round($delivered/$total*100,1):0]);
    }

    public static function daily(int $userId,string $range,?string $from=null,?string $to=null):array
    {
        $period=self::period($range,$from,$to);$accepted=SmsMessageState::acceptedSql();$failed=SmsMessageState::networkFailureSql();
        $stmt=db()->prepare("SELECT DATE(created_at) date,SUM($accepted) total_messages,SUM(state='delivered') delivered,SUM($failed) failed,SUM(state IN ('sent','dlr_unavailable')) pending FROM sms_messages WHERE user_id=? AND state<>'skipped' AND created_at>=? AND created_at<=? GROUP BY DATE(created_at) ORDER BY date");$stmt->execute([$userId,$period['start'],$period['end']]);return $stmt->fetchAll();
    }

    public static function rows(int $userId,string $range):array
    {
        $period=self::period($range);$stmt=db()->prepare("SELECT sc.name campaign,sm.destination,sm.state,sm.segment_count,sm.actual_charge,sm.sent_at,sm.delivered_at,sm.failed_at,sm.last_error,sm.created_at FROM sms_messages sm JOIN sms_campaigns sc ON sc.id=sm.campaign_id AND sc.user_id=sm.user_id WHERE sm.user_id=? AND sm.created_at>=? AND sm.created_at<=? ORDER BY sm.created_at DESC,sm.id DESC");$stmt->execute([$userId,$period['start'],$period['end']]);return array_map(static function($row){$state=$row[2];$detail=$row[8];$accepted=SmsMessageState::accepted(['state'=>$state,'last_error'=>$detail,'sent_at'=>$row[5],'actual_charge'=>$row[4]]);$row[2]=SmsDeliveryPresentation::label($state,$detail,$accepted);$row[8]=SmsDeliveryPresentation::details($state,$detail,$accepted);return $row;},$stmt->fetchAll(PDO::FETCH_NUM));
    }

    public static function breakdown(int $userId,string $range):array
    {
        $period=self::period($range);
        $failed=SmsMessageState::submissionFailureSql();
        $stmt=db()->prepare("SELECT CASE
            WHEN state='dlr_unavailable' THEN 'Sent - Confirmation Unavailable'
            WHEN state='sent' THEN 'Sent - Awaiting Confirmation'
            WHEN state IN ('pending','queued','retry','processing') THEN 'Waiting to send'
            WHEN state='delivered' THEN 'Delivered'
            WHEN $failed THEN 'Failed to send'
            WHEN state='failed' THEN 'Not Delivered'
            WHEN state='skipped' THEN 'Not Sent'
            ELSE CONCAT(UPPER(LEFT(state,1)),SUBSTRING(state,2))
        END status,COUNT(*) count
        FROM sms_messages
        WHERE user_id=? AND created_at>=? AND created_at<=?
        GROUP BY status ORDER BY status");
        $stmt->execute([$userId,$period['start'],$period['end']]);
        return $stmt->fetchAll();
    }
}
