<?php
require_once __DIR__.'/../domain/CentralSecurityEvents.php';
final class CentralSecurityEventController
{
 public function receive():void
 {
  header('Cache-Control: no-store');
  RateLimiter::checkOrFail('central_security_events:'.hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'')),120,1);
  $body=(string)file_get_contents('php://input',false,null,0,8193);
  try{Response::success(CentralSecurityEvents::receive(db(),$body,(string)($_SERVER['HTTP_X_IEOSUIA_TIMESTAMP']??''),(string)($_SERVER['HTTP_X_IEOSUIA_SIGNATURE']??'')));}
  catch(SmsLoginSecurityException $e){Response::error($e->getMessage(),$e->status);}
 }
}
