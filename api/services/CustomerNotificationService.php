<?php
require_once __DIR__.'/../domain/SmsReportingService.php';
/** Reconcile committed business records only. Never participates in sending or payment transactions. */
final class CustomerNotificationService
{
 public static function sync(PDO $pdo,int $userId):array
 {
  if(!self::tableExists($pdo,'notification_events'))throw new RuntimeException('Notification schema setup is required.');
  $failed=[];
  $run=static function(string $stage,callable $task)use(&$failed):void{
   try{$task();}catch(Throwable $e){
    $failed[]=$stage;
    error_log('Notification reconciliation stage='.$stage.' fingerprint='.substr(hash('sha256',get_class($e).':'.$e->getMessage()),0,16));
   }
  };
  $since=date('Y-m-d H:i:s',strtotime('-30 days'));
  $run('campaigns',static function()use($pdo,$userId,$since):void{
  $q=$pdo->prepare("SELECT id,name,state,completed_at FROM sms_campaigns c WHERE user_id=? AND state IN ('completed','partially_failed','failed') AND completed_at>=? AND NOT EXISTS(SELECT 1 FROM notification_events e WHERE e.user_id=c.user_id AND e.event_key=CONCAT('campaign:',c.id)) ORDER BY completed_at LIMIT 100");$q->execute([$userId,$since]);
  foreach(SmsReportingService::campaignRecords($userId,$q->fetchAll(PDO::FETCH_ASSOC)) as $c){
   $sent=(int)$c['sent_count'];$failed=(int)$c['failed_submission_count'];
   $title=$sent>0&&$failed===0?'Your SMS campaign has been sent successfully!':'Campaign sending finished';
   $message='Sending has finished for "'.$c['name'].'". '.$sent.' successfully sent, '.(int)$c['skipped_count'].' excluded, '.$failed.' failed submissions. Delivery confirmations update automatically as network reports arrive.';
   self::emit($pdo,$userId,'campaign:'.$c['id'],'campaign_complete',$title,$message,['campaign_id'=>(int)$c['id']],$c['completed_at']);
  }
  });
  $run('delivery',static function()use($pdo,$userId,$since):void{
  $q=$pdo->prepare("SELECT id,name,state,updated_at FROM sms_campaigns c WHERE user_id=? AND updated_at>=? AND NOT EXISTS(SELECT 1 FROM notification_events e WHERE e.user_id=c.user_id AND e.event_key=CONCAT('delivery:',c.id,':complete')) AND EXISTS(SELECT 1 FROM sms_messages m WHERE m.campaign_id=c.id AND m.user_id=c.user_id AND (m.state='delivered' OR ".SmsMessageState::networkFailureSql('m').")) ORDER BY updated_at DESC LIMIT 100");$q->execute([$userId,$since]);
  foreach(SmsReportingService::campaignRecords($userId,$q->fetchAll(PDO::FETCH_ASSOC)) as $c){
   $complete=$c['queued_count']+$c['processing_count']+$c['awaiting_delivery_count']+$c['dlr_unavailable_count']===0;
   self::emit($pdo,$userId,'delivery:'.$c['id'].($complete?':complete':':first'),'delivery_updated','Delivery information updated','We have received delivery information for "'.$c['name'].'". Your report reflects the latest available confirmations.'.($complete?'':' Some confirmations are still pending.'),['campaign_id'=>(int)$c['id']],$c['updated_at']);
  }
  });
  $run('purchases',static function()use($pdo,$userId,$since):void{
  $q=$pdo->prepare("SELECT id,credits_added,processed_at FROM payments p WHERE user_id=? AND status='completed' AND processed_at>=? AND NOT EXISTS(SELECT 1 FROM notification_events e WHERE e.user_id=p.user_id AND e.event_key=CONCAT('payment:',p.id)) ORDER BY processed_at LIMIT 100");$q->execute([$userId,$since]);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p)self::emit($pdo,$userId,'payment:'.$p['id'],'payment_complete','Credit purchase successful',((int)$p['credits_added']>0?number_format((int)$p['credits_added']).' SMS credits were added. ':'').'Open your wallet for the balance and payment details.',['payment_id'=>(int)$p['id']],$p['processed_at']);
  });
  $run('transfers',static function()use($pdo,$userId,$since):void{
  if(self::tableExists($pdo,'credit_transfers')){
   $q=$pdo->prepare("SELECT id,source_user_id,destination_user_id,credits,created_at FROM credit_transfers t WHERE (source_user_id=? OR destination_user_id=?) AND created_at>=? AND NOT EXISTS(SELECT 1 FROM notification_events e WHERE e.user_id=? AND e.event_key=CONCAT('transfer:',t.id)) ORDER BY created_at LIMIT 100");$q->execute([$userId,$userId,$since,$userId]);
   foreach($q->fetchAll(PDO::FETCH_ASSOC) as $t){$received=(int)$t['destination_user_id']===$userId;
    self::emit($pdo,$userId,'transfer:'.$t['id'],$received?'credits_received':'credits_transferred',$received?'Credits received':'Credit transfer completed',number_format((int)$t['credits']).' SMS credits '.($received?'were received.':'were transferred.').' Open your wallet for the transfer record.',['transfer_id'=>$t['id']],$t['created_at']);
   }
  }
  });
  $run('security',static function()use($pdo,$userId,$since):void{
  if(self::tableExists($pdo,'portal_security_events')){
   $q=$pdo->prepare("SELECT event_key,event_type,created_at FROM portal_security_events s WHERE user_id=? AND created_at>=? AND NOT EXISTS(SELECT 1 FROM notification_events e WHERE e.user_id=s.user_id AND e.event_key=CONCAT('security:',s.event_key)) ORDER BY created_at LIMIT 100");$q->execute([$userId,$since]);
   foreach($q->fetchAll(PDO::FETCH_ASSOC) as $s){$copy=self::securityCopy($s['event_type']);if($copy)self::emit($pdo,$userId,'security:'.$s['event_key'],'account_security',$copy[0],$copy[1],['section'=>'security'],$s['created_at']);}
  }
  });
  return ['available'=>$failed===[]];
 }
 public static function tableExists(PDO $pdo,string $name):bool
 {
  $q=$pdo->prepare($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?":"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$name]);return (int)$q->fetchColumn()===1;
 }
 public static function securityCopy(string $type):?array
 {
  return match($type){
   'two_step_enabled'=>['Extra sign-in check enabled','Email sign-in verification was enabled for the SMS Portal only. Review Security settings if this was not you.'],
   'two_step_disabled'=>['Extra sign-in check disabled','Email sign-in verification was disabled for the SMS Portal. Review Security settings if this was not you.'],
   'password_changed'=>['IEOSUIA password changed','Central IEOSUIA authentication confirmed a password change. If this was not you, secure your account through the official sign-in page and contact support.'],
   'recovery_code_used'=>['Recovery code used','A recovery code was used to sign in to the SMS Portal. Review Security settings if this was not you.'],
   default=>null,
  };
 }
 public static function emit(PDO $pdo,int $userId,string $key,string $type,string $title,string $message,array $data,string $created):bool
 {
  if($pdo->inTransaction())throw new LogicException('Notifications must run outside business transactions');
  $pdo->beginTransaction();$claimed=false;
  try{
   $q=$pdo->prepare('INSERT INTO notification_events(user_id,event_key,created_at) VALUES(?,?,?)');$q->execute([$userId,$key,$created]);$claimed=true;
   $q=$pdo->prepare('INSERT INTO notifications(user_id,type,title,message,data,`read`,created_at,updated_at) VALUES(?,?,?,?,?,0,?,?)');$q->execute([$userId,$type,$title,$message,json_encode($data,JSON_THROW_ON_ERROR),$created,$created]);
   $pdo->commit();return true;
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(!$claimed && $e instanceof PDOException && (($e->errorInfo[1]??null)===1062 || ($e->errorInfo[1]??null)===19))return false;throw $e;}
 }
}
