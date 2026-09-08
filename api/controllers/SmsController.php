<?php

require_once __DIR__.'/../domain/CampaignService.php';

final class SmsController
{
    public function preview(): void
    {
        try { Response::success(['preview'=>CampaignService::preview((int)Auth::id(),Request::input())]); }
        catch(InvalidArgumentException|DomainException $e){Response::error($e->getMessage(),422);}
    }

    public function index(): void
    {
        $page=max(1,(int)Request::query('page',1)); $per=max(1,min(100,(int)Request::query('per_page',20))); $user=(int)Auth::id();
        $status=trim((string)Request::query('status','')); $search=trim((string)Request::query('search',''));
        $allowed=['draft','scheduled','queued','processing','completed','partially_failed','cancelled'];
        if($status!==''&&!in_array($status,$allowed,true)){Response::error('Invalid campaign status',422);return;}
        $where=['user_id=?'];$args=[$user];
        if($status!==''){$where[]='state=?';$args[]=$status;}
        if($search!==''){$where[]='name LIKE ?';$args[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search).'%';}
        $predicate=implode(' AND ',$where);$pdo=db();
        $reconcile=$pdo->prepare("UPDATE sms_campaigns sc SET state=IF(EXISTS(SELECT 1 FROM sms_messages failed WHERE failed.campaign_id=sc.id AND failed.state='failed'),'partially_failed','completed'),completed_at=COALESCE(completed_at,NOW()),processing_count=0,queued_count=0,updated_at=NOW() WHERE sc.user_id=? AND sc.state='processing' AND NOT EXISTS(SELECT 1 FROM sms_messages active WHERE active.campaign_id=sc.id AND active.state IN ('pending','retry','processing'))");
        $reconcile->execute([$user]);
        $count=$pdo->prepare("SELECT COUNT(*) FROM sms_campaigns WHERE {$predicate}");$count->execute($args);$total=(int)$count->fetchColumn();
        $list=$pdo->prepare("SELECT sc.*,(SELECT COUNT(*) FROM sms_messages unavailable WHERE unavailable.campaign_id=sc.id AND unavailable.state='sent' AND unavailable.last_error LIKE 'DLR lookup:%') AS dlr_unavailable_count FROM sms_campaigns sc WHERE {$predicate} ORDER BY created_at DESC LIMIT {$per} OFFSET ".(($page-1)*$per));$list->execute($args);$rows=$list->fetchAll();
        foreach($rows as &$row){
            $row['status']=$row['state'];
            $price=(float)($row['price_per_segment'] ?? 0);
            $actualCost=(float)($row['actual_cost'] ?? 0);
            $row['recipient_count']=(int)($row['recipient_count'] ?? 0);
            $row['sent_count']=(int)($row['sent_count'] ?? 0);
            $row['delivered_count']=(int)($row['delivered_count'] ?? 0);
            $row['failed_count']=(int)($row['failed_count'] ?? 0);
            $row['queued_count']=(int)($row['queued_count'] ?? 0);
            $row['processing_count']=(int)($row['processing_count'] ?? 0);
            $row['skipped_count']=(int)($row['skipped_count'] ?? 0);
            $row['dlr_unavailable_count']=(int)($row['dlr_unavailable_count']??0);
            $row['awaiting_delivery_count']=max(0,$row['sent_count']-$row['delivered_count']-$row['dlr_unavailable_count']);
            $row['amount_spent']=$actualCost;
            $row['credits_used']=$price>0 ? (int)round($actualCost/$price) : 0;
        }
        $all=table('sms_campaigns')->where('user_id',$user)->get();$sent=0;$scheduled=0;$used=0;
        foreach($all as $row){
            $sent+=(int)($row['sent_count'] ?? 0);
            if($row['state']==='scheduled')$scheduled++;
            $price=(float)($row['price_per_segment'] ?? 0);
            if($price>0)$used+=(int)round(((float)$row['actual_cost'])/$price);
        }
        Response::success(['campaigns'=>$rows,'stats'=>['total'=>$total,'sent'=>$sent,'scheduled'=>$scheduled,'credits_used'=>$used],'meta'=>['page'=>$page,'per_page'=>$per,'total'=>$total]]);
    }

    public function store(): void
    {
        RateLimiter::checkOrFail('sms-create:'.Auth::id(),20,60);
        try { Response::created(['campaign'=>CampaignService::create((int)Auth::id(),Request::input())]); }
        catch(InvalidArgumentException|DomainException $e){Response::error($e->getMessage(),422);}
    }

