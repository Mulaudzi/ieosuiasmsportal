<?php
require_once __DIR__.'/../domain/CreditPurchaseSettlement.php';
require_once __DIR__.'/../domain/SmsDeliveryPresentation.php';
require_once __DIR__.'/../domain/SmsMessageState.php';
/** Read-only presentation data. Does not queue sends, alter balances or update statuses. */
final class PortalReadModel
{
    public static function overview(PDO $pdo, int $user): array
    {
        $scalar = static function(string $sql) use ($pdo, $user) { $q=$pdo->prepare($sql);$q->execute([$user]);return $q->fetchColumn(); };
        $accepted=SmsMessageState::acceptedSql();$networkFailure=SmsMessageState::networkFailureSql();
        return [
            'contacts'=>(int)$scalar('SELECT COUNT(*) FROM contacts WHERE user_id=?'),
            'bought_credits'=>(int)$scalar("SELECT COUNT(*) FROM payments WHERE user_id=? AND status='completed'")>0,
            'sent_campaign'=>(int)$scalar("SELECT COUNT(*) FROM sms_messages WHERE user_id=? AND $accepted")>0,
            'checked_delivery'=>(int)$scalar("SELECT COUNT(*) FROM sms_messages WHERE user_id=? AND (state='delivered' OR $networkFailure)")>0,
        ];
    }

    public static function confirmation(PDO $pdo, int $user, string $range): array
    {
        require_once __DIR__.'/../domain/SmsReportingService.php';
        $period=SmsReportingService::period($range);
        $q=$pdo->prepare("SELECT TIMESTAMPDIFF(SECOND,sent_at,delivered_at) seconds FROM sms_messages WHERE user_id=? AND created_at>=? AND created_at<=? AND state='delivered' AND sent_at IS NOT NULL AND delivered_at>=sent_at ORDER BY seconds");
        $q->execute([$user,$period['start'],$period['end']]);$seconds=$q->fetchAll(PDO::FETCH_COLUMN);$count=count($seconds);
        // A 75th percentile supports 'most', unlike a mean. Hide sparse samples.
        return ['seconds'=>$count>=5?(float)$seconds[(int)ceil($count*.75)-1]:null,'sample_count'=>$count,'method'=>'75th percentile of confirmed messages'];
    }

    public static function dates(array $filters): array
    {
        $period=$filters['period']??'month';$now=new DateTimeImmutable('now',new DateTimeZone('Africa/Johannesburg'));
        if($period==='month')return [$now->format('Y-m-01 00:00:00'),$now->modify('+1 day')->format('Y-m-d 00:00:00')];
        if($period==='30d')return [$now->modify('-30 days')->format('Y-m-d H:i:s'),$now->format('Y-m-d H:i:s')];
        if($period==='custom'){
            $dates=[];foreach(['start','end'] as $key){$text=(string)($filters[$key]??'');$d=DateTimeImmutable::createFromFormat('!Y-m-d',$text);if(!$d||$d->format('Y-m-d')!==$text)throw new InvalidArgumentException('Choose valid start and end dates');$dates[]=$d;}
            if($dates[1]<$dates[0])throw new InvalidArgumentException('End date must not precede start date');
            return [$dates[0]->format('Y-m-d 00:00:00'),$dates[1]->modify('+1 day')->format('Y-m-d 00:00:00')];
        }
        throw new InvalidArgumentException('Invalid date period');
    }

