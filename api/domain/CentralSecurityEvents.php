<?php
require_once __DIR__.'/SmsLoginSecurity.php';
/** Receiver contract only: Central Auth publishing is a separate integration. */
final class CentralSecurityEvents
{
 public static function receive(PDO $pdo,string $body,string $timestamp,string $signature,?int $now=null):array
 {
  $now??=time();$secret=(string)env('CENTRAL_SECURITY_EVENTS_SECRET','');
  if(env('CENTRAL_SECURITY_EVENTS_ENABLED','false')!=='true'||strlen($secret)<32)throw new SmsLoginSecurityException('Security event integration is not enabled.',503);
  if(strlen($body)>8192||!preg_match('/^[0-9]{10}$/D',$timestamp)||abs($now-(int)$timestamp)>300||!preg_match('/^[a-f0-9]{64}$/D',$signature)||!hash_equals(hash_hmac('sha256','sms-security-v1|'.$timestamp.'|'.$body,$secret),$signature))throw new SmsLoginSecurityException('Invalid security event.',401);
  try{$event=json_decode($body,true,8,JSON_THROW_ON_ERROR);}catch(JsonException){throw new SmsLoginSecurityException('Invalid security event.');}
  if(!is_array($event)||array_diff(array_keys($event),['id','type','subject','application','occurred_at'])||($event['type']??null)!=='password_changed'||($event['application']??null)!=='sms'||!is_string($event['id']??null)||!preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$event['id'])||!is_string($event['subject']??null)||!preg_match('/^[a-f0-9-]{36}$/Di',$event['subject'])||!is_string($event['occurred_at']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$event['occurred_at']))throw new SmsLoginSecurityException('Invalid security event.');
  $occurred=strtotime($event['occurred_at']);if(!$occurred||$occurred>$now+300||$occurred<$now-2592000||gmdate('Y-m-d\TH:i:s\Z',$occurred)!==$event['occurred_at'])throw new SmsLoginSecurityException('Invalid security event time.');
  $key='central:'.$event['id'];$hash=hash('sha256',$body);$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
  $pdo->beginTransaction();
  try{
   $q=$pdo->prepare('SELECT id FROM users WHERE identity_uuid=?'.$lock);$q->execute([$event['subject']]);$users=$q->fetchAll(PDO::FETCH_COLUMN);
   if(count($users)!==1)throw new SmsLoginSecurityException('Security event identity not available.',409);
   $user=(int)$users[0];$q=$pdo->prepare('SELECT user_id,payload_hash FROM portal_security_events WHERE event_key=?');$q->execute([$key]);$old=$q->fetch(PDO::FETCH_ASSOC);
   if($old){if((int)$old['user_id']!==$user||!hash_equals($old['payload_hash']??'',$hash))throw new SmsLoginSecurityException('Security event conflicts with an existing record.',409);$pdo->commit();return ['accepted'=>true,'duplicate'=>true];}
   $pdo->prepare('INSERT INTO portal_security_events(event_key,user_id,event_type,payload_hash,created_at) VALUES(?,?,?,?,?)')->execute([$key,$user,'password_changed',$hash,date('Y-m-d H:i:s',$occurred)]);
   // Invalidate this app's sessions once, without changing the shared password.
   $pdo->prepare('UPDATE users SET auth_version=COALESCE(auth_version,1)+1 WHERE id=?')->execute([$user]);
   $pdo->commit();return ['accepted'=>true,'duplicate'=>false];
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 }
}
