<?php
/** Reconcile committed business records only. Never participates in sending or payment transactions. */
final class CustomerNotificationService
{
 public static function sync(PDO $pdo,int $userId):void
 {
  $since=date('Y-m-d H:i:s',strtotime('-30 days'));
  $q=$pdo->prepare("SELECT id,name,completed_at FROM sms_campaigns c WHERE user_id=? AND state IN ('completed','partially_failed','failed') AND completed_at>=? AND NOT EXISTS(SELECT 1 FROM notification_events e WHERE e.user_id=c.user_id AND e.event_key=CONCAT('campaign:',c.id)) ORDER BY completed_at LIMIT 100");$q->execute([$userId,$since]);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $c)self::emit($pdo,$userId,'campaign:'.$c['id'],'campaign_complete','Campaign sending finished','Sending has finished for "'.$c['name'].'". Open the delivery breakdown for recipient outcomes. Sending finished does not mean every SMS was delivered.',['campaign_id'=>(int)$c['id']],$c['completed_at']);
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
