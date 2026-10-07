<?php
require_once __DIR__.'/../domain/SmsDeliveryPresentation.php';
require_once __DIR__.'/../services/SmsReportDocument.php';

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
        $count=$pdo->prepare("SELECT COUNT(*) FROM sms_campaigns WHERE {$predicate}");$count->execute($args);$total=(int)$count->fetchColumn();
        // Use the same filtered message-backed records for both rows and totals.
        $filtered=$pdo->prepare("SELECT * FROM sms_campaigns WHERE {$predicate} ORDER BY created_at DESC,id DESC");
        $filtered->execute($args);
        $all=SmsReportingService::campaignRecords($user,$filtered->fetchAll());
        $rows=array_slice($all,($page-1)*$per,$per);$sent=0;$scheduled=0;$used=0;
        foreach($all as $row){
            $sent+=$row['recipient_count']-$row['skipped_count'];
            if($row['state']==='scheduled')$scheduled++;
            $used+=(int)$row['credits_used'];
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
        $allowed=['pending','retry','processing','sent','delivered','failed','skipped','dlr_unavailable'];
        if($state!==''&&!in_array($state,$allowed,true)){Response::error('Invalid message state',422);return;}
        $where="m.campaign_id=? AND m.user_id=?";$args=[(int)$campaign['id'],(int)Auth::id()];
        $where.=SmsReportDocument::filter($state,trim((string)Request::query('search','')),$args);
        $count=db()->prepare("SELECT COUNT(*) FROM sms_messages m WHERE {$where}");$count->execute($args);$total=(int)$count->fetchColumn();
        $offset=($page-1)*$per;
        $query=db()->prepare("SELECT m.*,c.id AS contact_id,c.name AS contact_name,c.surname AS contact_surname,c.email AS contact_email FROM sms_messages m LEFT JOIN contacts c ON c.user_id=m.user_id AND c.phone_normalized=m.destination WHERE {$where} ORDER BY m.id ASC LIMIT {$per} OFFSET {$offset}");
        $query->execute($args);$rows=$query->fetchAll();
        $rows=array_map([self::class,'deliveryDetails'],$rows);
        $summary=db()->prepare("SELECT CASE WHEN m.state='sent' AND m.last_error LIKE 'DLR lookup:%' THEN 'dlr_unavailable' ELSE m.state END report_state,COUNT(*) count FROM sms_messages m WHERE {$where} GROUP BY report_state");$summary->execute($args);
        Response::success(['messages'=>$rows,'counts'=>array_column($summary->fetchAll(),'count','report_state'),'meta'=>['page'=>$page,'per_page'=>$per,'total'=>$total]]);
    }

    private static function deliveryDetails(array $row): array
    {
            $rawDetail=$row['last_error']??null;
            if($row['state']==='sent'&&str_starts_with((string)$rawDetail,'DLR lookup:'))$row['state']='dlr_unavailable';
            $row['delivery_label']=SmsDeliveryPresentation::label($row['state'],$rawDetail);
            $row['last_error']=SmsDeliveryPresentation::details($row['state'],$rawDetail);
        return $row;
    }

    public function export(array $params): void
    {
        try {
            $r=SmsReportDocument::load((int)Auth::id(),(int)$params['id'],'7d',(string)Request::query('state',''),trim((string)Request::query('search','')));
            SmsReportDocument::download($r,strtolower((string)Request::query('format','csv')));
        }catch(InvalidArgumentException $e){Response::error($e->getMessage(),422);}
        catch(RuntimeException $e){Response::error('Campaign not found',404);}
    }

}
