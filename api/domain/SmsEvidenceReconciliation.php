<?php
declare(strict_types=1);

require_once __DIR__.'/SmsDispatchService.php';

/**
 * Exact, operator-only reconciliation of the five SUPER SUNDAY messages.
 *
 * PRECONDITION: the caller must hold the same MySQL advisory singleton lock as
 * the SMS worker for the entire apply() call. This service never submits SMS,
 * calls the provider, invents provider IDs, or creates another reservation.
 * Screenshot report dates are evidence, NOT confirmed sent/delivered times.
 */
final class SmsEvidenceReconciliation
{
    public const CAMPAIGN_ID = 24;
    public const ACCOUNT_EMAIL = 'blwnwupotch@gmail.com';
    public const CAMPAIGN_NAME = 'SUPER SUNDAY';
    public const EVIDENCE_KEY = 'owner-logicsms-super-sunday-20261024-v1';
    private const SERVICE = 'sms-evidence-reconciliation';
    private const ORIGINAL_ERRORS = [
        936 => 'be9a3f21b73a937b5706f71c489b72de962df2e17da43c31890cfbb4f0d13424',
        937 => 'a394aade847af2d970904181b73a108159389ec160c9e32a5cedeb32dacc830f',
        938 => 'a394aade847af2d970904181b73a108159389ec160c9e32a5cedeb32dacc830f',
        939 => 'a394aade847af2d970904181b73a108159389ec160c9e32a5cedeb32dacc830f',
        940 => 'a394aade847af2d970904181b73a108159389ec160c9e32a5cedeb32dacc830f',
    ];

    public static function evidence(): array
    {
        return [
            'evidence_key' => self::EVIDENCE_KEY,
            'source' => 'owner_verified_logicsms_report_screenshots',
            'source_is_automated_webhook' => false,
            'owner_instruction' => 'Update SUPER SUNDAY to four delivered and one sent; do not resend',
            'report_row_date_semantics' => 'Provider report row date; timezone and exact send/delivery meaning unverified',
            'messages' => [
                ['id'=>936,'destination'=>'+27636644052','state'=>'delivered','provider_report_status'=>'DELIVERED','provider_report_row_date'=>'2026-10-23 21:04:08'],
                ['id'=>937,'destination'=>'+27648634848','state'=>'delivered','provider_report_status'=>'DELIVERED','provider_report_row_date'=>'2026-10-23 21:04:06'],
                ['id'=>938,'destination'=>'+27646412371','state'=>'delivered','provider_report_status'=>'DELIVERED','provider_report_row_date'=>'2026-10-23 21:04:05'],
                ['id'=>939,'destination'=>'+27793732449','state'=>'sent','provider_report_status'=>'SENT','provider_report_row_date'=>'2026-10-23 21:04:04'],
                ['id'=>940,'destination'=>'+27659244814','state'=>'delivered','provider_report_status'=>'DELIVERED','provider_report_row_date'=>'2026-10-23 21:04:03'],
            ],
            'portal_charge_each' => '0.3500',
            'portal_charge_total' => '1.7500',
            'provider_report_charge_is_not_portal_price' => true,
        ];
    }

    /** SELECT-only; safe for the private confirmation page to call on GET. */
    public static function preview(PDO $pdo): array
    {
        $snapshot = self::snapshot($pdo, false);
        $journal = self::journal($pdo, (int)$snapshot['account']['id']);
        $alreadyApplied = self::validate($snapshot, $journal);
        return self::result($snapshot, $alreadyApplied ? 'ALREADY_RECONCILED' : 'READY_TO_RECONCILE', $alreadyApplied);
    }

