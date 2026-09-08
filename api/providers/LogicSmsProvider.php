<?php

require_once __DIR__.'/../services/SmsService.php';

final class LogicSmsProvider implements SmsProvider
{
    private SmsService $service;
    public function __construct(){ $this->service=new SmsService(); }
    public function name():string{return 'logicsms';}
    public function send(string $destination,string $content,?string $senderId=null,?string $clientReference=null):array{return $this->service->send($destination,$content,$senderId,$clientReference);}
}
