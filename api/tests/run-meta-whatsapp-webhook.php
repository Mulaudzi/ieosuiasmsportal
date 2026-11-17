<?php
declare(strict_types=1);
// Synthetic SQLite fixtures only. No environment, network, production data or sending.
require_once __DIR__.'/../domain/MetaWhatsAppWebhookReceiver.php';
require_once __DIR__.'/../domain/MetaWhatsAppWebhookInbox.php';
$checks = 0;
function callbackCheck(bool $ok, string $label): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($label); }
$config = ['WHATSAPP_WEBHOOK_ENABLED'=>'true','WHATSAPP_META_APP_SECRET'=>'fixture-app-secret-12345',
    'WHATSAPP_META_VERIFY_TOKEN'=>str_repeat('fixture-',5),'WHATSAPP_META_WABA_ID'=>'67890','WHATSAPP_META_PHONE_NUMBER_ID'=>'12345'];
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys=ON');
$migration = require __DIR__.'/../migrations/022_whatsapp_webhook_inbox.php';
$migration($pdo); $migration($pdo);
$calls = 0;
$store = static function (string $hash,array $payload,array $events) use ($pdo,&$calls): array {
    $calls++; return MetaWhatsAppWebhookInbox::store($pdo,$hash,$payload,$events);
};
$sign = static fn(string $raw): string => 'sha256='.hash_hmac('sha256',$raw,$config['WHATSAPP_META_APP_SECRET']);
$query = ['hub_mode'=>'subscribe','hub_verify_token'=>$config['WHATSAPP_META_VERIFY_TOKEN'],'hub_challenge'=>'987654'];
$r = MetaWhatsAppWebhookReceiver::handle('GET',$query,'','',$config,$store);
callbackCheck($r['status']===200&&$r['body']==='987654'&&str_starts_with($r['content_type'],'text/plain'),'Exact unquoted Meta challenge');
callbackCheck($calls===0,'Challenge requires no database writes');
$r = MetaWhatsAppWebhookReceiver::handle('GET',array_replace($query,['hub_verify_token'=>'wrong']),'','',$config,$store);
callbackCheck($r['status']===403,'Wrong verification token rejected');
foreach ([['WHATSAPP_WEBHOOK_ENABLED'=>'false'],['WHATSAPP_META_APP_SECRET'=>''],['WHATSAPP_META_VERIFY_TOKEN'=>'short'],['WHATSAPP_META_PHONE_NUMBER_ID'=>'../wrong']] as $override) {
    $r = MetaWhatsAppWebhookReceiver::handle('GET',$query,'','',array_replace($config,$override),$store);
    callbackCheck($r['status']===503,'Missing/disabled config fails closed');
}
$value = ['messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>'12345'],
    'statuses'=>[['id'=>'wamid.fixture=','status'=>'delivered','timestamp'=>'1793200000']],
    'messages'=>[['id'=>'wamid.inbound=','from'=>'27790000000','text'=>['body'=>'Synthetic inbound message']]]];
$data = ['object'=>'whatsapp_business_account','entry'=>[['id'=>'67890','changes'=>[['field'=>'messages','value'=>$value]]],
    ['id'=>'99999','changes'=>[['field'=>'messages','value'=>['metadata'=>['phone_number_id'=>'77777'],'private'=>'unrelated business']]]]]];
