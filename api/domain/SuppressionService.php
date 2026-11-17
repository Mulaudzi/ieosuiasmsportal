<?php

require_once __DIR__.'/PhoneNumber.php';

final class SuppressionService
{
    public static function normalizedForUser(int $userId):array
    {
        $rows=table('opt_outs')->where('user_id',$userId)->where('channel','sms')->get();
        $normalized=[];
        foreach($rows as $row){
            try{$phone=PhoneNumber::normalize((string)($row['recipient']??''));$normalized[$phone]=true;}
            catch(InvalidArgumentException){/* Preserve malformed history; it cannot match a sendable E.164 destination. */}
        }
        // Workers are long-lived: never cache this list across messages.
        // A newly created opt-out must suppress the next claimed recipient.
        return $normalized;
    }

    public static function contains(int $userId,string $destination):bool
    {
        try{$normalized=PhoneNumber::normalize($destination);}catch(InvalidArgumentException){return true;}
        return isset(self::normalizedForUser($userId)[$normalized]);
    }
}
