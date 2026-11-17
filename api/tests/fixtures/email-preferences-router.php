<?php
// Local test server only; never reads application .env or accepts non-loopback clients.
if(PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(404);exit;}
function env(string $key,mixed $default=null):mixed{return $key==='EMAIL_CAMPAIGNS_ENABLED'||$key==='EMAIL_CAMPAIGNS_SEND_ENABLED'?'false':$default;}
function db():PDO{
    $path=(string)getenv('IEOSUIA_EMAIL_FIXTURE_DB');
    if(!preg_match('/^ses-callback-fixture-[a-f0-9]{24}\.sqlite$/D',basename($path))||realpath(dirname($path))!==realpath(sys_get_temp_dir()))throw new RuntimeException('Unsafe fixture path');
    return new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
final class Request{public static function query(?string $key=null,mixed $default=null):mixed{return $key===null?$_GET:($_GET[$key]??$default);}}
require_once __DIR__.'/../../controllers/EmailCampaignController.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/fixture-state'){$p=db();header('Content-Type: application/json');echo json_encode(['suppressions'=>(int)$p->query('SELECT COUNT(*) FROM email_campaign_suppressions')->fetchColumn(),'sender'=>$p->query('SELECT state FROM email_sender_profiles')->fetchColumn(),'wallet'=>$p->query('SELECT balance,reserved FROM wallets')->fetch(),'ledger'=>(int)$p->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn()]);exit;}
if(preg_match('~^/email/preferences/([a-f0-9]{32})$~D',$path,$m)&&in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){(new EmailCampaignController())->preferences(['id'=>$m[1]]);exit;}
http_response_code(404);
