<?php
require_once __DIR__.'/../domain/SmsLoginSecurity.php';
final class SmsLoginSecurityController
{
 private function service():SmsLoginSecurity{return new SmsLoginSecurity(db());}
 private function response(callable $fn):void{
  header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
  try{Response::success($fn());}catch(SmsLoginSecurityException $e){Response::error($e->getMessage(),$e->status);}
 }
 public function status():void{$this->response(fn()=>$this->service()->status((int)Auth::id()));}
 public function request():void{
  RateLimiter::checkOrFail('sms_security_request:'.Auth::id(),10,15);
  $this->response(function(){
   $purpose=Request::input('purpose');if(!is_string($purpose)||!in_array($purpose,['enable','disable'],true))throw new SmsLoginSecurityException('Choose enable or disable.');
   require_once __DIR__.'/../services/EmailService.php';$payload=JWT::decode(Request::bearerToken())?:[];
   return $this->service()->request(Auth::user(),$purpose,Request::bearerToken(),static fn($email,$code,$p)=>(EmailService::sendSmsLoginCode($email,$code,$p)['success']??false)===true,$payload);
  });
 }
 public function confirm():void{
  RateLimiter::checkOrFail('sms_security_verify:'.Auth::id(),15,15);
  $this->response(function(){
   $input=Request::input();foreach(['challenge','code','purpose']as$key)if(!is_string($input[$key]??null))throw new SmsLoginSecurityException('Enter the verification code.');
   if(!in_array($input['purpose'],['enable','disable'],true))throw new SmsLoginSecurityException('Invalid security action.');
   $r=$this->service()->verify($input['challenge'],$input['code'],$input['purpose'],Request::bearerToken(),(int)Auth::id());
   return ['token'=>Auth::generateToken($r['user']),'user'=>Auth::formatUserForFrontend($r['user']),'recovery_codes'=>$r['recovery_codes'],'enabled'=>$input['purpose']==='enable'];
  });
 }
 public function login():void{
  RateLimiter::checkOrFail('sms_login_verify_ip:'.hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'')),30,15);
  $this->response(function(){
   $input=Request::input();if(!is_string($input['challenge']??null)||!is_string($input['code']??null))throw new SmsLoginSecurityException('Enter the verification code.');
   $binding=$_COOKIE['sms_login_binding']??'';if(!is_string($binding)||!preg_match('/^[a-f0-9]{64}$/D',$binding))throw new SmsLoginSecurityException('Start secure sign-in again.');
   $r=$this->service()->verify($input['challenge'],$input['code'],'login',$binding);
   setcookie('sms_login_binding','',['expires'=>1,'path'=>'/api/auth/two-step','secure'=>(($_SERVER['HTTPS']??'')!==''&&$_SERVER['HTTPS']!=='off'),'httponly'=>true,'samesite'=>'Strict']);
   return ['token'=>Auth::generateToken($r['user']),'user'=>Auth::formatUserForFrontend($r['user'])];
  });
 }
}
