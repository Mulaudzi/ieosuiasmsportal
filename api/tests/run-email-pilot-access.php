<?php
declare(strict_types=1);
// Execute the actual availability/authentication gate with synthetic actors.
// No bootstrap, database, AWS client, mail transport or campaign is initialized.
final class PilotReply extends RuntimeException {public function __construct(public array $value){parent::__construct('captured');}}
class EmailAwsException extends RuntimeException {public function __construct(string $message,public int $status=400){parent::__construct($message);}}
class Response {public static function success(array $value):never{throw new PilotReply($value);}}
function env(string $key,mixed $default=null):mixed{return $GLOBALS['fixture_env'][$key]??$default;}
$source=file_get_contents(__DIR__.'/../email-aws.php');$start=strpos($source,"    if(empty(\$user['email_verified_at']))");$end=strpos($source,'    $data=[];',$start);
if($start===false||$end===false)throw new RuntimeException('Real pilot access gate not found');$gate=substr($source,$start,$end-$start);$checks=0;
function check(bool $value,string $why):void{global $checks;$checks++;if(!$value)throw new RuntimeException($why);}
function runGate(array $actor,string $action,array $config):array{global $gate;$GLOBALS['fixture_env']=$config;$user=$actor;$operation=$action;try{eval($gate);return ['status'=>200,'continues'=>true];}catch(PilotReply $reply){return ['status'=>200,'body'=>$reply->value,'continues'=>false];}catch(EmailAwsException $e){return ['status'=>$e->status,'continues'=>false];}}
$customer=['role'=>'user','email'=>'ordinary@example.invalid','email_verified_at'=>'2026-11-01'];$enabled=['EMAIL_AWS_ENABLED'=>'true','EMAIL_AWS_PILOT_EMAILS'=>'pilot@example.invalid'];
$r=runGate($customer,'limits',$enabled);check($r['status']===200&&!$r['continues'],'Nonpilot can read availability without reaching AWS');check($r['body']['enabled']===false&&!$r['body']['readiness']['ready'],'Nonpilot channel and readiness stay disabled');check(str_contains($r['body']['readiness']['reasons'][0],'invited pilot'),'Availability explains invitation requirement');check(!str_contains(json_encode($r['body']),'pilot@example.invalid'),'No allowlist address is exposed');
foreach(['sender','campaigns','detail','templates','files','reply-to-status','save-sender','quote','create','queue','cancel','save-template','upload-ticket','preview','request-reply-to','verify-reply-to','remove-reply-to','overview','admin-detail','review-sender','review-campaign']as$action)check(runGate($customer,$action,$enabled)['status']===403,'Nonpilot operation denied: '.$action);
$unverified=$customer;$unverified['email_verified_at']=null;check(runGate($unverified,'limits',$enabled)['status']===403,'Unverified account still denied');
$pilot=$customer;$pilot['email']='PILOT@example.invalid';check(runGate($pilot,'limits',$enabled)['continues'],'Allowlisted account retains preparation path');check(runGate($pilot,'queue',$enabled)['continues'],'Pilot reaches later readiness and sending gates');check(runGate($pilot,'overview',$enabled)['status']===403,'Pilot cannot reach admin review');
$admin=$customer;$admin['role']='admin';check(runGate($admin,'overview',$enabled)['continues'],'Admin review access preserved');
$r=runGate($customer,'limits',['EMAIL_AWS_ENABLED'=>'false']);check($r['status']===200&&!$r['body']['enabled'],'Global disabled status preserved');check(runGate($pilot,'queue',['EMAIL_AWS_ENABLED'=>'false'])['status']===503,'Global send-disabled gate preserved');
check(str_contains($source,"env('EMAIL_AWS_SEND_ENABLED','false')!=='true'"),'Independent customer-send gate remains');check(str_contains($source,"if((\$limits['readiness']['ready']??false)!==true)"),'AWS launch readiness gate remains');
echo "PASS: $checks email availability and pilot-access checks; isolated actors, no AWS, mail or database access.\n";
