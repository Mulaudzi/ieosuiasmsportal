<?php
declare(strict_types=1);

require_once __DIR__.'/LogicSmsDeliveryStatus.php';
require_once __DIR__.'/SmsMessageState.php';
require_once __DIR__.'/SmsDispatchService.php';

/**
 * Trusted adapters only: applies provider status evidence, NEVER submits SMS.
 * Adapters authenticate callbacks/queries and match report rows before calling.
 * A held-message settlement additionally requires ownership of the dispatcher
 * singleton advisory lock. This class owns its database transaction.
 */
final class SmsStatusReconciliation
{
    public const WORKER_LOCK = 'ieosuia-sms-dispatch-worker';
    private const SERVICE = 'sms-status-reconciliation';

    /** A no-resend submission intent without a live lease, not an unsent draft. */
    public static function eligible(array $row): bool
    {
        return in_array($row['state']??'', ['retry','processing'], true)
            && (int)($row['attempt_count']??0)>0
            && ($row['next_attempt_at']??null)==='9999-12-31 23:59:59'
            && in_array($row['lease_token']??null,[null,''],true) && in_array($row['leased_at']??null,[null,''],true)
            && in_array($row['sent_at']??null,[null,''],true) && in_array($row['provider_message_id']??null,[null,''],true)
            && self::units($row['actual_charge']??0)===0
            && self::units($row['estimated_charge']??0)>0
            && in_array($row['provider']??null, [null,'','logicsms'], true);
    }

