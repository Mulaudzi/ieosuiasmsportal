<?php
/** Transactional sender receipts. SMTP never runs in the wallet transaction. */
final class CreditTransferReceiptService
{
 public static function enqueue(PDO $pdo,array $transfer):void
 {
  if(env('CREDIT_TRANSFER_RECEIPTS_ENABLED','false')!=='true')return;
  if(!$pdo->inTransaction())throw new LogicException('Queue the receipt inside the transfer transaction.');
  $q=$pdo->prepare('SELECT email,email_verified_at FROM users WHERE id=?');$q->execute([$transfer['source_user_id']]);$sender=$q->fetch(PDO::FETCH_ASSOC);
  if(!$sender||empty($sender['email_verified_at'])||!filter_var($sender['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Verified sender email required for the transfer receipt.');
  // A missing queue fails the complete transaction, rather than losing the promised receipt.
  $q=$pdo->prepare("INSERT INTO credit_transfer_receipts(transfer_id,user_id,recipient_email,status,attempts,created_at,updated_at) VALUES(?,?,?,'pending',0,?,?)");
  $q->execute([$transfer['id'],$transfer['source_user_id'],$sender['email'],$transfer['created_at'],$transfer['created_at']]);
 }
 public static function dispatchDue(PDO $pdo,int $limit=25,?callable $send=null):array
 {
  $counts=['processed'=>0,'sent'=>0,'held'=>0];
  if(env('CREDIT_TRANSFER_RECEIPTS_ENABLED','false')!=='true')return $counts+['disabled'=>true];
  if($pdo->inTransaction())throw new LogicException('Email must run outside financial transactions.');
  $now=date('Y-m-d H:i:s');$cutoff=date('Y-m-d H:i:s',time()-600);
  // SMTP acceptance may have happened before a crash. Never automatically resend an unknown outcome.
  $q=$pdo->prepare("UPDATE credit_transfer_receipts SET status='held',updated_at=?,error_fingerprint='worker-expired' WHERE status='processing' AND locked_at<?");$q->execute([$now,$cutoff]);
  $limit=max(1,min(100,$limit));
  $rows=$pdo->query("SELECT transfer_id FROM credit_transfer_receipts WHERE status='pending' ORDER BY created_at,transfer_id LIMIT {$limit}")->fetchAll(PDO::FETCH_COLUMN);
  foreach($rows as $id){
   $q=$pdo->prepare("UPDATE credit_transfer_receipts SET status='processing',locked_at=?,updated_at=?,attempts=attempts+1 WHERE transfer_id=? AND status='pending'");$q->execute([$now,$now,$id]);
   if($q->rowCount()!==1)continue;
   $counts['processed']++;
   try{
    $q=$pdo->prepare('SELECT o.recipient_email,t.id,t.credits,t.created_at,u.email,u.email_verified_at,u.is_active FROM credit_transfer_receipts o JOIN credit_transfers t ON t.id=o.transfer_id AND t.source_user_id=o.user_id JOIN users u ON u.id=o.user_id WHERE o.transfer_id=?');$q->execute([$id]);$job=$q->fetch(PDO::FETCH_ASSOC);
    if(!$job||empty($job['email_verified_at'])||!(int)$job['is_active']||strcasecmp($job['email'],$job['recipient_email'])!==0)throw new RuntimeException('Receipt recipient requires review.');
    if($send===null){require_once __DIR__.'/EmailService.php';$send=[EmailService::class,'sendCreditTransferCompleted'];}
    $result=$send($job['recipient_email'],(int)$job['credits'],$job['id'],$job['created_at']);
    if(empty($result['success']))throw new RuntimeException('Receipt transport unconfirmed.');
    $q=$pdo->prepare("UPDATE credit_transfer_receipts SET status='sent',sent_at=?,updated_at=?,locked_at=NULL,error_fingerprint=NULL WHERE transfer_id=? AND status='processing'");$q->execute([date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),$id]);$counts['sent']++;
   }catch(Throwable $e){
    $q=$pdo->prepare("UPDATE credit_transfer_receipts SET status='held',updated_at=?,error_fingerprint=? WHERE transfer_id=? AND status='processing'");$q->execute([date('Y-m-d H:i:s'),substr(hash('sha256',get_class($e).':'.$e->getMessage()),0,16),$id]);$counts['held']++;
   }
  }
  return $counts;
 }
}
