<?php
/** Shared, read-only separation of provider acceptance from delivery outcomes. */
final class SmsMessageState
{
    public static function accepted(array $message): bool
    {
        if($message['state']==='skipped')return false;
        return !empty($message['sent_at']) || in_array($message['state'], ['sent','delivered','dlr_unavailable'], true)
            || ($message['state']==='failed' && ((float)($message['actual_charge']??0)>0
                || str_starts_with((string)($message['last_error']??''), 'LogicSMS delivery status:')));
    }
    public static function acceptedSql(string $alias=''): string
    {
        $a=$alias!==''?$alias.'.':'';
        return "({$a}state<>'skipped' AND ({$a}sent_at IS NOT NULL OR {$a}state IN ('sent','delivered','dlr_unavailable') OR ({$a}state='failed' AND ({$a}actual_charge>0 OR COALESCE({$a}last_error,'') LIKE 'LogicSMS delivery status:%'))))";
    }
    public static function networkFailureSql(string $alias=''): string
    { $a=$alias!==''?$alias.'.':'';return "({$a}state='failed' AND ".self::acceptedSql($alias).')'; }
    public static function submissionFailureSql(string $alias=''): string
    { $a=$alias!==''?$alias.'.':'';return "({$a}state='failed' AND NOT ".self::acceptedSql($alias).')'; }
    public static function technicalStatus(array $message): string
    {
        if($message['state']==='failed'&&!self::accepted($message))return 'FAILED_TO_SEND';
        return strtoupper($message['state']);
    }
    public static function filter(string $state,string $alias='m'): string
    {
        return match($state){
            '', 'all'=>'',
            'pending'=>" AND $alias.state IN ('pending','queued','retry')",
            'failed'=>' AND '.self::networkFailureSql($alias),
            'failed_to_send'=>' AND '.self::submissionFailureSql($alias),
            'sent','dlr_unavailable','delivered','skipped','queued','processing','retry'=>" AND $alias.state='$state'",
            default=>throw new InvalidArgumentException('Invalid delivery filter'),
        };
    }
}