    public function show(array $params): void
    {
        try { Response::success(['campaign'=>CampaignService::findOwned((int)Auth::id(),(int)$params['id'])]); }
        catch(RuntimeException $e){Response::error('Campaign not found',404);}
    }

    public function queue(array $params): void
    {
        RateLimiter::checkOrFail('sms-queue:'.Auth::id(),20,60);
        try { Response::success(['campaign'=>CampaignService::queue((int)Auth::id(),(int)$params['id'])]); }
        catch(RuntimeException $e){Response::error('Campaign not found',404);}
        catch(DomainException $e){Response::error($e->getMessage(),409);}
    }

    public function cancel(array $params): void
    {
        $user=(int)Auth::id(); $pdo=db(); $pdo->beginTransaction();
        try {
            $s=$pdo->prepare('SELECT * FROM sms_campaigns WHERE id=? AND user_id=? FOR UPDATE'); $s->execute([(int)$params['id'],$user]); $c=$s->fetch();
            if(!$c) throw new RuntimeException();
            if(!in_array($c['state'],['draft','scheduled','queued'],true)) throw new DomainException('Campaign can no longer be cancelled');
            $pdo->prepare("UPDATE sms_campaigns SET state='cancelled',cancelled_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$c['id']]);
            WalletService::release($pdo,$user,(int)$c['id'],(float)$c['estimated_cost'],'cancel'); $pdo->commit();
            Response::success(['campaign'=>CampaignService::findOwned($user,(int)$c['id'])]);
        } catch(RuntimeException $e){if($pdo->inTransaction())$pdo->rollBack();Response::error('Campaign not found',404);}
        catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();Response::error($e->getMessage(),409);}
    }