    /**
     * Required evidence: source, user_id, campaign_id, raw status string.
     * Query/callback: original_reference MUST equal portal-message-{messageId}.
     * Owner report: match_proof = {kind, destination, content_sha256,
     * campaign_created_at}; kind is exact_report_row/owner_verified_exact_message.
     * Extra provenance is retained privately, never treated as a provider ID.
     */
    public static function apply(PDO $pdo, int $messageId, array $evidence): array
    {
        if($messageId<1||$pdo->inTransaction())throw new DomainException('Reconciliation requires a positive message ID and its own transaction');
        $source=(string)($evidence['source']??'');
        if(!in_array($source,['owner_report','provider_query','authenticated_callback','provider_acknowledgement'],true))throw new DomainException('Unsupported evidence source');
        $userId=self::positiveId($evidence['user_id']??null);
        $campaignId=self::positiveId($evidence['campaign_id']??null);
        $status=trim((string)($evidence['status']??''));
        $parsed=LogicSmsDeliveryStatus::parse($status);
        if(!$parsed)throw new DomainException('Evidence has no recognized provider status');
        $incoming=LogicSmsDeliveryStatus::normalize($status);
        $originalReference='portal-message-'.$messageId;
        if(($source!=='owner_report'||array_key_exists('original_reference',$evidence))&&($evidence['original_reference']??null)!==$originalReference)throw new DomainException('Evidence does not identify the exact original submission reference');

        $pdo->beginTransaction();
        try {
            // Same message-before-wallet order as the sender. Campaign ownership
            // is checked under lock; caller-supplied IDs never establish tenancy.
            $q=$pdo->prepare('SELECT * FROM sms_messages WHERE id=? FOR UPDATE');$q->execute([$messageId]);$before=$q->fetch(PDO::FETCH_ASSOC);
            if(!$before||(int)$before['user_id']!==$userId||(int)$before['campaign_id']!==$campaignId)throw new DomainException('Evidence message owner or campaign mismatch');
            $q=$pdo->prepare('SELECT * FROM sms_campaigns WHERE id=? FOR UPDATE');$q->execute([$campaignId]);$campaign=$q->fetch(PDO::FETCH_ASSOC);
            if(!$campaign||(int)$campaign['user_id']!==$userId)throw new DomainException('Evidence campaign owner mismatch');
            if(!in_array($before['provider']??null,[null,'','logicsms'],true))throw new DomainException('Evidence provider mismatch');
            self::validateProof($pdo,$before,$campaign,$evidence);
            $fingerprint=self::fingerprint($messageId,$evidence,$parsed);
            $prior=self::priorReceipt($pdo,$messageId,$userId,$campaignId,$fingerprint);
            if($prior!==null){
                if(!empty($prior['accepted'])&&!SmsMessageState::accepted($before))throw new DomainException('Previously reconciled acceptance changed; inspect accounting before continuing');
                // A callback received during the sender's active lease was
                // deliberately deferred, not consumed. Once the lease clears
                // or normal settlement commits, that SAME evidence must be
                // reconsidered. Retain both receipt pairs, never rewrite them.
                $reconsider=empty($prior['accepted'])&&($prior['result']['code']??null)==='SUBMISSION_NOT_ELIGIBLE'
                    &&(SmsMessageState::accepted($before)||(self::eligible($before)&&self::provesAcceptance($parsed['status'],$evidence)));
                if(!$reconsider){
                    $pdo->commit();
                    return self::result($before,$fingerprint,'DUPLICATE_EVIDENCE',false,true,false,0);
                }
            }

            $acceptedBefore=SmsMessageState::accepted($before);
            $held=!$acceptedBefore&&self::eligible($before);
            $provesAcceptance=self::provesAcceptance($parsed['status'],$evidence);
            $chargedNow=false;$charge=0;$financialBefore=null;
            if($held&&$provesAcceptance){
                // A callback that outruns a live sender cannot get here: eligible
                // excludes active leases. The lock also serializes maintenance,
                // callbacks and query reconciliation against queue submission.
                $q=$pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');$q->execute([self::WORKER_LOCK]);
                if((int)$q->fetchColumn()!==1)throw new DomainException('Held settlement requires the dispatcher singleton lock');
                $financialBefore=self::financialSnapshot($pdo,$before,true);
                self::assertHeldReservation($pdo,$before,$financialBefore);
                $charge=self::units($before['estimated_charge']);
                $chargedNow=true;
            }

            $canApply=$acceptedBefore||($held&&$provesAcceptance);
            $blocked=LogicSmsDeliveryStatus::isBlocked($status);
            $refinesFailure=$before['state']==='failed'&&$blocked&&!LogicSmsDeliveryStatus::isBlocked($before['last_error']??null);
            $change=$canApply&&(!$acceptedBefore||$refinesFailure||LogicSmsDeliveryStatus::shouldApply((string)$before['state'],$incoming,$before['last_error']??null));
            $code=$change?'STATUS_RECONCILED':($acceptedBefore?'FINAL_STATE_PRESERVED':($held?'ACCEPTANCE_NOT_PROVEN':'SUBMISSION_NOT_ELIGIBLE'));
            $clock=(string)$pdo->query('SELECT NOW()')->fetchColumn();
            if($clock==='')throw new DomainException('Evidence observation time unavailable');
            $auditBefore=[
                'schema_version'=>1,'evidence_fingerprint'=>$fingerprint,
                'evidence'=>$evidence,'provider_status'=>$parsed,
                'observed_at_database_time'=>$clock,
                'timestamp_semantics'=>$source==='owner_report'?'report_row_time_unverified_not_copied_to_sent_or_delivery_time':'portal_observation_time_not_network_delivery_time',
                'before'=>['message'=>$before,'campaign'=>$campaign,'financial'=>$financialBefore],
            ];
            $beforeJson=json_encode($auditBefore,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            self::audit($pdo,$before,$fingerprint,'status_evidence_before',$beforeJson);

            if($chargedNow)WalletService::settleMessage($pdo,$before,$charge/10000);
            if($change){
                $fields=['state=?','provider=\'logicsms\'','updated_at=NOW()'];$values=[$incoming];
                if($source==='provider_acknowledgement'){$fields[]='provider_message_id=?';$values[]=$evidence['provider_message_id'];}
                if($chargedNow){$fields[]='actual_charge=estimated_charge';$fields[]='next_attempt_at=NULL';$fields[]='lease_token=NULL';$fields[]='leased_at=NULL';}
                if($incoming==='delivered'){
                    if($source!=='owner_report')$fields[]='delivered_at=COALESCE(delivered_at,NOW())';
                    $fields[]='failed_at=NULL';$fields[]='last_error=NULL';
                }elseif($incoming==='failed'){
                    if($source!=='owner_report')$fields[]='failed_at=COALESCE(failed_at,NOW())';
                    $fields[]='last_error=?';$values[]='LogicSMS delivery status: '.$status;
                }elseif($incoming==='dlr_unavailable'){
                    $fields[]='last_error=?';$values[]='LogicSMS delivery status: '.$status;
                }else $fields[]='last_error=NULL';
                // sent_at is NEVER invented. An accepted state plus the canonical
                // settlement is sufficient for acceptedSql and reporting totals.
                $values[]=$messageId;$values[]=$userId;$values[]=$campaignId;
                $q=$pdo->prepare('UPDATE sms_messages SET '.implode(',',$fields).' WHERE id=? AND user_id=? AND campaign_id=?');$q->execute($values);
                if($q->rowCount()!==1)throw new DomainException('Reconciliation update did not affect its exact message');
                SmsDispatchService::refreshCampaign($pdo,$campaignId);
            }
            $q=$pdo->prepare('SELECT * FROM sms_messages WHERE id=?');$q->execute([$messageId]);$after=$q->fetch(PDO::FETCH_ASSOC);
            $q=$pdo->prepare('SELECT * FROM sms_campaigns WHERE id=?');$q->execute([$campaignId]);$campaignAfter=$q->fetch(PDO::FETCH_ASSOC);
            if(!$after||!$campaignAfter)throw new DomainException('Reconciliation postcondition rows disappeared');
            $mutableCampaign=['state','queued_count','processing_count','sent_count','delivered_count','failed_count','skipped_count','actual_cost','completed_at','updated_at'];
            foreach($campaign as $field=>$value)if(!in_array($field,$mutableCampaign,true)&&($campaignAfter[$field]??null)!==$value)throw new DomainException('Unrelated campaign metadata changed during reconciliation');
            if($change)self::verifyProjection($pdo,$campaign,$campaignAfter);
            self::verifyMetadata($before,$after,$change,$chargedNow,$incoming,$source);
            $financialAfter=null;
            if($chargedNow){
                $financialAfter=self::financialSnapshot($pdo,$after,false);
                self::verifySettlement($after,$financialBefore,$financialAfter,$charge);
            }
            $result=self::result($after,$fingerprint,$code,$change,false,$chargedNow,$charge);
            self::audit($pdo,$before,$fingerprint,'status_evidence_after',json_encode([
                'schema_version'=>1,'evidence_fingerprint'=>$fingerprint,
                'evidence'=>$evidence,'before_audit_sha256'=>hash('sha256',$beforeJson),
                'after'=>['message'=>$after,'campaign'=>$campaignAfter,'financial'=>$financialAfter],
                'result'=>$result,'accepted'=>SmsMessageState::accepted($after),'sms_submitted'=>false,
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            $pdo->commit();
            return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private static function validateProof(PDO $pdo,array $message,array $campaign,array $evidence):void
    {
        if($evidence['source']==='provider_acknowledgement'){
            require_once __DIR__.'/SmsAcknowledgementEvidence.php';
            $q=$pdo->prepare('SELECT * FROM operational_logs WHERE id=?');$q->execute([self::positiveId($evidence['acknowledgement_log_id']??null)]);$log=$q->fetch(PDO::FETCH_ASSOC);
            if(!$log||(int)$log['sms_message_id']!==(int)$message['id'])throw new DomainException('Original acknowledgement log unavailable');
            $verified=SmsAcknowledgementEvidence::fromLog($log);
            foreach($verified as $field=>$value)if(($evidence[$field]??null)!==$value)throw new DomainException('Saved acknowledgement evidence mismatch');
        }
        $proof=$evidence['match_proof']??null;
        if($evidence['source']==='owner_report'){
            if(!is_array($proof)||!in_array($proof['kind']??null,['exact_report_row','owner_verified_exact_message'],true)||($proof['destination']??null)!==$message['destination']||($proof['content_sha256']??null)!==hash('sha256',(string)$message['content'])||($proof['campaign_created_at']??null)!==$campaign['created_at'])throw new DomainException('Provider report does not prove exact account, recipient, full content and campaign');
        }elseif($proof!==null){
            if(!is_array($proof))throw new DomainException('Invalid evidence matching proof');
            foreach(['destination'=>$message['destination'],'content_sha256'=>hash('sha256',(string)$message['content']),'campaign_created_at'=>$campaign['created_at']] as $field=>$expected)if(array_key_exists($field,$proof)&&$proof[$field]!==$expected)throw new DomainException('Original-reference evidence conflicts with message matching proof');
        }
        if(isset($evidence['account_email'])){
            $q=$pdo->prepare('SELECT email FROM users WHERE id=?');$q->execute([$message['user_id']]);$email=$q->fetchColumn();
            if($email===false||strtolower(trim((string)$email))!==strtolower(trim((string)$evidence['account_email'])))throw new DomainException('Evidence account email mismatch');
        }
        // Optional genuinely returned provider IDs are evidence only, never
        // assigned here. In particular, 9999 cannot overwrite/collide with an
        // unrelated accepted message's unique provider reference.
        $id=trim((string)($evidence['provider_message_id']??''));
        if($id!==''){
            if(!empty($message['provider_message_id'])&&$message['provider_message_id']!==$id)throw new DomainException('Conflicting provider identifier');
            $q=$pdo->prepare("SELECT id FROM sms_messages WHERE provider='logicsms' AND provider_message_id=? ORDER BY id LIMIT 2");$q->execute([$id]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $matched)if((int)$matched!==(int)$message['id'])throw new DomainException('Provider identifier belongs to another message');
        }
    }

    private static function provesAcceptance(string $status,array $evidence):bool
    {
        // This source has been revalidated against its original persisted log
        // under the exact message, campaign and account locks above.
        if($status==='CREATE'&&$evidence['source']==='provider_acknowledgement')return true;
        // CREATE/QUEUED/UNKNOWN and generic ERROR/INVALID/REJECTED cannot
        // establish a previously held message's billable network acceptance.
        if(in_array($status,['SENT','SUBMITTED','ACCEPTED','DELIVERED','DELIVRD','DELIVERY_SUCCESS','SUCCESS','UNDELIVERED','UNDELIV','BLOCKED','EXPIRED'],true))return true;
        if(!in_array($status,['FAILED','REJECTED','ERROR','INVALID'],true)||$evidence['source']!=='owner_report')return false;
        $credits=$evidence['provider_report_credits']??null;
        // An INVALID lookup can mean an invalid query, not an accepted SMS.
        // Only an exactly matched charged outbound report establishes this
        // otherwise ambiguous status as an accepted delivery failure.
        return ($evidence['acceptance_confirmed_by_report']??false)===true
            && is_numeric($credits)&&is_finite((float)$credits)&&(float)$credits>0;
    }

    private static function financialSnapshot(PDO $pdo,array $message,bool $lock):array
    {
        $q=$pdo->prepare('SELECT * FROM wallets WHERE user_id=?'.($lock?' FOR UPDATE':''));$q->execute([$message['user_id']]);$wallets=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($wallets)!==1||$wallets[0]['currency']!=='ZAR')throw new DomainException('A unique ZAR wallet is required');
        $q=$pdo->prepare('SELECT * FROM wallet_ledger WHERE wallet_id=? OR user_id=? ORDER BY id'.($lock?' FOR UPDATE':''));$q->execute([$wallets[0]['id'],$message['user_id']]);$ledger=$q->fetchAll(PDO::FETCH_ASSOC);
        // Also read the global unique settlement key, not just this campaign's
        // ledger, so a corrupt/cross-account key cannot silently short-circuit.
        $q=$pdo->prepare('SELECT * FROM wallet_ledger WHERE idempotency_key=?');$q->execute(['sms-message:'.$message['id'].':settle']);$settlements=$q->fetchAll(PDO::FETCH_ASSOC);
        $snapshot=['wallet'=>$wallets[0],'ledger'=>$ledger,'settlements'=>$settlements];
        // Historical wallets can contain reservations predating the canonical
        // ledger. Preserve that nonnegative, unattributed component; never
        // infer an opening journal entry, release it, or use it to fund a send.
        $snapshot['reservation_components']=self::balances($snapshot,(int)$message['user_id'],(int)$message['campaign_id']);
        return $snapshot;
    }

    private static function balances(array $financial,int $userId,int $campaignId):array
    {
        $total=0;$campaign=0;$original=0;$byCampaign=[];
        foreach($financial['ledger'] as $row){
            if((int)$row['user_id']!==$userId||(int)$row['wallet_id']!==(int)$financial['wallet']['id'])throw new DomainException('Wallet ledger ownership is inconsistent');
            $amount=self::units($row['amount']);
            if($amount<0)throw new DomainException('Negative reservation ledger entry');
            $operation=(string)$row['operation'];$direction=(string)$row['direction'];$entryCampaign=(int)($row['campaign_id']??0);
            // Balance adjustments (including negative manual adjustments) do
            // not consume SMS reservations. Only these canonical operations
            // have reservation effects; never infer them from direction alone.
            $expectedDirection=match($operation){
                'reservation','retry_reservation'=>'reserve',
                'sms_settlement'=>'debit',
                'reservation_release'=>'release',
                default=>null,
            };
            if($expectedDirection!==null){
                if($direction!==$expectedDirection||$entryCampaign<1)throw new DomainException('Canonical reservation operation has an invalid direction or campaign');
                $delta=$direction==='reserve'?$amount:-$amount;
                $byCampaign[$entryCampaign]=($byCampaign[$entryCampaign]??0)+$delta;
            }else{
                if(in_array($direction,['reserve','release'],true)||($entryCampaign>0&&$direction==='debit'))throw new DomainException('Unknown campaign reservation operation requires investigation');
                if(!in_array($direction,['credit','debit'],true))throw new DomainException('Unknown wallet ledger direction requires investigation');
                $delta=0;
            }
            $total+=$delta;
            if((int)($row['campaign_id']??0)===$campaignId){
                $campaign+=$delta;
                if($row['idempotency_key']==='campaign:'.$campaignId.':reserve'&&$row['operation']==='reservation'&&$row['direction']==='reserve'&&$amount>0)$original++;
            }
        }
        foreach($byCampaign as $outstanding)if($outstanding<0)throw new DomainException('A canonical campaign reservation has negative outstanding funds');
        $reserved=self::units($financial['wallet']['reserved']);$balance=self::units($financial['wallet']['balance']);
        if($total<0||$campaign<0||$reserved<$total)throw new DomainException('Canonical wallet reservation and ledger do not agree');
        if($balance<$reserved)throw new DomainException('Wallet reserved funds exceed its balance');
        $unattributed=$reserved-$total;
        return [
            'wallet_outstanding'=>$total,'campaign_outstanding'=>$campaign,'original_reservations'=>$original,
            'wallet_reserved_units'=>$reserved,'canonical_reserved_units'=>$total,
            'unattributed_reserved_units'=>$unattributed,
            'unattributed_reserved_component'=>number_format($unattributed/10000,4,'.',''),
            'unattributed_component_semantics'=>'retained_unattributed_not_backfilled_released_or_spent',
        ];
    }

    private static function assertHeldReservation(PDO $pdo,array $message,array $financial):void
    {
        $amount=self::units($message['estimated_charge']);
        if($financial['settlements'])throw new DomainException('Held row already has a settlement; inspect accounting before applying');
        $balances=self::balances($financial,(int)$message['user_id'],(int)$message['campaign_id']);
        if($balances['original_reservations']!==1||$balances['campaign_outstanding']<$amount||self::units($financial['wallet']['balance'])<$amount)throw new DomainException('Original campaign reservation cannot fund this message');
        $q=$pdo->prepare('SELECT * FROM sms_messages WHERE campaign_id=? ORDER BY id');$q->execute([$message['campaign_id']]);$liability=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            if((int)$row['user_id']!==(int)$message['user_id'])throw new DomainException('Campaign message ownership is inconsistent');
            if(!SmsMessageState::accepted($row)&&in_array($row['state'],['pending','queued','processing','retry'],true)){
                if(self::units($row['actual_charge'])!==0)throw new DomainException('Unaccepted campaign message has inconsistent charges');
                $liability+=self::units($row['estimated_charge']);
            }
        }
        if($liability<$amount||$balances['campaign_outstanding']<$liability)throw new DomainException('Reservation cannot cover the other unaccepted campaign messages');
    }

    private static function verifySettlement(array $message,array $before,array $after,int $amount):void
    {
        $pre=self::balances($before,(int)$message['user_id'],(int)$message['campaign_id']);
        $post=self::balances($after,(int)$message['user_id'],(int)$message['campaign_id']);
        if($post['unattributed_reserved_units']!==$pre['unattributed_reserved_units']||$post['canonical_reserved_units']!==$pre['canonical_reserved_units']-$amount)throw new DomainException('Unattributed reserved component changed during settlement');
        if(count($after['settlements'])!==1||count($after['ledger'])!==count($before['ledger'])+1||$post['campaign_outstanding']!==$pre['campaign_outstanding']-$amount||self::units($after['wallet']['balance'])!==self::units($before['wallet']['balance'])-$amount||self::units($after['wallet']['reserved'])!==self::units($before['wallet']['reserved'])-$amount||self::units($message['actual_charge'])!==$amount)throw new DomainException('Held-message settlement delta mismatch');
        $entry=$after['settlements'][0];
        if($entry['operation']!=='sms_settlement'||$entry['direction']!=='debit'||self::units($entry['amount'])!==$amount||(int)$entry['user_id']!==(int)$message['user_id']||(int)$entry['wallet_id']!==(int)$after['wallet']['id']||(int)$entry['campaign_id']!==(int)$message['campaign_id']||$entry['idempotency_key']!=='sms-message:'.$message['id'].':settle')throw new DomainException('Settlement key, identity or amount mismatch');
        foreach($before['ledger'] as $index=>$row)if($after['ledger'][$index]!==$row)throw new DomainException('Original wallet ledger was altered');
        foreach($before['wallet'] as $field=>$value)if(!in_array($field,['balance','reserved','updated_at'],true)&&$after['wallet'][$field]!==$value)throw new DomainException('Unrelated wallet metadata changed');
    }

    private static function verifyMetadata(array $before,array $after,bool $changed,bool $charged,string $incoming,string $source):void
    {
        if(!$changed){if($after!==$before)throw new DomainException('Non-applicable evidence unexpectedly changed the message');return;}
        $mutable=['state','provider','last_error','updated_at','delivered_at','failed_at'];
        if($source==='provider_acknowledgement')$mutable[]='provider_message_id';
        if($charged)$mutable=array_merge($mutable,['actual_charge','next_attempt_at','lease_token','leased_at']);
        foreach($before as $field=>$value)if(!in_array($field,$mutable,true)&&$after[$field]!==$value)throw new DomainException('Original message metadata changed unexpectedly');
        if($after['state']!==$incoming||$after['provider']!=='logicsms')throw new DomainException('Reconciled state postcondition failed');
        if($charged&&($after['next_attempt_at']!==null||$after['lease_token']!==null||$after['leased_at']!==null))throw new DomainException('Verified acceptance did not clear its submission hold');
        if($source==='owner_report'&&$after['delivered_at']!==$before['delivered_at'])throw new DomainException('Report row dates cannot become delivery timestamps');
    }

    /** Recheck mutable projections against source rows, not just allowed names. */
    private static function verifyProjection(PDO $pdo,array $before,array $after):void
    {
        $accepted=SmsMessageState::acceptedSql();$networkFailure=SmsMessageState::networkFailureSql();$submissionFailure=SmsMessageState::submissionFailureSql();
        $q=$pdo->prepare("SELECT COUNT(*) total,SUM(state IN ('pending','queued','retry')) queued,SUM(state='processing') processing,SUM($accepted) sent,SUM(state='delivered') delivered,SUM($networkFailure) failed,SUM($submissionFailure) failed_submissions,SUM(state='skipped') skipped,COALESCE(SUM(actual_charge),0) actual FROM sms_messages WHERE campaign_id=?");
        $q->execute([$before['id']]);$expected=$q->fetch(PDO::FETCH_ASSOC);
        if(!$expected)throw new DomainException('Campaign projection verification unavailable');
        foreach(['queued_count'=>'queued','processing_count'=>'processing','sent_count'=>'sent','delivered_count'=>'delivered','failed_count'=>'failed','skipped_count'=>'skipped'] as $field=>$aggregate)if((int)$after[$field]!== (int)$expected[$aggregate])throw new DomainException('Campaign projection counters do not match its message rows');
        if(self::units($after['actual_cost'])!==self::units($expected['actual']))throw new DomainException('Campaign projection cost does not match its message charges');
        $done=(int)$expected['sent']+(int)$expected['failed_submissions']+(int)$expected['skipped']===(int)$expected['total'];
        $state=in_array($before['state'],['cancelled','draft','scheduled'],true)?$before['state']:($done?((int)$expected['failed_submissions']>0?'partially_failed':'completed'):'processing');
        if($after['state']!==$state||($done&&$after['completed_at']===null))throw new DomainException('Campaign projection completion state mismatch');
    }

    private static function fingerprint(int $messageId,array $evidence,array $parsed):string
    {
        $stable=[
            'message_id'=>$messageId,'user_id'=>(int)$evidence['user_id'],'campaign_id'=>(int)$evidence['campaign_id'],
            'source'=>$evidence['source'],'status'=>$parsed,
            'original_reference'=>$evidence['original_reference']??null,
            'provider_message_id'=>$evidence['provider_message_id']??null,
            'match_proof'=>$evidence['match_proof']??null,
            'source_event_id'=>$evidence['source_event_id']??$evidence['event_id']??null,
            'artifact_sha256'=>$evidence['artifact_sha256']??null,
            'report_row'=>$evidence['report_row']??$evidence['report_row_metadata']??null,
            'queried_provider_status_at'=>$evidence['query']['provider_status_at']??null,
            'acceptance_confirmed_by_report'=>$evidence['acceptance_confirmed_by_report']??false,
            'provider_report_credits'=>$evidence['provider_report_credits']??null,
        ];
        $canonical=static function(mixed $value)use(&$canonical):mixed{if(!is_array($value))return $value;if(!array_is_list($value))ksort($value);foreach($value as $key=>$item)$value[$key]=$canonical($item);return $value;};
        return hash('sha256',json_encode($canonical($stable),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    private static function priorReceipt(PDO $pdo,int $messageId,int $userId,int $campaignId,string $fingerprint):?array
    {
        $q=$pdo->prepare('SELECT event,context_json FROM operational_logs WHERE service=? AND sms_message_id=? AND user_id=? AND campaign_id=? AND message=? AND event IN (?,?) ORDER BY id');
        $q->execute([self::SERVICE,$messageId,$userId,$campaignId,'Evidence '.$fingerprint,'status_evidence_before','status_evidence_after']);$events=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!$events)return null;
        if(count($events)%2!==0)throw new DomainException('Evidence journal is incomplete');
        $after=null;
        foreach(array_chunk($events,2) as [$beforeEvent,$afterEvent]){
            if($beforeEvent['event']!=='status_evidence_before'||$afterEvent['event']!=='status_evidence_after')throw new DomainException('Evidence journal order mismatch');
            $next=json_decode((string)$afterEvent['context_json'],true,512,JSON_THROW_ON_ERROR);
            if(($next['before_audit_sha256']??null)!==hash('sha256',(string)$beforeEvent['context_json'])||($next['evidence_fingerprint']??null)!==$fingerprint||($next['sms_submitted']??null)!==false)throw new DomainException('Evidence journal integrity mismatch');
            if($after!==null&&(!empty($after['accepted'])||($after['result']['code']??null)!=='SUBMISSION_NOT_ELIGIBLE'))throw new DomainException('Evidence journal unexpectedly reapplied a consumed receipt');
            $after=$next;
        }
        return $after;
    }

    private static function audit(PDO $pdo,array $message,string $fingerprint,string $event,string $json):void
    {
        $q=$pdo->prepare("INSERT INTO operational_logs(level,service,event,message,campaign_id,sms_message_id,user_id,context_json,created_at) VALUES ('info',?,?,?,?,?,?,?,NOW())");
        $q->execute([self::SERVICE,$event,'Evidence '.$fingerprint,$message['campaign_id'],$message['id'],$message['user_id'],$json]);
        if($q->rowCount()!==1)throw new DomainException('Mandatory status evidence journal unavailable');
    }

    private static function positiveId(mixed $value):int
    {
        if(!is_int($value)&&(!is_string($value)||!preg_match('/^[1-9][0-9]*$/D',$value)))throw new DomainException('Evidence owner and campaign IDs are required');
        $id=(int)$value;if($id<1)throw new DomainException('Evidence owner and campaign IDs must be positive');return $id;
    }
    private static function units(mixed $value):int
    {if(!is_numeric($value)||!is_finite((float)$value))throw new DomainException('Invalid accounting amount');return (int)round((float)$value*10000);}
    private static function result(array $row,string $fingerprint,string $code,bool $applied,bool $duplicate,bool $charged,int $amount):array
    {
        return ['ok'=>true,'code'=>$code,'message_id'=>(int)$row['id'],'state'=>$row['state'],'accepted'=>SmsMessageState::accepted($row),'applied'=>$applied,'duplicate'=>$duplicate,'charged_now'=>$charged,'charge_amount'=>number_format($amount/10000,4,'.',''),'evidence_fingerprint'=>$fingerprint,'sms_submitted'=>false];
    }
}
