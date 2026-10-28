<?php
declare(strict_types=1);
require_once __DIR__.'/SmsDispatchService.php';

/** A rejection of today's recovery request cannot disprove yesterday's acceptance. */
final class SmsRecoveryProvider implements SmsProvider
{
    public function __construct(private SmsProvider $inner){}
    public function name():string{return $this->inner->name();}
    public function send(string $destination,string $content,?string $senderId=null,?string $clientReference=null):array
    {
        $result=$this->inner->send($destination,$content,$senderId,$clientReference);
        if(empty($result['success']))$result['uncertain']=true;
        return $result;
    }
}

/** Operator-only recovery. Caller must hold the same singleton lock as the worker. */
final class SmsHeldRecovery
{
    public static function eligible(array $row,int $ageSeconds):bool
    {
        return $ageSeconds>=0 && $ageSeconds<86400 && $row['state']==='retry'
            && (int)$row['attempt_count']===1 && (int)($row['retry_generation']??0)===0
            && $row['next_attempt_at']==='9999-12-31 23:59:59' && empty($row['lease_token'])
            && empty($row['sent_at']) && empty($row['provider_message_id']) && (float)$row['actual_charge']===0.0
            && str_starts_with((string)$row['last_error'],'Submission started; awaiting provider acknowledgement;')
            && !str_contains((string)$row['last_error'],'Provider accepted reference');
    }

    public static function retryOne(PDO $pdo,int $userId,int $campaignId,int $messageId,SmsProvider $provider):array
    {
        // LogicSMS documents a two-day duplicate window. Limit this one-off
        // recovery to the first 24 hours since creation, including all queue time.
        if($provider->name()!=='logicsms'||env('LOGICSMS_API_URL','https://www.logicsms.co.za/postmsg2.aspx')!=='https://www.logicsms.co.za/postmsg2.aspx')throw new DomainException('Recovery requires the documented LogicSMS endpoint');
        // sms_messages.lease_token is CHAR(36), shared with the normal worker.
        $lease=bin2hex(random_bytes(16));
        $pdo->beginTransaction();
        try{
            $s=$pdo->prepare("SELECT m.*,TIMESTAMPDIFF(SECOND,m.created_at,NOW()) recovery_age FROM sms_messages m JOIN sms_campaigns c ON c.id=m.campaign_id AND c.user_id=m.user_id WHERE m.id=? AND m.user_id=? AND m.campaign_id=? AND c.state IN ('queued','processing') FOR UPDATE");
            $s->execute([$messageId,$userId,$campaignId]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row||!self::eligible($row,(int)$row['recovery_age']))throw new DomainException('Message changed, already attempted, or outside the safe duplicate-check window');
            $s=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN direction='reserve' THEN amount WHEN direction IN ('debit','release') THEN -amount ELSE 0 END),0) FROM wallet_ledger WHERE user_id=? AND campaign_id=?");$s->execute([$userId,$campaignId]);
            $outstanding=(float)$s->fetchColumn();
            $s=$pdo->prepare('SELECT reserved,balance FROM wallets WHERE user_id=? FOR UPDATE');$s->execute([$userId]);$wallet=$s->fetch(PDO::FETCH_ASSOC);
            $s=$pdo->prepare('SELECT COUNT(*) FROM wallet_ledger WHERE idempotency_key=?');$s->execute(['sms-message:'.$messageId.':settle']);
            if($s->fetchColumn()||!$wallet||(float)$row['estimated_charge']<=0||min($outstanding,(float)$wallet['reserved'],(float)$wallet['balance'])+0.00001<(float)$row['estimated_charge'])throw new DomainException('Unspent reservation could not be verified');
            // Mandatory journal: if unavailable, abort before any network side effect.
            $s=$pdo->prepare("INSERT INTO operational_logs(level,service,event,message,campaign_id,sms_message_id,user_id,context_json,created_at) VALUES ('warning','sms-recovery','duplicate_protected_retry','Operator requested one duplicate-protected recovery attempt',?,?,?,?,NOW())");
            $s->execute([$campaignId,$messageId,$userId,json_encode(['original_state'=>$row['state'],'original_error'=>$row['last_error'],'original_error_sha256'=>hash('sha256',(string)$row['last_error']),'original_attempt_count'=>1,'unique'=>'portal-message-'.$messageId,'duplicate_check'=>'Y','recovery_window_seconds'=>86400],JSON_THROW_ON_ERROR)]);
            $s=$pdo->prepare("UPDATE sms_messages SET state='processing',lease_token=?,leased_at=NOW(),attempt_count=attempt_count+1,updated_at=NOW() WHERE id=? AND user_id=? AND campaign_id=? AND state='retry' AND attempt_count=1");$s->execute([$lease,$messageId,$userId,$campaignId]);
            if($s->rowCount()!==1)throw new DomainException('Concurrent recovery prevented');
            $pdo->commit();
            $row['state']='processing';$row['lease_token']=$lease;$row['attempt_count']=2;
            try{SmsDispatchService::process($row,new SmsRecoveryProvider($provider));}
            catch(Throwable){SmsDispatchService::releaseMessageLease($messageId,$lease,'Recovery interrupted; original reference must be reconciled.');}
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $s=$pdo->prepare('SELECT id,state,attempt_count,actual_charge,last_error,provider_message_id,sent_at FROM sms_messages WHERE id=? AND user_id=? AND campaign_id=?');$s->execute([$messageId,$userId,$campaignId]);$after=$s->fetch(PDO::FETCH_ASSOC);
        return ['id'=>$messageId,'state'=>$after['state'],'attempt_count'=>(int)$after['attempt_count'],'actual_charge'=>$after['actual_charge'],'provider_reference_saved'=>!empty($after['provider_message_id']),'sent_at'=>$after['sent_at'],'error'=>$after['last_error']];
    }
}
