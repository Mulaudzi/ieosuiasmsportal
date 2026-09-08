<?php

final class LogicSmsDeliveryStatus
{
    public static function normalize(string $status):string
    {
        $value=strtoupper(trim($status));
        return match($value){
            'CREATE','CREATED','QUEUED','SENT','SUBMITTED','ACCEPTED'=>'sent',
            'DELIVERED','DELIVRD','DELIVERY_SUCCESS','SUCCESS'=>'delivered',
            'FAILED','UNDELIVERED','UNDELIV','REJECTED','EXPIRED','ERROR'=>'failed',
            default=>'unknown',
        };
    }
}