    public function retry(array $params): void
    {
        RateLimiter::checkOrFail('sms-retry:'.Auth::id(),10,60);
        $user=(int)Auth::id();$campaignId=(int)$params['id'];$pdo=db();$pdo->beginTransaction();
        try{
            $campaignStatement=$pdo->prepare('SELECT * FROM sms_campaigns WHERE id=? AND user_id=? FOR UPDATE');
            $campaignStatement->execute([$campaignId,$user]);$campaign=$campaignStatement->fetch();
            if(!$campaign)throw new RuntimeException('Campaign not found');
            if(in_array($campaign['state'],['cancelled','draft','scheduled'],true))throw new DomainException('This campaign is not eligible for retry');
            $messageStatement=$pdo->prepare("SELECT * FROM sms_messages WHERE campaign_id=? AND user_id=? AND state IN ('failed','retry') ORDER BY id FOR UPDATE");
            $messageStatement->execute([$campaignId,$user]);$messages=$messageStatement->fetchAll();
            if(!$messages)throw new DomainException('There are no failed or retryable messages in this campaign');
            $failed=array_values(array_filter($messages,static fn(array $message):bool=>$message['state']==='failed'));
            $retryAmount=array_sum(array_map(static fn(array $message):float=>(float)$message['estimated_charge'],$failed));
            $columnCheck=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sms_messages' AND column_name='retry_generation'");$hasRetryGeneration=(bool)$columnCheck->fetchColumn();
            if($failed){
                $retryKey=hash('sha256',implode(',',array_map(static fn(array $message):string=>$message['id'].':'.((int)($message['retry_generation']??$message['attempt_count']??0)+1),$failed)));
                WalletService::reserveRetry($pdo,$user,$campaignId,$retryAmount,$retryKey);
            }
            $ids=array_column($messages,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
            $generationSql=$hasRetryGeneration?",retry_generation=retry_generation+IF(state='failed',1,0)":'';
            $update=$pdo->prepare("UPDATE sms_messages SET state='retry',attempt_count=0{$generationSql},next_attempt_at=NOW(),lease_token=NULL,leased_at=NULL,last_error=NULL,failed_at=NULL,updated_at=NOW() WHERE id IN ({$marks}) AND state IN ('failed','retry')");
            $update->execute($ids);
            $pdo->prepare("UPDATE sms_campaigns SET state='queued',queued_count=?,processing_count=0,failed_count=0,completed_at=NULL,updated_at=NOW() WHERE id=?")->execute([count($messages),$campaignId]);
            $pdo->commit();
            Response::success(['campaign'=>CampaignService::findOwned($user,$campaignId),'retried_messages'=>count($messages)]);
        }catch(RuntimeException $e){if($pdo->inTransaction())$pdo->rollBack();Response::error('Campaign not found',404);}
        catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();Response::error($e->getMessage(),409);}
        catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('SMS campaign retry failed: '.$e->getMessage());Response::error('Campaign retry could not be started',500);}
    }

    public function messages(array $params): void
    {
        try {$campaign=CampaignService::findOwned((int)Auth::id(),(int)$params['id']);}
        catch(RuntimeException $e){Response::error('Campaign not found',404);return;}
        $page=max(1,(int)Request::query('page',1)); $per=max(1,min(100,(int)Request::query('per_page',50)));
        $state=trim((string)Request::query('state',''));
        $allowed=['pending','retry','processing','sent','delivered','failed','skipped'];
        if($state!==''&&!in_array($state,$allowed,true)){Response::error('Invalid message state',422);return;}
        $where="m.campaign_id=? AND m.user_id=?";$args=[(int)$campaign['id'],(int)Auth::id()];
        if($state!==''){$where.=' AND m.state=?';$args[]=$state;}
        $count=db()->prepare("SELECT COUNT(*) FROM sms_messages m WHERE {$where}");$count->execute($args);$total=(int)$count->fetchColumn();
        $offset=($page-1)*$per;
        $query=db()->prepare("SELECT m.*,c.id AS contact_id,c.name AS contact_name,c.surname AS contact_surname,c.email AS contact_email FROM sms_messages m LEFT JOIN contacts c ON c.user_id=m.user_id AND c.phone_normalized=m.destination WHERE {$where} ORDER BY m.id ASC LIMIT {$per} OFFSET {$offset}");
        $query->execute($args);$rows=$query->fetchAll();
        foreach($rows as &$row){
            if($row['state']==='sent'&&!$row['delivered_at']&&!$row['failed_at'])$row['failed_at']=str_starts_with((string)($row['last_error']??''),'DLR lookup:')?'Provider DLR unavailable':'Waiting for provider delivery receipt';
            if($row['state']==='delivered'&&empty($row['last_error']))$row['last_error']='Delivered successfully';
            elseif($row['state']==='sent'&&empty($row['last_error']))$row['last_error']='LogicSMS accepted the SMS; final DLR has not been received';
            elseif(empty($row['last_error']))$row['last_error']='No additional delivery details';
            if($row['state']==='sent'&&str_starts_with((string)$row['last_error'],'DLR lookup:'))$row['state']='dlr_unavailable';
        }unset($row);
        Response::success(['messages'=>$rows,'meta'=>['page'=>$page,'per_page'=>$per,'total'=>$total]]);
    }

    public function export(array $params): void
    {
        try {$campaign=CampaignService::findOwned((int)Auth::id(),(int)$params['id']);}
        catch(RuntimeException $e){Response::error('Campaign not found',404);return;}
        $statement=db()->prepare('SELECT m.*,c.name AS contact_name,c.surname AS contact_surname,c.email AS contact_email FROM sms_messages m LEFT JOIN contacts c ON c.user_id=m.user_id AND c.phone_normalized=m.destination WHERE m.campaign_id=? AND m.user_id=? ORDER BY m.id ASC');
        $statement->execute([(int)$campaign['id'],(int)Auth::id()]);$rows=$statement->fetchAll();
        header('Content-Type:text/csv'); header('Content-Disposition:attachment; filename="sms-campaign-'.$campaign['id'].'.csv"'); $out=fopen('php://output','w');
        fputcsv($out,['Contact','Email','Destination','State','Encoding','Segments','Charge','Provider ID','Sent At','Delivered At','Failed At','Error']);
        foreach($rows as $row){$safe=fn($v)=>is_string($v)&&preg_match('/^[=+\-@]/',$v)?"'".$v:$v;$contact=trim((string)($row['contact_name']??'').' '.(string)($row['contact_surname']??''));fputcsv($out,array_map($safe,[$contact,$row['contact_email']??null,$row['destination'],$row['state'],$row['encoding'],$row['segment_count'],$row['actual_charge'],$row['provider_message_id'],$row['sent_at'],$row['delivered_at'],$row['failed_at'],$row['last_error']]));}
        fclose($out); exit;
    }
}
