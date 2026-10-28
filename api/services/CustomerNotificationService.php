<?php
require_once __DIR__.'/../domain/SmsReportingService.php';
/** Reconcile committed business records only. Never participates in sending or payment transactions. */
final class CustomerNotificationService
{
 public static function sync(PDO $pdo,int $userId):void
 {
  $since=date('Y-m-d H:i:s',strtotime('-30 days'));
  $q=$pdo->prepare("SELECT id,name,state,completed_at FROM sms_campaigns c WHERE user_id=? AND state IN ('completed','partially_failed','failed') AND completed_at>=? AND NOT EXISTS(SELECT 1 FROM notification_events e WHERE e.user_id=c.user_id AND e.event_key=CONCAT('campaign:',c.id)) ORDER BY completed_at LIMIT 100");$q->execute([$userId,$since]);
  foreach(SmsReportingService::campaignRecords($userId,$q->fetchAll(PDO::FETCH_ASSOC)) as $c){
   $sent=(int)$c['sent_count'];$failed=(int)$c['failed_submission_count'];
   $title=$sent>0&&$failed===0?'Your SMS campaign has been sent successfully!':'Campaign sending finished';
   $message='Sending has finished for "'.$c['name'].'". '.$sent.' successfully sent, '.(int)$c['skipped_count'].' excluded, '.$failed.' failed submissions. Delivery confirmations update automatically as network reports arrive.';
   self::emit($pdo,$userId,'campaign:'.$c['id'],'campaign_complete',$title,$message,['campaign_id'=>(int)$c['id']],$c['completed_at']);
  }
  $q=$pdo->prepare("SELECT id,name,state,updated_at FROM sms_campaigns c WHERE user_id=? AND updated_at>=? AND NOT EXISTS(SELECT 1 FROM notification_events e WHERE e.user_id=c.user_id AND e.event_key=CONCAT('delivery:',c.id,':complete')) AND EXISTS(SELECT 1 FROM sms_messages m WHERE m.campaign_id=c.id AND m.user_id=c.user_id AND (m.state='delivered' OR ".SmsMessageState::networkFailureSql('m').")) ORDER BY updated_at DESC LIMIT 100");$q->execute([$userId,$since]);
  foreach(SmsReportingService::campaignRecords($userId,$q->fetchAll(PDO::FETCH_ASSOC)) as $c){
   $complete=$c['queued_count']+$c['processing_count']+$c['awaiting_delivery_count']+$c['dlr_unavailable_count']===0;
   self::emit($pdo,$userId,'delivery:'.$c['id'].($complete?':complete':':first'),'delivery_updated','Delivery information updated','We have received delivery information for "'.$c['name'].'". Your report reflects the latest available confirmations.'.($complete?'':' Some confirmations are still pending.'),['campaign_id'=>(int)$c['id']],$c['updated_at']);
  }
  $q=$pdo->prepare("SELECT id,credits_added,processed_at FROM payments p WHERE user_id=? AND status='completed' AND processed_at>=? AND NOT EXISTS(SELECT 1 FROM notification_events e WHERE e.user_id=p.user_id AND e.event_key=CONCAT('payment:',p.id)) ORDER BY processed_at LIMIT 100");$q->execute([$userId,$since]);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p)self::emit($pdo,$userId,'payment:'.$p['id'],'payment_complete','Credit purchase successful',((int)$p['credits_added']>0?number_format((int)$p['credits_added']).' SMS credits were added. ':'').'Open your wallet for the balance and payment details.',['payment_id'=>(int)$p['id']],$p['processed_at']);
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
