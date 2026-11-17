<?php
// Deploy only with the separately approved email release. No session or browser challenge required.
ini_set('display_errors','0');header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');echo '{"success":false}';exit;}
try{
    require_once __DIR__.'/config/database.php';require_once __DIR__.'/domain/EmailSesFeedback.php';
    $raw=(string)file_get_contents('php://input',false,null,0,262145);
    $result=(new EmailSesFeedback(db()))->receive($raw);echo json_encode(['success'=>true,'data'=>$result]);
}catch(Throwable $e){http_response_code($e instanceof EmailCampaignException?$e->status:503);echo json_encode(['success'=>false,'message'=>'SES feedback was not applied.']);error_log('email_ses_feedback_failure class='.get_class($e));}
