<?php
declare(strict_types=1);

require_once __DIR__.'/../services/SmsService.php';
require_once __DIR__.'/LogicSmsDeliveryStatus.php';

/** Bounded, read-only provider queries; never sends SMS or creates reservations. */
final class LogicSmsStatusSync
{
    public const WORKER_LOCK='ieosuia-sms-dispatch-worker';
    private const MAX_ATTEMPTS=12;
    private const HOLD='9999-12-31 23:59:59';

    /** Reconcile existing authenticated callback evidence without any network. */
    public static function runStored(PDO $pdo,int $limit=20,?float $deadline=null):array
    {
        if($pdo->inTransaction())throw new DomainException('Stored status reconciliation requires its own transaction');
        $limit=max(1,min(100,$limit));$deadline??=microtime(true)+20;
        $counts=['selected'=>0,'processed'=>0,'applied'=>0,'duplicates'=>0,'unchanged'=>0,'invalid'=>0,'requires_investigation'=>0,'busy'=>0,'errors'=>0,'stopped_reason'=>null,'deadline_reached'=>false,'provider_queries'=>0,'sms_submitted'=>false];
        if(microtime(true)>=$deadline){$counts['deadline_reached']=true;return $counts;}
        // Only the canonical original reference is safe for unaccepted rows.
        // Numeric acknowledgements can collide and must never be guessed here.
        $q=$pdo->prepare("SELECT e.id event_row_id,e.provider_event_id,e.provider_message_id,e.payload_json,m.id,m.user_id,m.campaign_id FROM sms_provider_events e JOIN sms_messages m ON e.provider_message_id=CONCAT('portal-message-',m.id) JOIN sms_campaigns c ON c.id=m.campaign_id AND c.user_id=m.user_id WHERE e.provider='logicsms' AND e.processed_at IS NULL AND ".self::heldSql()." AND m.lease_token IS NULL AND m.leased_at IS NULL ORDER BY CASE e.event_type WHEN 'delivery.delivered' THEN 0 WHEN 'delivery.failed' THEN 1 ELSE 2 END,e.id DESC LIMIT {$limit}");
        $q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);$counts['selected']=count($rows);
        foreach($rows as $row){
            if(microtime(true)>=$deadline){$counts['deadline_reached']=true;break;}
            $reference='portal-message-'.(int)$row['id'];$valid=$row['provider_message_id']===$reference;
            try{
                if(strlen((string)$row['payload_json'])>65536)throw new DomainException('Callback payload exceeds the safe processing bound');
                $payload=json_decode((string)$row['payload_json'],true,32,JSON_THROW_ON_ERROR);
                if(!is_array($payload))throw new DomainException('Callback payload is not an object');
                foreach(['Unique','unique','reference'] as $key)if(array_key_exists($key,$payload)&&$payload[$key]!==$reference)$valid=false;
                $status=null;
                foreach(['Status','status','dlr_status'] as $key){
                    if(!array_key_exists($key,$payload))continue;
                    $parsed=is_string($payload[$key])?LogicSmsDeliveryStatus::parse($payload[$key]):null;
                    if(!$parsed||$status!==null&&$status!==$parsed['status']){$valid=false;break;}
                    $status=$parsed['status'];
                }
                if($status===null||!self::heldAcceptanceStatus($status))$valid=false;
            }catch(Throwable){$valid=false;}
            if(!$valid){$counts['invalid']++;$counts['requires_investigation']++;continue;}
            $lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([self::WORKER_LOCK]);
            if((int)$lock->fetchColumn()!==1){$counts['busy']++;continue;}
            try{
                if(!class_exists('SmsStatusReconciliation',false))require_once __DIR__.'/SmsStatusReconciliation.php';
                $result=SmsStatusReconciliation::apply($pdo,(int)$row['id'],[
                    'source'=>'authenticated_callback','source_event_id'=>(string)$row['provider_event_id'],
                    'status'=>$status,'original_reference'=>$reference,'user_id'=>(int)$row['user_id'],
                    'campaign_id'=>(int)$row['campaign_id'],'observed_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
                    'payload_sha256'=>hash('sha256',(string)$row['payload_json']),
                ]);
                if(empty($result['ok'])||empty($result['accepted']))throw new DomainException('Callback did not establish verified acceptance');
                $processed=$pdo->prepare("UPDATE sms_provider_events SET processed_at=NOW() WHERE id=? AND provider='logicsms' AND provider_message_id=? AND processed_at IS NULL");
                $processed->execute([(int)$row['event_row_id'],$reference]);
                $counts['processed']++;
                if(!empty($result['applied']))$counts['applied']++;
                elseif(!empty($result['duplicate']))$counts['duplicates']++;
                else $counts['unchanged']++;
            }catch(Throwable){$counts['errors']++;$counts['requires_investigation']++;$counts['stopped_reason']='RECONCILIATION_UNAVAILABLE';}
            finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([self::WORKER_LOCK]);}
            if($counts['stopped_reason']!==null)break;
        }
        return $counts;
    }

    /**
     * A null scope processes eligible messages across accounts. An explicit empty
     * scope processes nothing. Caller owns authorization for a campaign scope.
     * Deadline is a wall-clock microtime(true) value; requests already in flight
     * cannot be interrupted here and remain subject to the gateway timeout.
     */
    public static function run(PDO $pdo,SmsService $gateway,int $limit=10,?float $deadline=null,?array $campaignIds=null):array
    {
        if($pdo->inTransaction())throw new DomainException('Status queries require no active database transaction');
        $owns=$pdo->prepare('SELECT IS_USED_LOCK(?)=CONNECTION_ID()');$owns->execute([self::WORKER_LOCK]);
        if((int)$owns->fetchColumn()===1)throw new DomainException('Release the dispatch worker lock before status queries');
        $limit=max(1,min(20,$limit));$deadline??=microtime(true)+20;
        $scope=self::scope($campaignIds);
        $counts=['selected'=>0,'attempted'=>0,'applied'=>0,'duplicates'=>0,'unchanged'=>0,'missing'=>0,'unavailable'=>0,'requires_investigation'=>0,'busy'=>0,'errors'=>0,'stopped_reason'=>null,'deadline_reached'=>false,'global_cooldown'=>false,'cooldown_seconds'=>300,'limit'=>$limit,'scoped_campaign_ids'=>$scope,'sms_submitted'=>false];
        if($scope===[])return $counts;
        if(microtime(true)>=$deadline){$counts['deadline_reached']=true;return $counts;}
        // A known missing lookup may be a broken identifier/account contract,
        // not one bad recipient. Back off the whole gateway instead of walking
        // through every historical row once per cron invocation. The durable
        // warning audit doubles as a five-minute circuit, without new schema.
        try{
            $circuit=$pdo->prepare("SELECT COUNT(*) FROM operational_logs WHERE service='sms-status-sync' AND event='status_lookup' AND level='warning' AND TIMESTAMPDIFF(SECOND,created_at,NOW()) BETWEEN 0 AND 299");
            $circuit->execute();
            if((int)$circuit->fetchColumn()>0){$counts['global_cooldown']=true;$counts['stopped_reason']='GLOBAL_COOLDOWN';return $counts;}
        }catch(Throwable){$counts['errors']++;$counts['requires_investigation']++;$counts['stopped_reason']='CIRCUIT_UNAVAILABLE';return $counts;}
        $where=self::eligibleSql();$args=[];
        if($scope!==null){$where.=' AND m.campaign_id IN ('.implode(',',array_fill(0,count($scope),'?')).')';$args=$scope;}
        $select=$pdo->prepare("SELECT m.id,m.user_id,m.campaign_id,m.state,COALESCE(m.dlr_attempt_count,0) dlr_attempt_count,m.last_dlr_checked_at FROM sms_messages m JOIN sms_campaigns c ON c.id=m.campaign_id AND c.user_id=m.user_id WHERE {$where} ORDER BY COALESCE(m.last_dlr_checked_at,'1000-01-01 00:00:00'),m.id LIMIT {$limit}");
        $select->execute($args);$rows=$select->fetchAll(PDO::FETCH_ASSOC);$counts['selected']=count($rows);
        foreach($rows as $row){
            if(microtime(true)>=$deadline){$counts['deadline_reached']=true;break;}
            // Claim only lookup metadata, never a sending lease or outcome. A
            // competing poller cannot query this row again within its backoff.
            $claim=$pdo->prepare('UPDATE sms_messages AS m SET last_dlr_checked_at=NOW(),dlr_attempt_count=COALESCE(dlr_attempt_count,0)+1 WHERE m.id=? AND COALESCE(m.dlr_attempt_count,0)=? AND m.last_dlr_checked_at <=> ? AND '.self::eligibleSql());
            $claim->bindValue(1,(int)$row['id'],PDO::PARAM_INT);
            $claim->bindValue(2,(int)$row['dlr_attempt_count'],PDO::PARAM_INT);
            $claim->bindValue(3,$row['last_dlr_checked_at'],$row['last_dlr_checked_at']===null?PDO::PARAM_NULL:PDO::PARAM_STR);
            $claim->execute();
            if($claim->rowCount()!==1)continue;
            $counts['attempted']++;$reference='portal-message-'.(int)$row['id'];
            try{$result=$gateway->getStatus('',$reference);}
            catch(Throwable){$result=['success'=>false,'error_code'=>'TRANSPORT_ERROR'];}
            $observed=gmdate('Y-m-d\TH:i:s\Z');
            $safe=self::safeResult($result,$reference);
            try{self::audit($pdo,$row,$reference,$observed,$safe);}
            catch(Throwable){$counts['errors']++;$counts['requires_investigation']++;$counts['stopped_reason']='AUDIT_UNAVAILABLE';break;}
            if(!$safe['success']){
                $counts['requires_investigation']++;
                if($safe['error_code']==='MESSAGE_NOT_FOUND'){$counts['missing']++;$counts['stopped_reason']='MESSAGE_NOT_FOUND';break;}
                $counts['errors']++;$counts['stopped_reason']=$safe['error_code'];break;
            }
            if(LogicSmsDeliveryStatus::normalize($safe['status'])==='dlr_unavailable'){
                // Unknown is not a delivery result or proof to charge a held send.
                $counts['unavailable']++;$counts['requires_investigation']++;continue;
            }
            if(in_array($safe['status'],['INVALID','ERROR','REJECTED'],true)){
                // These may describe a rejected lookup rather than delivery.
                // Only an exactly matched, accepted report can resolve them.
                $counts['unavailable']++;$counts['requires_investigation']++;continue;
            }
            if($row['state']==='retry'&&!self::heldAcceptanceStatus($safe['status'])){
                // CREATE/QUEUED are not acceptance; INVALID can describe the
                // lookup identifier itself. A generic ERROR/FAILED/REJECTED is
                // also insufficient to settle an uncertain original submission.
                $counts['unavailable']++;$counts['requires_investigation']++;continue;
            }
            $lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([self::WORKER_LOCK]);
            if((int)$lock->fetchColumn()!==1){$counts['busy']++;continue;}
            try{
                if(!class_exists('SmsStatusReconciliation',false))require_once __DIR__.'/SmsStatusReconciliation.php';
                $applied=SmsStatusReconciliation::apply($pdo,(int)$row['id'],[
                    'source'=>'provider_query','status'=>$safe['status'],
                    'original_reference'=>$reference,'user_id'=>(int)$row['user_id'],
                    'campaign_id'=>(int)$row['campaign_id'],'observed_at_utc'=>$observed,
                    'query'=>array_intersect_key($safe,array_flip(['lookup_reference','lookup_attempts','provider_status_at','timestamp_semantics'])),
                ]);
                if(empty($applied['ok']))throw new DomainException('Status reconciliation did not confirm its result');
                if(!empty($applied['applied']))$counts['applied']++;
                elseif(!empty($applied['duplicate']))$counts['duplicates']++;
                else $counts['unchanged']++;
            }catch(Throwable){$counts['errors']++;$counts['requires_investigation']++;$counts['stopped_reason']='RECONCILIATION_UNAVAILABLE';}
            finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([self::WORKER_LOCK]);}
            if($counts['stopped_reason']!==null)break;
        }
        return $counts;
    }

    /** Existing schema fields support 5m, 10m, 20m ... capped at 6h backoff. */
    private static function eligibleSql():string
    {
        $held=self::heldSql();
        $accepted="(m.provider='logicsms' AND m.state IN ('sent','dlr_unavailable'))";
        return "({$accepted} OR {$held}) AND m.lease_token IS NULL AND m.leased_at IS NULL AND COALESCE(m.dlr_attempt_count,0)<".self::MAX_ATTEMPTS.
            " AND TIMESTAMPDIFF(SECOND,COALESCE(m.sent_at,m.created_at),NOW()) BETWEEN 120 AND 2592000".
            " AND (m.last_dlr_checked_at IS NULL OR TIMESTAMPDIFF(SECOND,m.last_dlr_checked_at,NOW())>=LEAST(21600,300*POW(2,GREATEST(COALESCE(m.dlr_attempt_count,0)-1,0))))";
    }

    private static function heldSql():string
    {
        return "(m.state='retry' AND m.next_attempt_at='".self::HOLD."' AND m.attempt_count>0 AND m.sent_at IS NULL AND COALESCE(m.actual_charge,0)=0 AND (m.provider='logicsms' OR m.provider IS NULL) AND (COALESCE(m.last_error,'') LIKE 'Submission started; awaiting provider acknowledgement%' OR COALESCE(m.last_error,'') LIKE 'Provider accepted reference %; awaiting local settlement%'))";
    }

    private static function heldAcceptanceStatus(string $status):bool
    {
        return in_array($status,['SENT','SUBMITTED','ACCEPTED','DELIVERED','DELIVRD','DELIVERY_SUCCESS','SUCCESS','UNDELIVERED','UNDELIV','BLOCKED','EXPIRED'],true);
    }

    private static function scope(?array $ids):?array
    {
        if($ids===null)return null;
        if(count($ids)>100)throw new InvalidArgumentException('At most 100 campaign IDs are supported');
        $result=[];
        foreach($ids as $id){
            if(!(is_int($id)||is_string($id)&&preg_match('/^[1-9][0-9]{0,9}$/D',$id))||(int)$id<=0)throw new InvalidArgumentException('Campaign IDs must be positive integers');
            $result[]=(int)$id;
        }
        $result=array_values(array_unique($result));sort($result,SORT_NUMERIC);return $result;
    }

    /** No free-text error, credentials, response body, phone or content is kept. */
    private static function safeResult(array $result,string $reference):array
    {
        $success=!empty($result['success']);$parsed=$success?LogicSmsDeliveryStatus::parse((string)($result['status']??'')):null;
        $allowedErrors=['MESSAGE_NOT_FOUND','AUTHENTICATION_ERROR','TRANSPORT_ERROR','HTTP_ERROR','INVALID_RESPONSE','MISSING_REFERENCE'];
        $code=in_array($result['error_code']??null,$allowedErrors,true)?$result['error_code']:'INVALID_RESPONSE';
        if($success&&(!$parsed||($result['lookup_reference']??null)!==$reference)){$success=false;$code='INVALID_RESPONSE';}
        $safe=['success'=>$success,'lookup_reference'=>$reference,'lookup_attempts'=>[]];
        foreach(array_slice(is_array($result['lookup_attempts']??null)?$result['lookup_attempts']:[],0,2) as $attempt){
            if(!is_array($attempt)||($attempt['reference']??null)!==$reference)continue;
            $method=$attempt['method']??null;$http=$attempt['http_status']??null;
            if(!in_array($method,['POST','GET'],true)||!is_int($http)||$http<0||$http>599)continue;
            $outcome=(string)($attempt['outcome']??'');
            $safeOutcome=LogicSmsDeliveryStatus::parse($outcome)['status']??(in_array($outcome,$allowedErrors,true)?$outcome:'INVALID_RESPONSE');
            $safe['lookup_attempts'][]=['reference'=>$reference,'method'=>$method,'http_status'=>$http,'outcome'=>$safeOutcome];
        }
        if(!$success){$safe['error_code']=$code;return $safe;}
        $safe['status']=$parsed['status'];
        $timestamp=$result['provider_status_at']??null;
        $safe['provider_status_at']=is_string($timestamp)&&LogicSmsDeliveryStatus::parse($safe['status'].' '.$timestamp)!==null?$timestamp:null;
        $semantics=$result['timestamp_semantics']??null;
        $safe['timestamp_semantics']=in_array($semantics,['status_transition','provider_reported_unverified'],true)?$semantics:null;
        return $safe;
    }

    private static function audit(PDO $pdo,array $row,string $reference,string $observed,array $safe):void
    {
        $context=['original_reference'=>$reference,'observed_at_utc'=>$observed,'lookup'=>$safe,'sms_submitted'=>false];
        $sql="INSERT INTO operational_logs(level,service,event,message,campaign_id,sms_message_id,user_id,context_json,created_at) VALUES(?,'sms-status-sync','status_lookup',?,?,?,?,?,NOW())";
        $q=$pdo->prepare($sql);
        $q->execute([$safe['success']?'info':'warning',$safe['success']?'Read-only provider status query completed':'Read-only provider status query: '.$safe['error_code'],(int)$row['campaign_id'],(int)$row['id'],(int)$row['user_id'],json_encode($context,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        if($q->rowCount()!==1)throw new DomainException('Lookup evidence audit was not saved');
    }
}
