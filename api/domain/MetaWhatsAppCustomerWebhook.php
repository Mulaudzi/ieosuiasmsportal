<?php
declare(strict_types=1);
require_once __DIR__.'/MetaWhatsAppWebhook.php';
require_once __DIR__.'/MetaWhatsAppWebhookInbox.php';

/** Route signed customer receipts to durable sender-bound storage, without sending messages. */
final class MetaWhatsAppCustomerWebhook
{
    public static function store(PDO $pdo,string $raw,string $signature,string $appSecret):void {
        if(!preg_match('/^sha256=[a-f0-9]{64}$/D',$signature)||!hash_equals('sha256='.hash_hmac('sha256',$raw,$appSecret),$signature))throw new InvalidArgumentException('Invalid signature');
        if(strlen($raw)>1048576)throw new InvalidArgumentException('Payload too large');
        $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if(($data['object']??'')!=='whatsapp_business_account'||!is_array($data['entry']??null))throw new InvalidArgumentException('Invalid payload');
        $pairs=[];
        foreach($data['entry']as $entry)foreach(($entry['changes']??[])as $change){
            $waba=$entry['id']??null;$phone=$change['value']['metadata']['phone_number_id']??null;
            if(!is_string($waba)||!is_string($phone)||!preg_match('/^[0-9]{5,32}$/D',$waba)||!preg_match('/^[0-9]{5,32}$/D',$phone)||($change['field']??'')!=='messages')continue;
            $pairs[$waba.':'.$phone]=[$waba,$phone];
        }
        foreach($pairs as [$waba,$phone]){
            $q=$pdo->prepare("SELECT owner_id FROM whatsapp_customer_connections WHERE waba_id=? AND phone_number_id=? AND status='authorized'");$q->execute([$waba,$phone]);
            if($q->fetchColumn()===false)continue;
            $parser=new MetaWhatsAppWebhook($appSecret,'',$waba,$phone);
            $payload=$parser->matchingPayload($raw,$signature);
            if($payload['entry'])MetaWhatsAppWebhookInbox::store($pdo,hash('sha256',$raw),$payload,$parser->statusEvents($raw,$signature));
        }
    }
}