    /** Owns its transaction; requires the worker singleton lock held externally. */
    public static function apply(PDO $pdo): array
    {
        if ($pdo->inTransaction()) throw new DomainException('Reconciliation requires its own transaction');
        $pdo->beginTransaction();
        try {
            // Match the dispatcher's message-before-wallet lock order. No network
            // request can happen inside this class or its transaction.
            $before = self::snapshot($pdo, true);
            $userId = (int)$before['account']['id'];
            $journal = self::journal($pdo, $userId);
            if (self::validate($before, $journal)) {
                $pdo->commit();
                return self::result($before, 'ALREADY_RECONCILED', true);
            }
            $observedAt = (string)$pdo->query('SELECT NOW()')->fetchColumn();
            if ($observedAt === '') throw new DomainException('Reconciliation time unavailable');
            $evidence = self::evidence();
            $beforeContext = [
                'schema_version'=>1, 'evidence'=>$evidence,
                'reconciled_at_database_time'=>$observedAt,
                'reconciliation_is_not_network_delivery_time'=>true,
                'before'=>$before,
            ];
            $beforeJson = json_encode($beforeContext, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            self::audit($pdo, $userId, 'owner_report_before', $beforeJson);
            $update = $pdo->prepare("UPDATE sms_messages SET state=?,provider='logicsms',actual_charge=estimated_charge,next_attempt_at=NULL,lease_token=NULL,leased_at=NULL,last_error=NULL,updated_at=NOW() WHERE id=? AND user_id=? AND campaign_id=? AND state='retry' AND actual_charge=0 AND provider_message_id IS NULL AND sent_at IS NULL");
            foreach ($before['messages'] as $index=>$row) {
                WalletService::settleMessage($pdo, $row, (float)$row['estimated_charge']);
                $update->execute([$evidence['messages'][$index]['state'],$row['id'],$userId,self::CAMPAIGN_ID]);
                if ($update->rowCount() !== 1) throw new DomainException('Message changed during reconciliation');
            }
            SmsDispatchService::refreshCampaign($pdo, self::CAMPAIGN_ID);
            $after = self::snapshot($pdo, true);
            self::verifyApplied($after, $before, false);
            $afterContext = [
                'schema_version'=>1, 'evidence'=>$evidence,
                'reconciled_at_database_time'=>$observedAt,
                'before_audit_sha256'=>hash('sha256', $beforeJson),
                'after'=>$after, 'sms_submitted'=>false,
            ];
            self::audit($pdo, $userId, 'owner_report_after', json_encode($afterContext, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            $pdo->commit();
            return self::result($after, 'RECONCILED_FROM_OWNER_VERIFIED_REPORT', false);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function snapshot(PDO $pdo, bool $lock): array
    {
        $s=$pdo->prepare('SELECT id,email FROM users WHERE LOWER(TRIM(email))=? ORDER BY id LIMIT 2');
        $s->execute([self::ACCOUNT_EMAIL]);$accounts=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($accounts)!==1)throw new DomainException('Exact account could not be uniquely verified');
        $userId=(int)$accounts[0]['id'];
        $s=$pdo->prepare('SELECT * FROM sms_campaigns WHERE id=?');$s->execute([self::CAMPAIGN_ID]);$campaign=$s->fetch(PDO::FETCH_ASSOC);
        if(!$campaign||(int)$campaign['user_id']!==$userId||$campaign['name']!==self::CAMPAIGN_NAME||(int)$campaign['recipient_count']!==5)throw new DomainException('Exact campaign owner, name or recipient count changed');
        $s=$pdo->prepare('SELECT * FROM sms_messages WHERE campaign_id=? ORDER BY id'.($lock?' FOR UPDATE':''));
        $s->execute([self::CAMPAIGN_ID]);$messages=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($messages)!==5)throw new DomainException('Campaign message count changed');
        foreach(self::evidence()['messages'] as $index=>$expected){
            $row=$messages[$index];
            if((int)$row['id']!==$expected['id']||(int)$row['user_id']!==$userId||$row['destination']!==$expected['destination']||(int)$row['campaign_id']!==self::CAMPAIGN_ID)throw new DomainException('Exact campaign message identity changed');
        }
        $s=$pdo->prepare('SELECT * FROM wallets WHERE user_id=?'.($lock?' FOR UPDATE':''));$s->execute([$userId]);$wallets=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($wallets)!==1||$wallets[0]['currency']!=='ZAR')throw new DomainException('Exact ZAR wallet could not be verified');
        $s=$pdo->prepare('SELECT * FROM wallet_ledger WHERE campaign_id=? ORDER BY id');$s->execute([self::CAMPAIGN_ID]);$ledger=$s->fetchAll(PDO::FETCH_ASSOC);
        // Catch an idempotency key attached to the wrong campaign or account.
        $s=$pdo->prepare('SELECT * FROM wallet_ledger WHERE idempotency_key IN (?,?,?,?,?) ORDER BY id');
        $s->execute(array_map(static fn($id)=>'sms-message:'.$id.':settle',array_keys(self::ORIGINAL_ERRORS)));
        return ['account'=>$accounts[0],'campaign'=>$campaign,'messages'=>$messages,'wallet'=>$wallets[0],'campaign_ledger'=>$ledger,'settlements'=>$s->fetchAll(PDO::FETCH_ASSOC)];
    }

    private static function journal(PDO $pdo, int $userId): array
    {
        $s=$pdo->prepare('SELECT event,context_json FROM operational_logs WHERE campaign_id=? AND user_id=? AND service=? AND event IN (?,?) ORDER BY id');
        $s->execute([self::CAMPAIGN_ID,$userId,self::SERVICE,'owner_report_before','owner_report_after']);
        $journal=[];
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){
            $context=json_decode((string)$row['context_json'],true,512,JSON_THROW_ON_ERROR);
            if(($context['evidence']['evidence_key']??null)!==self::EVIDENCE_KEY)throw new DomainException('Unexpected reconciliation evidence journal');
            if(isset($journal[$row['event']]))throw new DomainException('Duplicate reconciliation journal');
            $journal[$row['event']]=['context'=>$context,'json'=>(string)$row['context_json']];
        }
        return $journal;
    }

    /** Returns true only for a verified completed previous reconciliation. */
    private static function validate(array $snapshot, array $journal): bool
    {
        if($journal){
            if(count($journal)!==2||!isset($journal['owner_report_before'],$journal['owner_report_after']))throw new DomainException('Incomplete reconciliation journal');
            $pre=$journal['owner_report_before'];$post=$journal['owner_report_after'];
            if(($post['context']['before_audit_sha256']??'')!==hash('sha256',$pre['json'])||($post['context']['sms_submitted']??null)!==false||($pre['context']['evidence']??null)!==self::evidence()||($post['context']['evidence']??null)!==self::evidence())throw new DomainException('Reconciliation journal provenance mismatch');
            self::verifyApplied($snapshot,$pre['context']['before'],true);
            return true;
        }
        if($snapshot['campaign']['state']!=='processing')throw new DomainException('Campaign is no longer the expected held campaign');
        if($snapshot['settlements'])throw new DomainException('An original message already has a settlement');
        if(count($snapshot['campaign_ledger'])!==1)throw new DomainException('Original campaign reservation changed');
        $entry=$snapshot['campaign_ledger'][0];
        if($entry['operation']!=='reservation'||$entry['direction']!=='reserve'||$entry['idempotency_key']!=='campaign:24:reserve'||self::units($entry['amount'])!==17500||(int)$entry['user_id']!==(int)$snapshot['account']['id']||(int)$entry['wallet_id']!==(int)$snapshot['wallet']['id'])throw new DomainException('Original unspent reservation could not be verified');
        if(min(self::units($snapshot['wallet']['balance']),self::units($snapshot['wallet']['reserved']))<17500)throw new DomainException('Original reserved funds are unavailable');
        foreach($snapshot['messages'] as $row){
            $id=(int)$row['id'];
            if($row['state']!=='retry'||self::units($row['actual_charge'])!==0||self::units($row['estimated_charge'])!==3500||(int)$row['segment_count']!==1||(int)$row['attempt_count']!==($id===936?2:1)||(int)($row['retry_generation']??0)!==0||$row['next_attempt_at']!=='9999-12-31 23:59:59'||$row['lease_token']!==null||$row['leased_at']!==null||!in_array($row['provider'],[null,'logicsms'],true)||$row['provider_message_id']!==null||$row['sent_at']!==null||$row['delivered_at']!==null||$row['failed_at']!==null||hash('sha256',(string)$row['last_error'])!==self::ORIGINAL_ERRORS[$id])throw new DomainException('Original held message changed; inspect before applying evidence');
        }
        return false;
    }

    private static function verifyApplied(array $after, array $before, bool $allowLaterStatus): void
    {
        if(count($after['settlements'])!==5||count($after['campaign_ledger'])!==6)throw new DomainException('Settlement count does not match the five original messages');
        $settlements=[];
        foreach($after['settlements'] as $entry){
            $id=(int)substr((string)$entry['idempotency_key'],strlen('sms-message:'));
            if(isset($settlements[$id])||!isset(self::ORIGINAL_ERRORS[$id])||$entry['idempotency_key']!=='sms-message:'.$id.':settle'||$entry['operation']!=='sms_settlement'||$entry['direction']!=='debit'||self::units($entry['amount'])!==3500||(int)$entry['user_id']!==(int)$after['account']['id']||(int)$entry['campaign_id']!==self::CAMPAIGN_ID||(int)$entry['wallet_id']!==(int)$after['wallet']['id'])throw new DomainException('Message settlement identity or amount mismatch');
            $settlements[$id]=$entry;
        }
        // Reservation itself must survive unchanged, including metadata and key.
        if($after['campaign_ledger'][0]!==$before['campaign_ledger'][0])throw new DomainException('Original reservation metadata changed');
        foreach($after['messages'] as $index=>$row){
            $original=$before['messages'][$index];$target=self::evidence()['messages'][$index]['state'];
            $allowed=$target==='delivered'?['delivered']:['sent','delivered','failed','dlr_unavailable'];
            if(($allowLaterStatus?!in_array($row['state'],$allowed,true):$row['state']!==$target)||self::units($row['actual_charge'])!==3500||$row['provider']!=='logicsms'||$row['next_attempt_at']!==null||$row['lease_token']!==null||$row['leased_at']!==null)throw new DomainException('Reconciled message state or accounting mismatch');
            // Only these fields are allowed to change during initial apply.
            $mutable=['state','provider','actual_charge','next_attempt_at','lease_token','leased_at','last_error','updated_at'];
            // Later DLRs can add genuine provider timestamps/metadata; repeats
            // neither overwrite them nor downgrade a newer final outcome.
            if(!$allowLaterStatus){
                foreach($original as $field=>$value)if(!in_array($field,$mutable,true)&&$row[$field]!==$value)throw new DomainException('Original message metadata changed unexpectedly');
                if($row['last_error']!==null)throw new DomainException('Original technical error was not cleared after journalling');
            }
        }
        if(!$allowLaterStatus){
            if(self::units($after['wallet']['balance'])!==self::units($before['wallet']['balance'])-17500||self::units($after['wallet']['reserved'])!==self::units($before['wallet']['reserved'])-17500)throw new DomainException('Wallet settlement delta mismatch');
            foreach($before['wallet'] as $field=>$value)if(!in_array($field,['balance','reserved','updated_at'],true)&&$after['wallet'][$field]!==$value)throw new DomainException('Unrelated wallet metadata changed');
            $mutable=['state','queued_count','processing_count','sent_count','delivered_count','failed_count','skipped_count','actual_cost','completed_at','updated_at'];
            foreach($before['campaign'] as $field=>$value)if(!in_array($field,$mutable,true)&&$after['campaign'][$field]!==$value)throw new DomainException('Unrelated campaign metadata changed');
            $c=$after['campaign'];
            if($c['state']!=='completed'||(int)$c['sent_count']!==5||(int)$c['delivered_count']!==4||(int)$c['queued_count']!==0||(int)$c['processing_count']!==0||(int)$c['failed_count']!==0||(int)$c['skipped_count']!==0||self::units($c['actual_cost'])!==17500)throw new DomainException('Campaign aggregate reconciliation mismatch');
        }
    }

    private static function audit(PDO $pdo, int $userId, string $event, string $json): void
    {
        $s=$pdo->prepare("INSERT INTO operational_logs(level,service,event,message,campaign_id,sms_message_id,user_id,context_json,created_at) VALUES ('warning',?,?,?,24,NULL,?,?,NOW())");
        $s->execute([self::SERVICE,$event,'Operator reconciliation from owner-verified provider report; not an automated webhook',$userId,$json]);
        if($s->rowCount()!==1)throw new DomainException('Mandatory evidence audit could not be saved');
    }

    /** Compare DECIMAL(14,4) values without binary-float equality. */
    private static function units(mixed $value): int
    {
        if(!is_numeric($value))throw new DomainException('Invalid accounting amount');
        return (int)round((float)$value*10000);
    }

    private static function result(array $snapshot, string $code, bool $alreadyApplied): array
    {
        $c=$snapshot['campaign'];
        return [
            'ok'=>true,'code'=>$code,'already_reconciled'=>$alreadyApplied,
            'campaign'=>array_intersect_key($c,array_flip(['id','name','state','recipient_count','sent_count','delivered_count','queued_count','processing_count','skipped_count','actual_cost'])),
            'messages'=>array_map(static fn($row)=>['id'=>(int)$row['id'],'destination'=>$row['destination'],'state'=>$row['state'],'actual_charge'=>number_format((float)$row['actual_charge'],4,'.',''),'provider_reference_saved'=>$row['provider_message_id']!==null,'sent_at'=>$row['sent_at'],'delivered_at'=>$row['delivered_at']],$snapshot['messages']),
            'wallet'=>array_intersect_key($snapshot['wallet'],array_flip(['balance','reserved','currency'])),
            'evidence'=>self::evidence(),'sms_submitted'=>false,'provider_requests'=>0,
        ];
    }
}
