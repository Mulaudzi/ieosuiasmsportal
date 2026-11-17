<?php
declare(strict_types=1);
require_once __DIR__.'/../domain/EmailAwsSignature.php';
require_once __DIR__.'/../domain/EmailAwsPolicy.php';
final class EmailAwsClient {
    public function __construct(private $transport=null){}
    public function call(array $actor,string $operation,array $data=[]):array {
        $url=rtrim((string)env('EMAIL_AWS_API_URL',''),'/');$key=(string)env('EMAIL_AWS_PORTAL_KEY','');
        if(!preg_match('~^https://[a-z0-9]{20,64}\.lambda-url\.eu-north-1\.on\.aws$~D',$url)||strlen($key)<32)throw new EmailAwsException('AWS email connection is awaiting setup',503);
        $body=json_encode(['actor'=>$actor,'operation'=>$operation,'data'=>$data===[]?new stdClass():$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($body)>400000)throw new EmailAwsException('Split this campaign into a smaller audience',413);
        $headers=EmailAwsSignature::sign($key,'email-campaign-api','/service',$body);
        if($this->transport){$result=($this->transport)($url.'/service',$headers,$body);}
        else {
            $curl=curl_init($url.'/service');$raw='';
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>55,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>1500000)return 0;$raw.=$chunk;return strlen($chunk);}]);
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
            if($ok===false)throw new EmailAwsException('AWS email response was not confirmed. Refresh before retrying; do not create another campaign.',503);
            $result=['status'=>$status,'body'=>$raw];
        }
        try{$reply=json_decode($result['body'],true,32,JSON_THROW_ON_ERROR);}catch(Throwable){throw new EmailAwsException('AWS email response unavailable; refresh before retrying',503);}
        if(($result['status']??0)!==200||($reply['success']??false)!==true){$status=in_array($result['status']??0,[400,403,404,409,413,422,429,503],true)?$result['status']:503;throw new EmailAwsException('The email operation could not finish. Refresh the campaign status before retrying.',$status);}
        return$reply;
    }
}
