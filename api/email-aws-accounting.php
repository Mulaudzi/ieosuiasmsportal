<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json');header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');
require_once __DIR__.'/config/database.php';
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||env('EMAIL_AWS_ACCOUNTING_ENABLED','false')!=='true'){http_response_code(404);echo '{}';exit;}
    require_once __DIR__.'/domain/EmailAwsSignature.php';require_once __DIR__.'/domain/EmailAwsAccounting.php';
    $raw=file_get_contents('php://input',false,null,0,10001);
    if(!is_string($raw)||strlen($raw)>10000||!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))throw new EmailAwsException('Invalid service request',400);
    $keys=['v1'=>(string)env('EMAIL_AWS_ACCOUNTING_KEY','')];
    if(strlen((string)env('EMAIL_AWS_ACCOUNTING_PREVIOUS_KEY',''))>=32)$keys['previous']=(string)env('EMAIL_AWS_ACCOUNTING_PREVIOUS_KEY','');
    $headers=[];foreach(['KEY','TIME','NONCE','SIGNATURE']as$name)$headers['x-email-'.strtolower($name)]=(string)($_SERVER['HTTP_X_EMAIL_'.$name]??'');
    EmailAwsSignature::verify(db(),$keys,'email-accounting','/api/email-aws-accounting.php',$raw,$headers);
    $input=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
    if(!is_array($input)||!is_array($input['data']??null))throw new EmailAwsException('Invalid service request',400);
    if(($input['action']??'')==='attempt'&&env('EMAIL_AWS_SEND_ENABLED','false')!=='true')throw new EmailAwsException('New email attempts are paused',503);
    $result=(new EmailAwsAccounting(db()))->command((string)($input['action']??''),$input['data']);
    echo json_encode(['success'=>true]+$result,JSON_THROW_ON_ERROR);
}catch(Throwable $e){$status=$e instanceof EmailAwsException?$e->status:($e instanceof RuntimeException&&in_array($e->getCode(),[400,401,409],true)?$e->getCode():503);http_response_code($status);echo json_encode(['success'=>false,'message'=>'The scoped accounting request was not confirmed.']);}