    public static function activity(PDO $pdo, int $user, array $filters, float $price): array
    {
        [$start,$end]=self::dates($filters);$status=$filters['status']??'all';
        if(!in_array($status,['all','paid','pending','failed','refunded'],true))throw new InvalidArgumentException('Invalid payment status');
        $search=trim((string)($filters['search']??''));$page=max(1,(int)($filters['page']??1));$per=max(1,min(50,(int)($filters['per_page']??5)));
        // Each purchase appears once, from payments. Ledger payment credits and
        // reservation/release entries are not purchases or SMS usage.
        // Older live tables have different utf8mb4 collations. Normalize this
        // read only, not the tables, before combining references and descriptions.
        $collate=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' COLLATE utf8mb4_unicode_ci':'';
        $purchaseJoin=CreditPurchaseSettlement::available($pdo)?' LEFT JOIN credit_purchase_orders po ON po.payment_id=p.id':'';
        $purchaseCredits=$purchaseJoin!==''?'COALESCE(po.credits,p.credits_added)':'p.credits_added';
        // Reconciled acceptance does not invent sent_at. A unique canonical,
        // owned settlement supplies an accounting-observation date instead.
        // The scalar aggregate cannot multiply usage if legacy ledger data is
        // inconsistent; ambiguous or unrelated ledger rows supply no date.
        $settledAt="(SELECT MAX(sl.created_at) FROM wallet_ledger sl JOIN wallets sw ON sw.id=sl.wallet_id AND sw.user_id=sm.user_id WHERE sl.user_id=sm.user_id AND sl.campaign_id=sm.campaign_id AND sl.idempotency_key=CONCAT('sms-message:',sm.id,':settle') AND sl.operation='sms_settlement' AND sl.direction='debit' AND sl.payment_id IS NULL AND sl.amount=sm.actual_charge HAVING COUNT(*)=1)";
        $accepted=SmsMessageState::acceptedSql('sm');
        $sql="SELECT * FROM (
          SELECT CONCAT('payment-',p.id) id,p.created_at,'Purchase' type,p.merchant_reference$collate reference,
            p.merchant_reference$collate detail,p.amount, $purchaseCredits credits,
            (CASE WHEN p.status='completed' THEN 'paid' WHEN p.status='cancelled' THEN 'failed' ELSE p.status END)$collate status,'purchase_created_at'$collate date_semantics
          FROM payments p $purchaseJoin WHERE p.user_id=?
          UNION ALL
          SELECT CONCAT('campaign-',c.id),MAX(m.usage_at),'SMS usage',CAST(c.id AS CHAR)$collate,
            (CASE WHEN SUM(CASE WHEN m.sent_at IS NULL THEN 1 ELSE 0 END)>0 THEN CONCAT(c.name,' (includes charge-recorded dates; send times unverified)') ELSE c.name END)$collate,
            SUM(m.actual_charge),SUM(m.segment_count),'completed'$collate,
            (CASE WHEN SUM(CASE WHEN m.sent_at IS NULL THEN 1 ELSE 0 END)>0 THEN 'includes_charge_recorded_at' ELSE 'sent_at' END)$collate
          FROM sms_campaigns c JOIN (SELECT sm.*,COALESCE(sm.sent_at,$settledAt) usage_at FROM sms_messages sm WHERE $accepted) m ON m.campaign_id=c.id AND m.user_id=c.user_id
          WHERE c.user_id=? AND m.actual_charge>0 AND m.usage_at>=? AND m.usage_at<? GROUP BY c.id,c.name
          UNION ALL
          SELECT CONCAT('adjustment-',l.id),l.created_at,'Credit adjustment',l.idempotency_key$collate,
            COALESCE((SELECT t.description FROM wallet_transactions t WHERE t.wallet_id=l.wallet_id AND t.reference$collate=l.idempotency_key$collate LIMIT 1),REPLACE(l.operation,'_',' '))$collate,
            CASE WHEN l.direction='debit' THEN -l.amount ELSE l.amount END,
            CASE WHEN l.direction='debit' THEN -FLOOR((l.amount+0.000001)/?) ELSE FLOOR((l.amount+0.000001)/?) END,'completed'$collate,'adjustment_recorded_at'$collate
          FROM wallet_ledger l WHERE l.user_id=? AND l.payment_id IS NULL AND l.campaign_id IS NULL AND l.direction IN ('credit','debit') AND l.operation<>'payment_credit'
        ) activity WHERE created_at>=? AND created_at<?";
        $args=[$user,$user,$start,$end,$price,$price,$user,$start,$end];
        if($status!=='all'){$sql.=' AND type=\'Purchase\' AND status=?';$args[]=$status;}
        if($search!==''){$term='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';$sql.=" AND (reference LIKE ? ESCAPE '!' OR CAST(amount AS CHAR) LIKE ? ESCAPE '!')";array_push($args,$term,$term);}
        $q=$pdo->prepare('SELECT COUNT(*) FROM ('.$sql.') filtered');$q->execute($args);$total=(int)$q->fetchColumn();
        $q=$pdo->prepare($sql.' ORDER BY created_at DESC,id DESC LIMIT '.$per.' OFFSET '.(($page-1)*$per));$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){$row['amount']=(float)$row['amount'];$row['credits']=(int)$row['credits'];}unset($row);
        $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE user_id=? AND status='completed'");$q->execute([$user]);
        return ['transactions'=>$rows,'total'=>$total,'page'=>$page,'last_page'=>max(1,(int)ceil($total/$per)),'total_paid'=>(float)$q->fetchColumn()];
    }

    public static function recipientLabel(string $state, ?string $reason, bool $submitted=true): string
    {
        return SmsDeliveryPresentation::label($state,$reason,$submitted);
    }

    public static function reason(string $state, ?string $reason, bool $submitted=true): string
    {
        // Derive every explanation from the latest state, never raw provider text.
        return SmsDeliveryPresentation::details($state,$reason,$submitted);
    }

    public static function recipients(PDO $pdo, int $user, int $id, string $state='all', string $search='', string $segments='all', ?int $page=null): array
    {
        $q=$pdo->prepare('SELECT id,name FROM sms_campaigns WHERE id=? AND user_id=?');$q->execute([$id,$user]);$campaign=$q->fetch(PDO::FETCH_ASSOC);
        if(!$campaign)throw new OutOfBoundsException('Campaign not found');
        $where='m.user_id=? AND m.campaign_id=?';$args=[$user,$id];
        $choice=$state==='all'?'All recipients':($state==='failed_to_send'?'Failed to send':SmsDeliveryPresentation::label($state));
        $where.=SmsMessageState::filter($state);
        if(!in_array($segments,['all','1','multi'],true))throw new InvalidArgumentException('Invalid segment filter');
        if($segments==='1')$where.=' AND m.segment_count=1';
        if($segments==='multi')$where.=' AND m.segment_count>1';
        $sql="SELECT m.id,m.destination,m.state,m.segment_count,m.sent_at,m.last_error,m.actual_charge,
          (SELECT TRIM(CONCAT(COALESCE(c.name,''),' ',COALESCE(c.surname,''))) FROM contacts c WHERE c.user_id=m.user_id AND c.phone_normalized=m.destination ORDER BY c.id LIMIT 1) contact
          FROM sms_messages m WHERE $where";
        // Paginate the read query, not a fully materialised recipient array.
        // Exports omit page and keep the same search/status/segment predicate.
        $sql="SELECT * FROM ($sql) recipient_view";
        if($search!==''){
            $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],strtolower($search)).'%';
            $sql.=" WHERE LOWER(COALESCE(NULLIF(contact,''),'Unsaved recipient')) LIKE ? ESCAPE '!' OR LOWER(destination) LIKE ? ESCAPE '!'";
            $args[]=$pattern;$args[]=$pattern;
        }
        $count=$pdo->prepare("SELECT COUNT(*) FROM ($sql) matching_recipients");$count->execute($args);$total=(int)$count->fetchColumn();
        $sql.=' ORDER BY id';
        if($page!==null)$sql.=' LIMIT 50 OFFSET '.((max(1,$page)-1)*50);
        $q=$pdo->prepare($sql);$q->execute($args);$rows=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $m){
            $contact=$m['contact']?:'Unsaved recipient';
            $submitted=SmsMessageState::accepted($m);
            $rows[]=['id'=>(string)$m['id'],'contact'=>$contact,'number'=>$m['destination'],
                'state'=>$m['state'],'technical_status'=>SmsMessageState::technicalStatus($m),
                'reason_code'=>SmsDeliveryPresentation::reasonCode($m['state'],$m['last_error'],$submitted),
                'status'=>self::recipientLabel($m['state'],$m['last_error'],$submitted),
                'segments'=>(int)$m['segment_count'],'sent'=>$m['sent_at']?date('j M Y, H:i',strtotime($m['sent_at'])):'—',
                'result'=>self::reason($m['state'],$m['last_error'],$submitted)];
        }
        return ['name'=>$campaign['name'],'choice'=>$choice,'rows'=>$rows,'total'=>$total];
    }
}
