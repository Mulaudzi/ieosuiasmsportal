<?php
declare(strict_types=1);
// Offline SQLite integration tests. Synthetic Graph transport only; no live sends or account changes.
require_once __DIR__.'/../domain/MetaWhatsAppWorkspace.php';
require_once __DIR__.'/../domain/MetaWhatsAppCustomerWebhook.php';
$checks=0;
function check(bool $value,string $name):void{global $checks;$checks++;if(!$value)throw new RuntimeException('FAIL: '.$name);}
function denied(callable $call,int $status,string $name):void{try{$call();check(false,$name);}catch(MetaWhatsAppConnectException $e){check($e->status===$status,$name);}}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);INSERT INTO users VALUES(1,1),(2,1)');
foreach(['026_whatsapp_customer_connections.php','022_whatsapp_webhook_inbox.php','028_whatsapp_workspace.php']as $file){$migration=require __DIR__.'/../migrations/'.$file;$migration($pdo);$migration($pdo);}
$config=new MetaWhatsAppConnectConfig('123456789',str_repeat('b',32),'v25.0','987654321','https://portal.example/api/whatsapp-meta-oauth.php',base64_encode(str_repeat('k',32)),'',true,'reviewer@example.invalid');
$config->assertPilotAccess(false,'reviewer@example.invalid');check(true,'dedicated customer pilot allowed');
denied(fn()=>$config->assertPilotAccess(false,'other@example.invalid'),503,'unlisted customer cannot join pilot');
$now=1800000000;$token='OFFLINE_TOKEN_'.str_repeat('x',64);$cipher=$config->seal(['token'=>$token],'connection:1:111111111:222222222');
$metadata=json_encode(['display_phone_number'=>'+27 63 808 2493','verified_name'=>'Fixture','business_name'=>'Fixture']);
$q=$pdo->prepare("INSERT INTO whatsapp_customer_connections VALUES('222222222',1,'111111111','authorized',?,?,?,1,1)");$q->execute([$cipher,$now+1000,$metadata]);
$stub=(object)['posts'=>0,'gets'=>0,'mode'=>'accepted','templates'=>[
 ['id'=>'333333333','name'=>'fixture_update','status'=>'APPROVED','language'=>'en_ZA','category'=>'UTILITY','components'=>[['type'=>'BODY','text'=>'Your requested update: {{1}}.']]],
 ['id'=>'333333334','name'=>'pending','status'=>'PENDING','language'=>'en_ZA','components'=>[['type'=>'BODY','text'=>'Pending']]],
 ['id'=>'333333335','name'=>'media','status'=>'APPROVED','language'=>'en_ZA','components'=>[['type'=>'HEADER','format'=>'IMAGE'],['type'=>'BODY','text'=>'Media']]],
]];
$client=new MetaWhatsAppWorkspaceClient($config,static function($method,$url,$bearer,$body)use($stub,$token){
 check($bearer===$token,'owner token used only inside transport');check(str_starts_with($url,'https://graph.facebook.com/v25.0/'),'pinned HTTPS Graph host/version');
 if($method==='GET'){$stub->gets++;return ['status'=>200,'body'=>json_encode(['data'=>$stub->templates])];}
 $stub->posts++;
 if($stub->mode==='uncertain')throw new RuntimeException('DO NOT EXPOSE '.$token);
 if($stub->mode==='rejected')return ['status'=>400,'body'=>json_encode(['error'=>['code'=>100,'message'=>'DO NOT EXPOSE '.$token]])];
 if(str_contains($url,'/messages?'))return ['status'=>200,'body'=>json_encode(['messaging_product'=>'whatsapp','messages'=>[['id'=>'wamid.FIXTURE1']]])];
 if(str_contains($url,'/message_templates?'))return ['status'=>200,'body'=>json_encode(['id'=>'444444444','status'=>'PENDING'])];
 return ['status'=>200,'body'=>'{"success":true}'];
});
$service=new MetaWhatsAppWorkspace($pdo,$config,$client,true,static fn()=>$now);$service->assertReady();
denied(fn()=>$service->templates(2,'222222222'),404,'cross-owner templates rejected');check($stub->gets===0,'cross-owner rejection before network');
$list=$service->templates(1,'222222222')['templates'];check(count($list)===3&&$list[0]['parameter_count']===1,'template list reads body parameters');check(!$list[2]['supported'],'media template not falsely supported');
$body=['phone_number_id'=>'222222222','operation_key'=>'fixture-operation-send-0001','recipient'=>'+27799282775','template'=>'fixture_update','language'=>'en_ZA','parameters'=>['ready'],'consent'=>true,'consent_note'=>'OFFLINE fixture consent only'];
$bad=$body;$bad['consent']=false;denied(fn()=>$service->send(1,$bad),422,'consent required');
$bad=$body;$bad['recipient']='+44799282775';denied(fn()=>$service->send(1,$bad),422,'recipient must be South African');
$bad=$body;$bad['parameters']=[];denied(fn()=>$service->send(1,$bad),422,'parameter count enforced');
$bad=$body;$bad['template']='pending';denied(fn()=>$service->send(1,$bad),422,'pending template cannot send');
$bad=$body;$bad['template']='media';denied(fn()=>$service->send(1,$bad),422,'unsupported template cannot send');
$disabled=new MetaWhatsAppWorkspace($pdo,$config,$client,false,static fn()=>$now);denied(fn()=>$disabled->send(1,$body),503,'send activation gate enforced');check($stub->posts===0,'invalid requests cause no POST');
$result=$service->send(1,$body);check($result['outcome']==='accepted'&&$stub->posts===1,'explicit approved message submitted once');
$result=$service->send(1,$body);check($result['replayed']&&$stub->posts===1,'same operation key does not resend');
$bad=$body;$bad['recipient']='+27638082493';denied(fn()=>$service->send(1,$bad),409,'changed request cannot reuse operation key');
$history=$service->status(1);check($history['operations'][0]['state']==='accepted','acceptance not presented as delivery');check(!$service->status(2)['operations'],'history isolated by owner');check(!str_contains(json_encode($history),$token),'history never returns credentials');
$raw=json_encode(['object'=>'whatsapp_business_account','entry'=>[['id'=>'111111111','changes'=>[['field'=>'messages','value'=>['messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>'222222222'],'statuses'=>[['id'=>'wamid.FIXTURE1','status'=>'read','timestamp'=>(string)$now]]]]]]]]);
$signature='sha256='.hash_hmac('sha256',$raw,$config->appSecret);
MetaWhatsAppCustomerWebhook::store($pdo,$raw,$signature,$config->appSecret);MetaWhatsAppCustomerWebhook::store($pdo,$raw,$signature,$config->appSecret);
check($service->status(1)['operations'][0]['state']==='read','signed customer event provides read status');check((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_status_events')->fetchColumn()===1,'webhook retries deduplicated');
try{MetaWhatsAppCustomerWebhook::store($pdo,$raw,'sha256='.str_repeat('0',64),$config->appSecret);check(false,'bad signature denied');}catch(InvalidArgumentException){check(true,'bad signature denied');}
$stub->mode='uncertain';$body['operation_key']='fixture-operation-send-0002';$result=$service->send(1,$body);check($result['outcome']==='uncertain'&&$stub->posts===2,'timeout recorded as uncertain');$service->send(1,$body);check($stub->posts===2,'uncertain outcome never automatically resent');
$stub->mode='rejected';$body['operation_key']='fixture-operation-send-0003';check($service->send(1,$body)['outcome']==='rejected','explicit rejection recorded');
$stub->mode='accepted';$create=['phone_number_id'=>'222222222','operation_key'=>'fixture-operation-template-0001','name'=>'requested_update','language'=>'en_ZA','category'=>'UTILITY','text'=>'Your requested update is ready.'];
check($service->createTemplate(1,$create)['outcome']==='accepted','template creation submits actual Graph payload');$postCount=$stub->posts;$service->createTemplate(1,$create);check($stub->posts===$postCount,'template mutation idempotent');
$bad=$create;$bad['text']='{{1}}';denied(fn()=>$service->createTemplate(1,$bad),422,'variable template creation not supported');
$pdo->exec("UPDATE whatsapp_customer_connections SET status='disconnected'");denied(fn()=>$service->send(1,$body),404,'disconnect blocks later sends');
check(!str_contains(json_encode($service->status(1)),$token),'provider failures do not leak token');
echo 'PASS: '.$checks.' WhatsApp workspace checks; offline SQLite and synthetic Graph only.'.PHP_EOL;
