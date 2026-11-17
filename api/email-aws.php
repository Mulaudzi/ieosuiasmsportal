<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json');header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');
require_once __DIR__.'/config/database.php';require_once __DIR__.'/core/QueryBuilder.php';require_once __DIR__.'/core/Request.php';require_once __DIR__.'/core/Response.php';require_once __DIR__.'/core/JWT.php';require_once __DIR__.'/core/Auth.php';require_once __DIR__.'/core/RateLimiter.php';
require_once __DIR__.'/domain/EmailAwsAccounting.php';require_once __DIR__.'/services/EmailAwsClient.php';
try {
    Auth::check();$user=Auth::user();$operation=$_GET['action']??'limits';
    $reads=['limits','sender','campaigns','detail','admin-detail','overview','templates','files','reply-to-status'];$writes=['save-sender','quote','create','queue','cancel','review-sender','review-campaign','save-template','upload-ticket','preview','request-reply-to','verify-reply-to','remove-reply-to'];
    if(!is_string($operation)||!in_array($operation,[...$reads,...$writes],true))throw new EmailAwsException('Unknown email operation',404);
    $write=in_array($operation,$writes,true);
    if(($_SERVER['REQUEST_METHOD']??'')!==($write?'POST':'GET'))throw new EmailAwsException('Method not allowed',405);
    if(empty($user['email_verified_at']))throw new EmailAwsException('Verify your account email first',403);
    $enabled=env('EMAIL_AWS_ENABLED','false')==='true';
    if($operation==='limits'&&!$enabled)Response::success(['enabled'=>false,'emails_per_credit'=>5,'max_recipients'=>1000,'target_daily_limit'=>10000,'billing_basis'=>'SES acceptance','readiness'=>['ready'=>false,'reasons'=>['AWS email campaigns are awaiting launch acceptance.']]]);
    if(!$enabled)throw new EmailAwsException('AWS email campaigns are awaiting launch acceptance',503);
    $pilot=array_filter(array_map('strtolower',array_map('trim',explode(',',(string)env('EMAIL_AWS_PILOT_EMAILS','')))));
    if(($user['role']??'')!=='admin'&&!in_array(strtolower($user['email']),$pilot,true)){
        // Verified customers may read their own channel availability. Every
        // campaign, sender, file and write operation still requires pilot access.
        if($operation==='limits')Response::success(['enabled'=>false,'emails_per_credit'=>5,'max_recipients'=>1000,'target_daily_limit'=>10000,'billing_basis'=>'SES acceptance','readiness'=>['ready'=>false,'reasons'=>['Email campaigns are currently available to invited pilot accounts.']]]);
        throw new EmailAwsException('Email pilot access has not been enabled for this account',403);
    }
    if(in_array($operation,['admin-detail','overview','review-sender','review-campaign'],true)&&($user['role']??'')!=='admin')throw new EmailAwsException('Administrator access required',403);
    $data=[];
    if($write){
        $origin=rtrim((string)env('APP_URL','https://sms.ieosuia.com'),'/');
        if(($_SERVER['HTTP_ORIGIN']??'')!==$origin||!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))throw new EmailAwsException('Start this action from the portal',403);
        RateLimiter::checkOrFail('email_aws:'.Auth::id(),20,1);
        $raw=file_get_contents('php://input',false,null,0,400001);if(!is_string($raw)||strlen($raw)>400000)throw new EmailAwsException('Campaign is too large',413);
        $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($data)||array_is_list($data)&&$data!==[])throw new EmailAwsException('Invalid email request');
    }
    foreach(['actor','reservation','user_id','unit_cents','available_cents']as$field)if(array_key_exists($field,$data)&&!($field==='user_id'&&$operation==='review-sender'))throw new EmailAwsException('Caller-selected authorization or financial values are not allowed');
    if(isset($_GET['id']))$data['id']=(string)$_GET['id'];
    if(in_array($operation,['quote','create'],true)){
        $source=$data['recipients']??[];if(!is_array($source))throw new EmailAwsException('Choose a bounded audience');
        $addresses=$source['manual']??[];if(!is_array($addresses)||count($addresses)>10000)throw new EmailAwsException('Too many recipient addresses');
        foreach(['contact_ids','group_ids']as$field){
            $ids=$source[$field]??[];if(!is_array($ids)||count($ids)>1000)throw new EmailAwsException('Too many contact selections');
            foreach($ids as$id)if(!preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id))throw new EmailAwsException('Invalid contact selection');
            $ids=array_values(array_unique($ids));if(!$ids)continue;$marks=implode(',',array_fill(0,count($ids),'?'));$table=$field==='group_ids'?'contact_groups':'contacts';
            $q=db()->prepare("SELECT id FROM {$table} WHERE user_id=? AND id IN ({$marks})");$q->execute([Auth::id(),...$ids]);if(count($q->fetchAll(PDO::FETCH_COLUMN))!==count($ids))throw new EmailAwsException('Contact selection not found',404);
            $sql=$field==='group_ids'?"SELECT c.email FROM contacts c JOIN group_contacts gc ON gc.contact_id=c.id WHERE c.user_id=? AND gc.group_id IN ({$marks}) LIMIT 10001":"SELECT email FROM contacts WHERE user_id=? AND id IN ({$marks}) LIMIT 10001";
            $q=db()->prepare($sql);$q->execute([Auth::id(),...$ids]);array_push($addresses,...$q->fetchAll(PDO::FETCH_COLUMN));
        }
        if(count($addresses)>10000)throw new EmailAwsException('Split this audience into smaller campaigns');
        $q=db()->prepare("SELECT recipient FROM opt_outs WHERE user_id=? AND channel='email'");$q->execute([Auth::id()]);$excluded=array_fill_keys(array_map('strtolower',$q->fetchAll(PDO::FETCH_COLUMN)),true);
        $addresses=array_values(array_filter($addresses,static fn($v)=>is_string($v)&&!isset($excluded[strtolower(trim($v))])));
        $data['recipients']=['manual'=>$addresses,'group_ids'=>[],'contact_ids'=>[]];
    }
    $accounting=new EmailAwsAccounting(db());
    // Reading or independently reviewing campaigns does not require an administrator's customer wallet.
    // Only an audience/cost preview needs available funds; queueing locks the payer separately below.
    $pricing=in_array($operation,['quote','create'],true)?$accounting->price((int)Auth::id()):['unit_cents'=>EmailAwsPolicy::unitCents(),'available_cents'=>0];
    $actor=['id'=>(int)Auth::id(),'email'=>$user['email'],'role'=>$user['role'],'verified'=>true]+$pricing;
    $client=new EmailAwsClient();
    if($operation==='queue'){
        if(env('EMAIL_AWS_SEND_ENABLED','false')!=='true')throw new EmailAwsException('Live email sending is disabled',503);
        $limits=$client->call($actor,'limits');if(($limits['readiness']['ready']??false)!==true)throw new EmailAwsException('AWS launch acceptance is not complete',503);
        $current=$client->call($actor,'detail',['id'=>$data['id']??''])['campaign'];
        if($current['state']==='approved')$data['reservation']=$accounting->reserve((int)Auth::id(),$current);
        else $data['reservation']=$accounting->proof((int)Auth::id(),$current['id']);
    }
    if($operation==='request-reply-to'){
        require_once __DIR__.'/domain/EmailReplyToBridge.php';require_once __DIR__.'/services/EmailService.php';
        RateLimiter::checkOrFail('email_reply_to:'.Auth::id(),3,15);
        RateLimiter::checkOrFail('email_reply_to_ip:'.($_SERVER['REMOTE_ADDR']??'unknown'),10,60);
        $reply=EmailReplyToBridge::request($data,static fn($op,$payload)=>$client->call($actor,$op,$payload),static fn($email,$code)=>EmailService::sendCampaignReplyToCode($email,$code));
    }else $reply=$client->call($actor,$operation,$data);
    if($operation==='cancel'&&($reply['campaign']['cancel_requested']??false)===true){try{$accounting->cancel((int)Auth::id(),$reply['campaign']['id']);}catch(EmailAwsException $e){if($e->status!==404)throw$e;}}
    Response::success($reply);
}catch(EmailAwsException $e){Response::error($e->getMessage(),$e->status);}
catch(Throwable){Response::error('Email operation was not confirmed. Refresh before retrying.',503);}