$raw = json_encode($data,JSON_THROW_ON_ERROR);
$r = MetaWhatsAppWebhookReceiver::handle('POST',[],$raw,'',$config,$store);
callbackCheck($r['status']===401&&$calls===0,'Unsigned request cannot reach storage');
$r = MetaWhatsAppWebhookReceiver::handle('POST',[],$raw.' ',$sign($raw),$config,$store);
callbackCheck($r['status']===401&&$calls===0,'Tampered request cannot reach storage');
$r = MetaWhatsAppWebhookReceiver::handle('POST',[],$raw,$sign($raw),$config,$store);
callbackCheck($r['status']===200&&$calls===1,'Signed matched request persists before success');
callbackCheck((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_webhook_receipts')->fetchColumn()===1,'One inbox receipt');
callbackCheck((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_status_events')->fetchColumn()===1,'One immutable status event');
$stored = $pdo->query('SELECT payload_json FROM whatsapp_webhook_receipts')->fetchColumn();
callbackCheck(str_contains($stored,'Synthetic inbound message'),'Matching incoming messages durably retained');
callbackCheck(!str_contains($stored,'unrelated business'),'Unrelated account content not retained');
callbackCheck(!str_contains($r['body'],'Synthetic inbound')&&!str_contains($r['body'],'27790000000'),'Response excludes private payload');
$r = MetaWhatsAppWebhookReceiver::handle('POST',[],$raw,$sign($raw),$config,$store);
callbackCheck(json_decode($r['body'],true)['duplicate']===true,'Exact replay reported as duplicate');
callbackCheck((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_webhook_receipts')->fetchColumn()===1,'Replay does not duplicate receipt');
$again = $data; $again['entry'][0]['changes'][0]['value']['statuses'][]=$value['statuses'][0];
$raw2 = json_encode($again,JSON_THROW_ON_ERROR);
$r = MetaWhatsAppWebhookReceiver::handle('POST',[],$raw2,$sign($raw2),$config,$store);
callbackCheck($r['status']===200,'Different delivery envelope containing repeated status accepted');
callbackCheck((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_webhook_receipts')->fetchColumn()===2,'Distinct signed delivery retained');
callbackCheck((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_status_events')->fetchColumn()===1,'Status deduplicated across envelopes');
foreach (['read','sent'] as $state) {
    $changed=$data;$changed['entry'][0]['changes'][0]['value']['statuses'][0]['status']=$state;
    $body=json_encode($changed,JSON_THROW_ON_ERROR);
    callbackCheck(MetaWhatsAppWebhookReceiver::handle('POST',[],$body,$sign($body),$config,$store)['status']===200,'Out-of-order evidence retained');
}
callbackCheck((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_status_events')->fetchColumn()===3,'No lifecycle overwritten by late event');
$r = MetaWhatsAppWebhookReceiver::handle('POST',[],$raw,$sign($raw),$config,static function(){throw new RuntimeException('secret/private DB failure');});
callbackCheck($r['status']===503&&!str_contains($r['body'],'private'),'Failed storage is not acknowledged and does not leak details');
$r = MetaWhatsAppWebhookReceiver::handle('POST',[],$raw,$sign($raw),$config,static fn()=>['stored'=>false]);
callbackCheck($r['status']===503,'Unconfirmed storage cannot return success');
$empty=json_encode(['object'=>'whatsapp_business_account','entry'=>[$data['entry'][1]]],JSON_THROW_ON_ERROR);$before=$calls;
$r=MetaWhatsAppWebhookReceiver::handle('POST',[],$empty,$sign($empty),$config,$store);
callbackCheck($r['status']===200&&!json_decode($r['body'],true)['matched']&&$calls===$before,'Unrelated sender ignored without storage');
$r=MetaWhatsAppWebhookReceiver::handle('POST',[],str_repeat('x',1048577),'',$config,$store);
callbackCheck($r['status']===413,'Oversized body rejected');
callbackCheck(MetaWhatsAppWebhookReceiver::handle('DELETE',[],'','',$config,$store)['status']===405,'Unsupported method rejected');
$before=(int)$pdo->query('SELECT COUNT(*) FROM whatsapp_webhook_receipts')->fetchColumn();
$webhook=new MetaWhatsAppWebhook($config['WHATSAPP_META_APP_SECRET'],$config['WHATSAPP_META_VERIFY_TOKEN'],'67890','12345');
$payload=$webhook->matchingPayload($raw,$sign($raw));$events=$webhook->statusEvents($raw,$sign($raw));
$events[0]['waba_id']='invalid';$failed=false;
try { MetaWhatsAppWebhookInbox::store($pdo,hash('sha256','new envelope'),$payload,$events); } catch(LogicException) { $failed=true; }
callbackCheck($failed&&(int)$pdo->query('SELECT COUNT(*) FROM whatsapp_webhook_receipts')->fetchColumn()===$before,'Event mismatch rolls back receipt atomically');
callbackCheck(!$pdo->inTransaction(),'No transaction left open');
$emptyDb=new PDO('sqlite::memory:');$emptyDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$r=MetaWhatsAppWebhookReceiver::handle('POST',[],$raw,$sign($raw),$config,static fn($h,$p,$e)=>MetaWhatsAppWebhookInbox::store($emptyDb,$h,$p,$e));
callbackCheck($r['status']===503,'Missing migration fails closed');
echo json_encode(['passed'=>true,'checks'=>$checks,'scope'=>'Synthetic SQLite/HTTP handler only','network_calls'=>0,'messages_sent'=>0]).PHP_EOL;
