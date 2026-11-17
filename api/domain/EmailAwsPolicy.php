<?php
declare(strict_types=1);
/** Small portal accounting primitives. Campaign content/rendering/dispatch stay on AWS. */
final class EmailAwsException extends RuntimeException {
    public function __construct(string $message,public readonly int $status=422){parent::__construct($message);}
}
final class EmailAwsPolicy {
    public static function email(string $raw):?string {
        $value=strtolower(trim($raw));return strlen($value)<=254&&filter_var($value,FILTER_VALIDATE_EMAIL)&&!preg_match('/[\r\n\x00]/',$value)?$value:null;
    }
    public static function unitCents():int {
        $price=trim((string)env('SMS_PRICE_PER_SEGMENT',''));
        if(!preg_match('/^\d{1,5}(?:\.\d{1,4})?$/D',$price))throw new EmailAwsException('Email pricing is not configured',503);
        [$whole,$fraction]=array_pad(explode('.',$price),2,'');$units=(int)$whole*10000+(int)str_pad($fraction,4,'0');
        if($units<=0||$units%500!==0)throw new EmailAwsException('Five emails per credit must be representable in whole cents',503);
        return intdiv($units,500);
    }
    public static function moneyUnits(string $value):int {
        if(!preg_match('/^(\d{1,10})(?:\.(\d{1,4}))?$/D',$value,$m))throw new EmailAwsException('Wallet reconciliation is required',503);
        return(int)$m[1]*10000+(int)str_pad($m[2]??'',4,'0');
    }
    public static function amount(int $cents):string{return sprintf('%d.%02d00',intdiv($cents,100),$cents%100);}
}
