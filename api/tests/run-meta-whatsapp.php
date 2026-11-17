<?php
declare(strict_types=1);
require_once __DIR__.'/../providers/MetaWhatsAppProvider.php';
require_once __DIR__.'/../domain/MetaWhatsAppWebhook.php';
$checks = 0;
function checkWhatsApp(bool $condition, string $label): void
{
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($label);
}
$config = ['WHATSAPP_SEND_ENABLED' => 'true', 'WHATSAPP_META_GRAPH_VERSION' => 'v99.0',
    'WHATSAPP_META_PHONE_NUMBER_ID' => '12345', 'WHATSAPP_META_WABA_ID' => '67890',
    'WHATSAPP_META_ACCESS_TOKEN' => 'fixture-token-do-not-use', 'WHATSAPP_META_APP_SECRET' => 'fixture-secret',
    'WHATSAPP_META_VERIFY_TOKEN' => 'fixture-verify-token'];
$calls = [];
$transport = static function (string $url, array $headers, string $body) use (&$calls): array {
    $calls[] = [$url, $headers, json_decode($body, true, 32, JSON_THROW_ON_ERROR)];
    return ['status' => 200, 'errno' => 0, 'body' => '{"messaging_product":"whatsapp","messages":[{"id":"wamid.fixture123="}]}'];
};
$provider = new MetaWhatsAppProvider($config, $transport);
$result = $provider->sendTemplate('+27791234567', 'service_reminder', 'en_US', ['Sunday', '09:00']);
checkWhatsApp($result['success'] && $result['status'] === 'accepted', 'Acceptance is distinct from delivery');
checkWhatsApp($result['message_id'] === 'wamid.fixture123=', 'Keep exact Meta message ID');
checkWhatsApp(count($calls) === 1, 'One provider POST per invocation');
checkWhatsApp($calls[0][0] === 'https://graph.facebook.com/v99.0/12345/messages', 'Direct versioned Meta endpoint');
checkWhatsApp(!str_contains($calls[0][0], 'fixture-token'), 'Token excluded from URL');
checkWhatsApp(in_array('Authorization: Bearer fixture-token-do-not-use', $calls[0][1], true), 'Bearer header');
$payload = $calls[0][2];
checkWhatsApp($payload['to'] === '27791234567' && $payload['type'] === 'template', 'International destination and template');
checkWhatsApp($payload['template']['components'][0]['parameters'][1] === ['type' => 'text', 'text' => '09:00'], 'Ordered body parameters');
checkWhatsApp(!str_contains(json_encode($result), 'fixture-token'), 'Safe result excludes secrets');
foreach ([['WHATSAPP_SEND_ENABLED' => 'false'], ['WHATSAPP_SEND_ENABLED' => 'TRUE'],
    ['WHATSAPP_META_ACCESS_TOKEN' => ''], ['WHATSAPP_META_ACCESS_TOKEN' => "fixture\r\nInjected: yes"],
    ['WHATSAPP_META_PHONE_NUMBER_ID' => '../other'], ['WHATSAPP_META_GRAPH_VERSION' => 'https://other.example'],
    ['WHATSAPP_META_WABA_ID' => 'wrong']] as $override) {
    $before = count($calls);
    $result = (new MetaWhatsAppProvider(array_replace($config, $override), $transport))->sendTemplate('+27791234567', 'hello_world', 'en_US');
    checkWhatsApp(!$result['success'] && !$result['uncertain'] && count($calls) === $before, 'Invalid/disabled configuration never sends');
}
foreach ([['0791234567','hello_world','en_US',[]], ['+27791234567','Bad Name','en_US',[]],
    ['+27791234567','hello_world','../en',[]], ['+27791234567','hello_world','en_US',[null]],
    ['+27791234567','hello_world','en_US',['wrong' => 'key']], ['+27791234567','hello_world','en_US',["\xff"]]] as $args) {
    $before = count($calls); $result = $provider->sendTemplate(...$args);
    checkWhatsApp(!$result['success'] && count($calls) === $before, 'Invalid recipient/template inputs never send');
}
foreach ([['',200,0], ['<html>One moment...</html>',403,0], ['null',200,0], ['{"messages":"bad"}',200,0],
    ['{"messages":[{"id":"123"}]}',200,0], ['{"messaging_product":"whatsapp","messages":[{"id":"wamid.good"}]}',200,28],
    ['{"error":{"code":2,"message":"private provider body"}}',500,0],
    ['{"messaging_product":"whatsapp","messages":[{"id":"wamid.a"},{"id":"wamid.b"}]}',200,0]] as $response) {
    $r = MetaWhatsAppProvider::parseResponse(...$response);
    checkWhatsApp(!$r['success'] && $r['uncertain'] && !$r['retryable'], 'Ambiguous responses cannot trigger resend');
}
$r = MetaWhatsAppProvider::parseResponse('{"error":{"code":190,"message":"private secret"}}',401);
checkWhatsApp(!$r['uncertain'] && $r['provider_error_code'] === 190 && !str_contains(json_encode($r), 'private'), 'Explicit rejection exposes only numeric code');
$r = (new MetaWhatsAppProvider($config, static function () { throw new RuntimeException('token=private'); }))->sendTemplate('+27791234567','hello_world','en_US');
checkWhatsApp($r['uncertain'] && !str_contains(json_encode($r), 'private'), 'Transport exceptions do not leak secrets');
$webhook = new MetaWhatsAppWebhook('fixture-secret', 'fixture-verify-token', '67890', '12345');
checkWhatsApp($webhook->challenge(['hub.mode'=>'subscribe','hub.verify_token'=>'fixture-verify-token','hub.challenge'=>'123']) === '123', 'Meta challenge');
checkWhatsApp($webhook->challenge(['hub_mode'=>'subscribe','hub_verify_token'=>'fixture-verify-token','hub_challenge'=>'456']) === '456', 'PHP query key normalization');
checkWhatsApp($webhook->challenge(['hub.mode'=>'subscribe','hub.verify_token'=>'wrong','hub.challenge'=>'123']) === null, 'Reject wrong verify token');
checkWhatsApp($webhook->challenge(['hub_mode'=>['subscribe'],'hub_verify_token'=>[],'hub_challenge'=>[]]) === null, 'Reject query arrays');
checkWhatsApp((new MetaWhatsAppWebhook('','','67890','12345'))->challenge(['hub_mode'=>'subscribe','hub_verify_token'=>'','hub_challenge'=>'123']) === null, 'Empty verify token fails closed');
$statuses = [];
foreach (['sent','delivered','read','failed'] as $state) $statuses[] = ['id'=>'wamid.fixture123=','status'=>$state,'timestamp'=>'1793194200','recipient_id'=>'27791234567'];
$data = ['object'=>'whatsapp_business_account','entry'=>[['id'=>'67890','changes'=>[['field'=>'messages','value'=>[
    'messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>'12345'],'statuses'=>[...$statuses,$statuses[0]],
    'messages'=>[['from'=>'27791234567','text'=>['body'=>'Private inbound text']]]]]]]]];
