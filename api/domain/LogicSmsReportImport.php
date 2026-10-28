<?php
declare(strict_types=1);
require_once __DIR__.'/PhoneNumber.php';
require_once __DIR__.'/LogicSmsDeliveryStatus.php';
require_once __DIR__.'/SmsStatusReconciliation.php';

/** Owner-authorised report import: exact tenant + number + content + time, never number alone. */
final class LogicSmsReportImport
{
    public static function preview(PDO $pdo,array $report,array $scopes):array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',(string)($report['file_sha256']??''))||!is_array($report['rows']??null)||count($report['rows'])>10000)throw new InvalidArgumentException('Invalid bounded report');
        if(!$scopes||count($scopes)>20)throw new InvalidArgumentException('Explicit account scopes are required');
        $accounts=[];
        foreach($scopes as $index=>$scope){
            $email=strtolower(trim((string)($scope['email']??'')));$hash=(string)($scope['content_sha256']??'');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new InvalidArgumentException('Each scope requires exact owner and full content hash');
            $q=$pdo->prepare('SELECT id,email FROM users WHERE LOWER(TRIM(email))=? ORDER BY id LIMIT 2');$q->execute([$email]);$users=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($users)!==1)throw new DomainException('Report account is not unique');
            $accounts[$index]=['id'=>(int)$users[0]['id'],'email'=>$email,'content_sha256'=>$hash,'campaign_id'=>isset($scope['campaign_id'])?(int)$scope['campaign_id']:null];
        }
        $rows=[];$matches=[];$seen=[];
        foreach($report['rows'] as $row){
            $content=(string)($row['content']??'');$hash=hash('sha256',$content);
            $applicable=array_filter($accounts,static fn($account)=>$account['content_sha256']===$hash);
            if(!$applicable)continue; // Do not expose or change unrelated provider-account customers.
            $destination=PhoneNumber::normalize((string)($row['destination']??''));
            $reported=self::wallTime((string)($row['reported_at']??''));
            $rawStatus=strtoupper(trim((string)($row['status']??'')));
            $parsed=LogicSmsDeliveryStatus::parse($rawStatus);$state=$parsed?LogicSmsDeliveryStatus::normalize($rawStatus):'unknown';
            $display=['source_row'=>(int)($row['source_row']??0),'destination'=>$destination,'reported_at'=>$reported->format('Y-m-d H:i:s'),'provider_status'=>$rawStatus,'status_state'=>$state];
            $candidates=[];
            foreach($applicable as $account){
                $sql='SELECT m.*,c.created_at AS campaign_created_at,c.started_at AS campaign_started_at,c.name AS campaign_name FROM sms_messages m JOIN sms_campaigns c ON c.id=m.campaign_id AND c.user_id=m.user_id WHERE m.user_id=? AND m.destination=? AND m.content=?';
                $args=[$account['id'],$destination,$content];
                if($account['campaign_id']!==null){$sql.=' AND m.campaign_id=?';$args[]=$account['campaign_id'];}
                $q=$pdo->prepare($sql.' ORDER BY m.id LIMIT 11');$q->execute($args);$found=$q->fetchAll(PDO::FETCH_ASSOC);
                if(count($found)>10)throw new DomainException('Too many possible matches; narrow the campaign scope');
                foreach($found as $message){
                    if(hash('sha256',(string)$message['content'])!==$hash||$message['state']==='skipped'||!in_array($message['provider'],[null,'','logicsms'],true))continue;
                    $created=self::wallTime((string)$message['campaign_created_at']);
                    $anchor=self::wallTime((string)($message['campaign_started_at']?:$message['created_at']));
                    // Report times remain unverified wall times. This bound is a
                    // matching constraint only, not a write to sent_at/delivered_at.
                    if($reported->getTimestamp()<$created->getTimestamp()-300||$reported->getTimestamp()>$anchor->getTimestamp()+86400)continue;
                    $candidates[(int)$message['id']]=$message;
                }
            }
            if(count($candidates)!==1){$rows[]=$display+['match_code'=>$candidates?'AMBIGUOUS':'UNMATCHED'];continue;}
            $message=array_values($candidates)[0];$id=(int)$message['id'];
            $display+=['message_id'=>$id,'campaign_id'=>(int)$message['campaign_id'],'campaign_name'=>$message['campaign_name'],'current_state'=>$message['state']];
            if(isset($seen[$id]))throw new DomainException('Multiple report rows match one message; resolve report ambiguity before applying');
            $seen[$id]=true;
            if($state==='unknown'||$state==='dlr_unavailable'||in_array($rawStatus,['CREATE','CREATED','QUEUED','ERROR','REJECTED'],true)){
                $rows[]=$display+['match_code'=>'UNRESOLVED_STATUS'];continue;
            }
            $credits=(string)($row['provider_credits']??'');
            if(!is_numeric($credits)||!is_finite((float)$credits)||(float)$credits<0)throw new InvalidArgumentException('Invalid provider report credits');
            $evidence=[
                'source'=>'owner_report','user_id'=>(int)$message['user_id'],'campaign_id'=>(int)$message['campaign_id'],
                'status'=>$rawStatus,'acceptance_confirmed_by_report'=>true,'provider_report_credits'=>$credits,
                'report_sha256'=>$report['file_sha256'],'artifact_sha256'=>$report['file_sha256'],
                'report_row'=>['source_row'=>(int)$row['source_row'],'reported_at'=>$display['reported_at'],'provider_credits'=>$credits],
                'provider_reported_at'=>$display['reported_at'],'timestamp_semantics'=>'provider_report_row_wall_time_unverified',
                'match_proof'=>['kind'=>'exact_report_row','destination'=>$destination,'content_sha256'=>$hash,'campaign_created_at'=>$message['campaign_created_at']],
            ];
            $matches[]=['message_id'=>$id,'evidence'=>$evidence];
            $rows[]=$display+['match_code'=>'MATCHED'];
        }
        if(count($rows)>500)throw new DomainException('Limit one report import to 500 relevant rows');
        $planHash=hash('sha256',json_encode(['report_sha256'=>$report['file_sha256'],'scopes'=>$accounts,'matches'=>$matches],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        $counts=array_count_values(array_column($rows,'match_code'));
        return ['ok'=>true,'read_only'=>true,'file_sha256'=>$report['file_sha256'],'plan_sha256'=>$planHash,'source_rows'=>(int)($report['row_count']??count($report['rows'])),'relevant_rows'=>count($rows),'counts'=>$counts,'rows'=>$rows,'matches'=>$matches,'sms_submitted'=>false,'database_changes'=>false];
    }

    /** Requires the existing worker advisory lock; each message is an atomic, resumable settlement. */
    public static function apply(PDO $pdo,array $report,array $scopes,string $expectedPlan):array
    {
        $plan=self::preview($pdo,$report,$scopes);
        if(!$plan['matches'])throw new DomainException('No verified report matches to apply');
        if(!preg_match('/^[a-f0-9]{64}$/D',$expectedPlan)||!hash_equals($plan['plan_sha256'],$expectedPlan))throw new DomainException('Report matching changed; inspect the new preview before applying');
        $results=[];$errors=[];
        foreach($plan['matches'] as $match){
            try{$results[]=SmsStatusReconciliation::apply($pdo,$match['message_id'],$match['evidence']);}
            catch(Throwable $e){$errors[]=['message_id'=>$match['message_id'],'code'=>'RECONCILIATION_GUARD_FAILED','error_fingerprint'=>substr(hash('sha256',get_class($e).':'.$e->getMessage()),0,16)];break;}
        }
        $campaigns=[];
        foreach(array_unique(array_column($plan['rows'],'campaign_id')) as $campaignId){
            $q=$pdo->prepare('SELECT id,name,state,recipient_count,sent_count,delivered_count,failed_count,queued_count,processing_count,skipped_count,actual_cost FROM sms_campaigns WHERE id=?');$q->execute([$campaignId]);$campaigns[]=$q->fetch(PDO::FETCH_ASSOC);
        }
        unset($plan['matches']);
        return ['ok'=>!$errors,'code'=>$errors?'REPORT_PARTIALLY_APPLIED':'REPORT_RECONCILED','results'=>$results,'errors'=>$errors,'campaigns'=>$campaigns,'report'=>$plan,'sms_submitted'=>false,'resend_performed'=>false,'provider_requests'=>0,'unknown_statuses_not_guessed'=>true];
    }

    private static function wallTime(string $text):DateTimeImmutable
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$text,new DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d H:i:s')!==$text)throw new InvalidArgumentException('Invalid report or campaign wall time');
        return $date;
    }
}
