<?php

require_once __DIR__.'/../services/SmsService.php';
require_once __DIR__.'/ConcurrentSmsProvider.php';

final class LogicSmsProvider implements ConcurrentSmsProvider
{
    private SmsService $service;
    public function __construct(){ $this->service=new SmsService(); }
    public function name():string{return 'logicsms';}
    public function send(string $destination,string $content,?string $senderId=null,?string $clientReference=null):array{return $this->service->send($destination,$content,$senderId,$clientReference);}
    public function createSubmissionHandle(array $message): CurlHandle|array
    {
        return $this->service->createSubmissionHandle($message['destination'],$message['content'],$message['sender_id']??null,'portal-message-'.$message['id']);
    }
    public function parseSubmissionResponse(string $body,int $curlResult,int $httpStatus):array
    {
        // Never pass credential-bearing URLs or raw transport exceptions to logs.
        return SmsService::parseResponse($body,$curlResult===CURLE_OK?'':curl_strerror($curlResult),$httpStatus);
    }
}
