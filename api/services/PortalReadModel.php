<?php
/** Read-only presentation data. Does not queue sends, alter balances or update statuses. */
final class PortalReadModel
{
    public static function overview(PDO $pdo, int $user): array
    {
        $scalar = static function(string $sql) use ($pdo, $user) { $q=$pdo->prepare($sql);$q->execute([$user]);return $q->fetchColumn(); };
        return [
            'contacts'=>(int)$scalar('SELECT COUNT(*) FROM contacts WHERE user_id=?'),
            'bought_credits'=>(int)$scalar("SELECT COUNT(*) FROM payments WHERE user_id=? AND status='completed'")>0,
            'sent_campaign'=>(int)$scalar("SELECT COUNT(*) FROM sms_messages WHERE user_id=? AND (sent_at IS NOT NULL OR state IN ('sent','delivered'))")>0,
            'checked_delivery'=>(int)$scalar("SELECT COUNT(*) FROM sms_messages WHERE user_id=? AND (state='delivered' OR (state='failed' AND sent_at IS NOT NULL))")>0,
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
        $sql="SELECT * FROM (
          SELECT CONCAT('payment-',p.id) id,p.created_at,'Purchase' type,p.merchant_reference$collate reference,
            p.merchant_reference$collate detail,p.amount, p.credits_added credits,
            (CASE WHEN p.status='completed' THEN 'paid' WHEN p.status='cancelled' THEN 'failed' ELSE p.status END)$collate status
          FROM payments p WHERE p.user_id=?
          UNION ALL
          SELECT CONCAT('campaign-',c.id),MAX(m.sent_at),'SMS usage',CAST(c.id AS CHAR)$collate,c.name$collate,SUM(m.actual_charge),SUM(m.segment_count),'completed'$collate
          FROM sms_campaigns c JOIN sms_messages m ON m.campaign_id=c.id AND m.user_id=c.user_id
          WHERE c.user_id=? AND m.actual_charge>0 AND m.sent_at>=? AND m.sent_at<? GROUP BY c.id,c.name
          UNION ALL
          SELECT CONCAT('adjustment-',l.id),l.created_at,'Credit adjustment',l.idempotency_key$collate,
            COALESCE((SELECT t.description FROM wallet_transactions t WHERE t.wallet_id=l.wallet_id AND t.reference$collate=l.idempotency_key$collate LIMIT 1),REPLACE(l.operation,'_',' '))$collate,
            CASE WHEN l.direction='debit' THEN -l.amount ELSE l.amount END,
            CASE WHEN l.direction='debit' THEN -FLOOR((l.amount+0.000001)/?) ELSE FLOOR((l.amount+0.000001)/?) END,'completed'$collate
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

    public static function recipientLabel(string $state, ?string $reason): string
    {
        return match($state){'delivered'=>'Delivered','failed'=>'Not Delivered','pending','queued','retry'=>'Pending dispatch','processing'=>'Processing','sent'=>str_starts_with((string)$reason,'DLR lookup:')?'Delivery Status Unavailable':'Delivery Confirmation Pending','skipped'=>'Skipped',default=>ucfirst(str_replace('_',' ',$state))};
    }

    public static function reason(string $state, ?string $reason): string
    {
        // A former lookup error must not survive as the explanation of a newer outcome.
        if($state!=='failed'||!$reason||str_starts_with($reason,'DLR lookup:'))return '—';
        return preg_replace('/^(Provider|Network|SMS)\s*(error|failure)?\s*:\s*/i','',trim($reason));
    }

    public static function recipients(PDO $pdo, int $user, int $id, string $state='all', string $search=''): array
    {
        $q=$pdo->prepare('SELECT id,name FROM sms_campaigns WHERE id=? AND user_id=?');$q->execute([$id,$user]);$campaign=$q->fetch(PDO::FETCH_ASSOC);
        if(!$campaign)throw new OutOfBoundsException('Campaign not found');
        $where='m.user_id=? AND m.campaign_id=?';$args=[$user,$id];
        $choice=match($state){'all'=>'All recipients','pending'=>'Pending dispatch','processing'=>'Processing','sent'=>'Delivery Confirmation Pending','delivered'=>'Delivered','failed'=>'Not Delivered',default=>throw new InvalidArgumentException('Invalid recipient filter')};
        if($state==='pending')$where.=" AND m.state IN ('pending','queued','retry')";
        elseif($state==='sent')$where.=" AND m.state='sent' AND (m.last_error IS NULL OR m.last_error NOT LIKE 'DLR lookup:%')";
        elseif($state!=='all'){$where.=' AND m.state=?';$args[]=$state;}
        $sql="SELECT m.id,m.destination,m.state,m.segment_count,m.sent_at,m.last_error,
          (SELECT TRIM(CONCAT(COALESCE(c.name,''),' ',COALESCE(c.surname,''))) FROM contacts c WHERE c.user_id=m.user_id AND c.phone_normalized=m.destination ORDER BY c.id LIMIT 1) contact
          FROM sms_messages m WHERE $where ORDER BY m.id";
        $q=$pdo->prepare($sql);$q->execute($args);$rows=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $m){
            $contact=$m['contact']?:'Unsaved recipient';
            if($search!==''&&stripos($contact,$search)===false&&stripos($m['destination'],$search)===false)continue;
            $rows[]=['id'=>(string)$m['id'],'contact'=>$contact,'number'=>$m['destination'],'status'=>self::recipientLabel($m['state'],$m['last_error']),'segments'=>(int)$m['segment_count'],'sent'=>$m['sent_at']?date('j M Y, H:i',strtotime($m['sent_at'])):'—','result'=>self::reason($m['state'],$m['last_error'])];
        }
        return ['name'=>$campaign['name'],'choice'=>$choice,'rows'=>$rows,'total'=>count($rows)];
    }
}
