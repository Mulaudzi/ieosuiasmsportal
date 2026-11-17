<?php
declare(strict_types=1);
require_once __DIR__.'/EmailAwsPolicy.php';
/** Consume ownership codes on the server; never return or log them in a browser response. */
final class EmailReplyToBridge {
    public static function request(array $data,callable $service,callable $send):array {
        $address=EmailAwsPolicy::email((string)($data['email']??''));
        if(!$address)throw new EmailAwsException('Enter a valid reply inbox address');
        $result=$service('request-reply-to',['email'=>$address]);$delivery=$result['verification_delivery']??null;
        if(!is_array($delivery)||($delivery['email']??null)!==$address||!is_string($delivery['id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$delivery['id'])||!is_string($delivery['code']??null)||!preg_match('/^[0-9]{6}$/D',$delivery['code']))throw new EmailAwsException('Verification could not be prepared; refresh before retrying',503);
        $sent=false;
        try{$sent=($send($address,$delivery['code'])['success']??false)===true;}catch(Throwable){}
        // A failed or ambiguous handoff consumes its allowance and never grants ownership.
        try{$confirmed=$service('reply-to-delivery',['id'=>$delivery['id'],'delivered'=>$sent]);}
        catch(Throwable){throw new EmailAwsException('Verification delivery was not confirmed. Refresh the reply-address status before requesting another code.',503);}
        if(!$sent)throw new EmailAwsException('The verification email could not be sent. Wait before requesting another code.',503);
        return array_intersect_key($confirmed,array_flip(['account_email','addresses','pending','max_addresses']));
    }
}
