<?php

require_once __DIR__.'/../services/SmsService.php';
require_once __DIR__.'/../services/PaymentReceiptService.php';

final class AdminOperationsController
{
    private function admin(): void
    {
        $user=Auth::user();
        if(!$user||($user['role']??'')!=='admin')Response::error('Unauthorized',403);
    }

    private function page(): array
    {
        $page=max(1,(int)Request::query('page',1));
        $perPage=max(1,min(100,(int)Request::query('per_page',25)));
        return [$page,$perPage,($page-1)*$perPage];
    }

    public function overview(): void
    {
        $this->admin();$pdo=db();
        $users=$pdo->query("SELECT COUNT(*) total,SUM(is_active=1) active,SUM(email_verified_at IS NOT NULL) verified FROM users WHERE role<>'admin'")->fetch();
        $campaigns=$pdo->query("SELECT COUNT(*) total,SUM(state='scheduled') scheduled,SUM(state IN ('queued','processing')) active,SUM(state='completed') completed,SUM(state='partially_failed') partially_failed,COALESCE(SUM(actual_cost),0) consumed FROM sms_campaigns")->fetch();
        $messages=$pdo->query("SELECT COUNT(*) total,SUM(state='pending' OR state='retry') queued,SUM(state='processing') processing,SUM(state='sent') sent,SUM(state='delivered') delivered,SUM(state='failed') failed,SUM(state='skipped') skipped,COALESCE(SUM(actual_charge),0) charged FROM sms_messages")->fetch();
        $wallets=$pdo->query("SELECT COUNT(*) total,COALESCE(SUM(balance),0) balance,COALESCE(SUM(reserved),0) reserved FROM wallets WHERE currency='ZAR'")->fetch();
        $payments=$pdo->query("SELECT COUNT(*) total,SUM(status='completed') completed,COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) received,COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) pending FROM payments WHERE currency='ZAR'")->fetch();
        $customers=$pdo->query("SELECT u.id,u.name,u.email,u.is_active,u.created_at,COALESCE(w.balance,0) balance,COALESCE(w.reserved,0) reserved,COUNT(DISTINCT sc.id) campaigns,COUNT(DISTINCT sm.id) messages FROM users u LEFT JOIN wallets w ON w.user_id=u.id LEFT JOIN sms_campaigns sc ON sc.user_id=u.id LEFT JOIN sms_messages sm ON sm.user_id=u.id WHERE u.role<>'admin' GROUP BY u.id,u.name,u.email,u.is_active,u.created_at,w.balance,w.reserved ORDER BY u.created_at DESC LIMIT 8")->fetchAll();
        Response::success(['users'=>$users,'campaigns'=>$campaigns,'messages'=>$messages,'wallets'=>$wallets,'payments'=>$payments,'recent_customers'=>$customers]);
    }

    public function services(): void
    {
        $this->admin();$pdo=db();$rows=$pdo->query('SELECT service_name,instance_id,metadata_json,last_seen_at,TIMESTAMPDIFF(SECOND,last_seen_at,NOW()) age_seconds FROM service_heartbeats ORDER BY service_name')->fetchAll();
        $limits=['sms-worker'=>120,'sms-scheduler'=>180,'sms-dlr-poller'=>300];
        foreach($rows as &$row){$row['metadata']=$row['metadata_json']?json_decode($row['metadata_json'],true):null;unset($row['metadata_json']);$row['healthy']=(int)$row['age_seconds']<=($limits[$row['service_name']]??300);}unset($row);
        Response::success(['services'=>$rows]);
    }

    public function provider(): void
    {
        $this->admin();
        try {
            $result=(new SmsService())->getCredits();
        } catch (Throwable $exception) {
            error_log('LogicSMS provider balance check failed: '.$exception->getMessage());
            $result=['success'=>false,'error'=>'Provider balance check failed'];
        }
        $threshold=max(0,(float)env('LOGICSMS_LOW_CREDIT_THRESHOLD',100));
        if(empty($result['success'])){Response::success(['provider'=>'logicsms','available'=>false,'credits'=>null,'low'=>null,'threshold'=>$threshold,'error'=>$result['error']??'Provider query failed']);return;}
        Response::success(['provider'=>'logicsms','available'=>true,'credits'=>(float)$result['credits'],'low'=>(float)$result['credits']<$threshold,'threshold'=>$threshold,'checked_at'=>date('c')]);
    }