$encode = static fn(array $d): string => json_encode($d, JSON_THROW_ON_ERROR);
$sign = static fn(string $raw): string => 'sha256='.hash_hmac('sha256',$raw,'fixture-secret');
$raw = $encode($data); $events = $webhook->statusEvents($raw, $sign($raw));
checkWhatsApp(count($events) === 4, 'All lifecycle statuses; duplicates inside one request collapsed');
checkWhatsApp(!str_contains(json_encode($events),'Private inbound') && !str_contains(json_encode($events),'27791234567'), 'Output excludes inbound text and recipient phone');
checkWhatsApp(count(array_unique(array_column($events,'event_key'))) === 4, 'Stable distinct event keys');
checkWhatsApp($webhook->statusEvents($raw,$sign($raw)) === $events, 'Replay produces same deduplication keys');
$other = $data; $other['entry'][0]['id'] = '999';
checkWhatsApp($webhook->statusEvents($encode($other),$sign($encode($other))) === [], 'Reject unrelated WABA');
$other = $data; $other['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = '999';
checkWhatsApp($webhook->statusEvents($encode($other),$sign($encode($other))) === [], 'Reject unrelated sender phone ID');
foreach ([[$raw.' ', $sign($raw)],[$raw,'sha256='.str_repeat('0',64)],[$raw,''],['{bad json',$sign('{bad json')],
    [str_repeat('a',1048577),'sha256='.str_repeat('0',64)]] as [$body,$signature]) {
    $rejected = false;
    try { $webhook->statusEvents($body,$signature); } catch (InvalidArgumentException) { $rejected = true; }
    checkWhatsApp($rejected,'Reject unsigned/tampered/oversized/malformed requests');
}
echo json_encode(['passed'=>true,'checks'=>$checks,'network_calls'=>0,'sms_sent'=>false,'whatsapp_sent'=>false]).PHP_EOL;
