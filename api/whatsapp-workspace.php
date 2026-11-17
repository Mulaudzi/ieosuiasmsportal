<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/core/QueryBuilder.php';
require_once __DIR__.'/core/Request.php';
require_once __DIR__.'/core/Response.php';
require_once __DIR__.'/core/JWT.php';
require_once __DIR__.'/core/Auth.php';
require_once __DIR__.'/domain/MetaWhatsAppWorkspace.php';
try{
    $method=(string)($_SERVER['REQUEST_METHOD']??'');$action=$_GET['action']??'status';
    if(!is_string($action)||!in_array($action,['status','templates','create-template','send','subscribe'],true))Response::error('Unknown WhatsApp operation.',404);
    $read=in_array($action,['status','templates'],true);
    if($method!==($read?'GET':'POST')){header('Allow: '.($read?'GET':'POST'));Response::error('Method not allowed.',405);}
    Auth::check();
    try{
        $config=MetaWhatsAppConnectConfig::fromEnvironment();
        $config->assertPilotAccess(Auth::isAdmin(),(string)(Auth::user()['email']??''));
        if(env('WHATSAPP_WORKSPACE_ENABLED','false')!=='true')throw new MetaWhatsAppConnectException('The WhatsApp workspace is awaiting setup.',503);
        $service=new MetaWhatsAppWorkspace(db(),$config,new MetaWhatsAppWorkspaceClient($config),env('WHATSAPP_WORKSPACE_SEND_ENABLED','false')==='true');
        $service->assertReady();
    }catch(Throwable $e){
        if($action==='status')Response::success(['available'=>false,'sending_enabled'=>false,'operations'=>[]]);
        throw $e;
    }
    $owner=(int)Auth::id();
    if($action==='status')Response::success($service->status($owner));
    if($action==='templates'){
        $phone=$_GET['phone_number_id']??null;
        if(!is_string($phone))Response::error('Choose a connected business number.',422);
        Response::success($service->templates($owner,$phone));
    }
    if(($_SERVER['HTTP_ORIGIN']??'')!==$config->origin||!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))Response::error('Start this action from the portal.',403);
    $raw=file_get_contents('php://input',false,null,0,16385);
    if(!is_string($raw)||strlen($raw)>16384)Response::error('The request is too large.',413);
    try{$body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){Response::error('Invalid WhatsApp request.',422);}
    if(!is_array($body)||array_is_list($body))Response::error('Invalid WhatsApp request.',422);
    foreach(['phone_number_id','operation_key']as $field)if(!is_string($body[$field]??null))Response::error('Complete all required fields.',422);
    $result=match($action){'create-template'=>$service->createTemplate($owner,$body),'send'=>$service->send($owner,$body),'subscribe'=>$service->subscribe($owner,$body)};
    Response::success($result);
}catch(MetaWhatsAppConnectException $e){Response::error($e->getMessage(),$e->status);}
catch(Throwable){Response::error('The WhatsApp workspace is temporarily unavailable. Check recent activity before repeating a submission.',503);}