    public function logs(): void
    {
        $this->admin();[$page,$perPage,$offset]=$this->page();$pdo=db();
        $level=trim((string)Request::query('level',''));$service=trim((string)Request::query('service',''));$search=trim((string)Request::query('search',''));
        $where=['1=1'];$args=[];
        if($level!==''){$where[]='level=?';$args[]=$level;}
        if($service!==''){$where[]='service=?';$args[]=$service;}
        if($search!==''){$where[]='(event LIKE ? OR message LIKE ?)';$args[]='%'.$search.'%';$args[]='%'.$search.'%';}
        $predicate=implode(' AND ',$where);$count=$pdo->prepare("SELECT COUNT(*) FROM operational_logs WHERE {$predicate}");$count->execute($args);$total=(int)$count->fetchColumn();
        $stmt=$pdo->prepare("SELECT id,level,service,event,message,campaign_id,sms_message_id,user_id,context_json,created_at FROM operational_logs WHERE {$predicate} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}");$stmt->execute($args);$logs=$stmt->fetchAll();
        foreach($logs as &$log){$log['context']=$log['context_json']?json_decode($log['context_json'],true):null;unset($log['context_json']);}unset($log);
        $runs=$pdo->query("SELECT id,service_name,instance_id,status,processed_count,failed_count,message,started_at,completed_at,duration_ms FROM service_job_runs ORDER BY id DESC LIMIT 50")->fetchAll();
        Response::success(['logs'=>$logs,'runs'=>$runs,'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'last_page'=>(int)ceil($total/$perPage)] ]);
    }

    public function customers(): void
    {
        $this->admin();[$page,$perPage,$offset]=$this->page();$pdo=db();$search=trim((string)Request::query('search',''));$where="u.role<>'admin'";$args=[];
        if($search!==''){$where.=' AND (u.name LIKE ? OR u.email LIKE ?)';$args[]='%'.$search.'%';$args[]='%'.$search.'%';}
        $count=$pdo->prepare("SELECT COUNT(*) FROM users u WHERE $where");$count->execute($args);$total=(int)$count->fetchColumn();
        $sql="SELECT u.id,u.name,u.email,u.is_active,u.email_verified_at,u.created_at,u.last_login_at,COALESCE(w.balance,0) balance,COALESCE(w.reserved,0) reserved,(SELECT COUNT(*) FROM contacts c WHERE c.user_id=u.id) contacts,(SELECT COUNT(*) FROM contact_groups g WHERE g.user_id=u.id) contact_groups,(SELECT COUNT(*) FROM templates t WHERE t.user_id=u.id) templates,(SELECT COUNT(*) FROM opt_outs o WHERE o.user_id=u.id) opt_outs,(SELECT COUNT(*) FROM sms_campaigns sc WHERE sc.user_id=u.id) campaigns,(SELECT COUNT(*) FROM sms_messages sm WHERE sm.user_id=u.id) messages FROM users u LEFT JOIN wallets w ON w.user_id=u.id WHERE $where ORDER BY u.created_at DESC LIMIT $perPage OFFSET $offset";
        $stmt=$pdo->prepare($sql);$stmt->execute($args);Response::success(['customers'=>$stmt->fetchAll(),'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'last_page'=>(int)ceil($total/$perPage)]]);
    }

    public function customer(array $params): void
    {
        $this->admin();$id=(int)$params['id'];$pdo=db();$stmt=$pdo->prepare("SELECT u.id,u.name,u.email,u.role,u.is_active,u.email_verified_at,u.created_at,u.last_login_at,COALESCE(w.id,0) wallet_id,COALESCE(w.balance,0) balance,COALESCE(w.reserved,0) reserved,COALESCE(w.currency,'ZAR') currency FROM users u LEFT JOIN wallets w ON w.user_id=u.id WHERE u.id=?");$stmt->execute([$id]);$customer=$stmt->fetch();if(!$customer)Response::error('Customer not found',404);
        $counts=[];foreach(['contacts'=>'contacts','contact_groups'=>'contact_groups','templates'=>'templates','opt_outs'=>'opt_outs','campaigns'=>'sms_campaigns','messages'=>'sms_messages'] as $key=>$table){$q=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE user_id=?");$q->execute([$id]);$counts[$key]=(int)$q->fetchColumn();}
        $q=$pdo->prepare("SELECT id,name,state,sender_id,recipient_count,sent_count,delivered_count,failed_count,skipped_count,estimated_cost,actual_cost,scheduled_at,created_at FROM sms_campaigns WHERE user_id=? ORDER BY created_at DESC LIMIT 10");$q->execute([$id]);$campaigns=$q->fetchAll();
        $q=$pdo->prepare("SELECT id,gateway,merchant_reference,amount,currency,status,payment_method,created_at,processed_at FROM payments WHERE user_id=? ORDER BY created_at DESC LIMIT 10");$q->execute([$id]);$payments=$q->fetchAll();
        $q=$pdo->prepare("SELECT id,operation,direction,amount,campaign_id,payment_id,created_at FROM wallet_ledger WHERE user_id=? ORDER BY created_at DESC LIMIT 20");$q->execute([$id]);$ledger=$q->fetchAll();
        Response::success(['customer'=>$customer,'counts'=>$counts,'campaigns'=>$campaigns,'payments'=>$payments,'ledger'=>$ledger]);
    }

    public function campaigns(): void
    {
        $this->admin();[$page,$perPage,$offset]=$this->page();$pdo=db();$state=trim((string)Request::query('state',''));$search=trim((string)Request::query('search',''));$where='1=1';$args=[];
        if($state!==''){$where.=' AND sc.state=?';$args[]=$state;}if($search!==''){$where.=' AND (sc.name LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';$args=array_merge($args,['%'.$search.'%','%'.$search.'%','%'.$search.'%']);}
        $count=$pdo->prepare("SELECT COUNT(*) FROM sms_campaigns sc JOIN users u ON u.id=sc.user_id WHERE $where");$count->execute($args);$total=(int)$count->fetchColumn();
        $sql="SELECT sc.*,u.name user_name,u.email user_email FROM sms_campaigns sc JOIN users u ON u.id=sc.user_id WHERE $where ORDER BY sc.created_at DESC LIMIT $perPage OFFSET $offset";$stmt=$pdo->prepare($sql);$stmt->execute($args);
        Response::success(['campaigns'=>$stmt->fetchAll(),'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'last_page'=>(int)ceil($total/$perPage)]]);
    }

    public function campaign(array $params): void
    {
        $this->admin();$id=(int)$params['id'];[$page,$perPage,$offset]=$this->page();$pdo=db();$q=$pdo->prepare('SELECT sc.*,u.name user_name,u.email user_email FROM sms_campaigns sc JOIN users u ON u.id=sc.user_id WHERE sc.id=?');$q->execute([$id]);$campaign=$q->fetch();if(!$campaign)Response::error('Campaign not found',404);
        $state=trim((string)Request::query('state',''));$where='campaign_id=?';$args=[$id];if($state!==''){$where.=' AND state=?';$args[]=$state;}$count=$pdo->prepare("SELECT COUNT(*) FROM sms_messages WHERE $where");$count->execute($args);$total=(int)$count->fetchColumn();$q=$pdo->prepare("SELECT id,destination,state,segment_count,estimated_charge,actual_charge,provider,provider_message_id,attempt_count,last_error,sent_at,delivered_at,failed_at,created_at FROM sms_messages WHERE $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");$q->execute($args);
        Response::success(['campaign'=>$campaign,'messages'=>$q->fetchAll(),'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'last_page'=>(int)ceil($total/$perPage)]]);
    }

    public function finance(): void
    {
        $this->admin();$pdo=db();$payments=$pdo->query("SELECT COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) received,COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) pending,COALESCE(SUM(CASE WHEN status IN ('failed','cancelled') THEN amount ELSE 0 END),0) unsuccessful,COUNT(*) total FROM payments WHERE currency='ZAR'")->fetch();
        $ledger=$pdo->query("SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE 0 END),0) credits_issued,COALESCE(SUM(CASE WHEN direction='debit' THEN amount ELSE 0 END),0) credits_debited,COUNT(*) entries FROM wallet_ledger")->fetch();
        $wallets=$pdo->query("SELECT COALESCE(SUM(balance),0) balance,COALESCE(SUM(reserved),0) reserved,COUNT(*) wallets FROM wallets WHERE currency='ZAR'")->fetch();
        $recent=$pdo->query("SELECT p.id,p.user_id,u.name user_name,u.email user_email,p.gateway,p.merchant_reference,p.amount,p.currency,p.status,p.created_at,p.processed_at FROM payments p JOIN users u ON u.id=p.user_id ORDER BY p.created_at DESC LIMIT 25")->fetchAll();
        Response::success(['payments'=>$payments,'ledger'=>$ledger,'wallets'=>$wallets,'recent_payments'=>$recent]);
    }

    public function retryPaymentReceipt(array $params): void
    {
        $this->admin();$paymentId=(int)($params['id']??0);
        $payment=table('payments')->where('id',$paymentId)->first();
        if(!$payment)Response::error('Payment not found',404);
        if(($payment['status']??'')!=='completed')Response::error('Only completed payments can send a receipt',409);
        $queued=PaymentReceiptService::enqueue(db(),$paymentId,(int)$payment['user_id']);
        if($queued)table('payment_receipt_outbox')->where('payment_id',$paymentId)->where('status','failed')->update(['status'=>'pending','available_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        $result=PaymentReceiptService::dispatch($paymentId);
        Response::success(['receipt_email_status'=>$result['status'],'sent'=>!empty($result['success'])]);
    }
}
