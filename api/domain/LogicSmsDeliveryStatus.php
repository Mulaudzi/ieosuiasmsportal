<?php

final class LogicSmsDeliveryStatus
{
    /** Provider timestamps have no documented timezone or handset-delivery semantics. */
    public static function parse(string $status):?array
    {
        $value=trim($status);$timestamp=null;
        if(preg_match('/^([A-Z_]+)\s+(\d{4})[-\/](\d{2})[-\/](\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/i',$value,$parts)){
            if(!checkdate((int)$parts[3],(int)$parts[4],(int)$parts[2])||(int)$parts[5]>23||(int)$parts[6]>59||(int)$parts[7]>59)return null;
            $value=$parts[1];$timestamp=sprintf('%s-%s-%s %s:%s:%s',...array_slice($parts,2));
        }
        $value=strtoupper($value);
        if(!in_array($value,['CREATE','CREATED','QUEUED','SENT','SUBMITTED','ACCEPTED','DELIVERED','DELIVRD','DELIVERY_SUCCESS','SUCCESS','FAILED','UNDELIVERED','UNDELIV','REJECTED','EXPIRED','ERROR','INVALID','BLOCKED','UNKNOWN','UNAVAILABLE','DLR_UNAVAILABLE','STATUS_UNAVAILABLE'],true))return null;
        return ['status'=>$value,'provider_status_at'=>$timestamp];
    }

    /** Only a definitive status diagnostic, never a failed lookup or arbitrary prose. */
    public static function isBlocked(?string $detail):bool
    {
        $value=preg_replace('/^LogicSMS delivery status:\s*/i','',trim((string)$detail));
        return (self::parse($value)['status']??null)==='BLOCKED';
    }

    public static function normalize(string $status):string
    {
        $value=self::parse($status)['status']??'';
        return match($value){
            'CREATE','CREATED','QUEUED','SENT','SUBMITTED','ACCEPTED'=>'sent',
            'DELIVERED','DELIVRD','DELIVERY_SUCCESS','SUCCESS'=>'delivered',
            'FAILED','UNDELIVERED','UNDELIV','REJECTED','EXPIRED','ERROR','INVALID','BLOCKED'=>'failed',
            'UNKNOWN','UNAVAILABLE','DLR_UNAVAILABLE','STATUS_UNAVAILABLE'=>'dlr_unavailable',
            default=>'unknown',
        };
    }

    public static function shouldApply(string $current,string $incoming,?string $currentDetail=null):bool
    {
        if($current==='delivered'||$current===$incoming)return false;
        // LogicSMS confirmed BLOCKED is final. Retain conflicting later evidence
        // in the event journal, but do not silently reverse this result.
        if($current==='failed'&&self::isBlocked($currentDetail))return false;
        if($current==='failed')return $incoming==='delivered';
        if($current==='dlr_unavailable'&&$incoming==='sent')return false;
        return in_array($incoming,['sent','delivered','failed','dlr_unavailable'],true);
    }
}
