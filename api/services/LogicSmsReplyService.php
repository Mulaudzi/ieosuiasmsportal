<?php
require_once __DIR__.'/../domain/PhoneNumber.php';

final class LogicSmsReplyService
{
    public static function isStop(string $text): bool
    {
        return in_array(strtoupper(trim($text)), ['STOP','UNSUBSCRIBE','STOP ALL','STOPALL','CANCEL','END','QUIT'], true);
    }

    public static function parse(string $body): array
    {
        if (stripos($body,'<!DOCTYPE')!==false) throw new RuntimeException('Invalid replies response');
        $xml=@simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml===false || strtolower($xml->getName())!=='replies') throw new RuntimeException('LogicSMS reply query failed or returned an unsupported response');
        $rows=[];
        foreach ($xml->reply as $reply) {
            $row=[];foreach(['id','fromcell','message','ref','date'] as $key)$row[$key]=trim((string)$reply->{$key});
            if($row['id']===''||$row['ref']===''||$row['fromcell']==='')throw new RuntimeException('Reply is missing its identity or message reference');
            $rows[]=$row;
        }
        return $rows;
    }

    public static function poll(PDO $pdo): array
    {
        $username=(string)env('LOGICSMS_USERNAME','');$password=(string)env('LOGICSMS_PASSWORD','');
        if($username===''||$password==='')throw new RuntimeException('LogicSMS credentials are not configured');
        // Re-read a rolling window. Durable provider-event IDs make repeats safe.
        $params=['Username'=>$username,'Pass'=>$password,'Type'=>'DateRange','Start'=>date('Y-m-d',strtotime('-30 days')),'End'=>date('Y-m-d')];
        $ch=curl_init('https://www.logicsms.co.za/queryreplies.aspx');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true]);
        $ca=trim((string)env('LOGICSMS_CA_BUNDLE',''));if($ca!=='')curl_setopt($ch,CURLOPT_CAINFO,$ca);
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if($body===false||$status!==200)throw new RuntimeException('LogicSMS reply query transport failed');
        $counts=['checked'=>0,'opted_out'=>0,'unmatched'=>0,'duplicates'=>0];
        foreach(self::parse($body) as $reply){
            $counts['checked']++;if(!self::isStop($reply['message']))continue;
            try{$phone=PhoneNumber::normalize($reply['fromcell']);}catch(InvalidArgumentException){$counts['unmatched']++;continue;}
            // Never infer tenant ownership from the phone alone.
            if(preg_match('/^portal-message-(\d+)$/',$reply['ref'],$match)){
                $q=$pdo->prepare("SELECT id,user_id,provider_message_id FROM sms_messages WHERE id=? AND provider='logicsms' AND destination=?");$q->execute([(int)$match[1],$phone]);
            }else{
                $q=$pdo->prepare("SELECT id,user_id,provider_message_id FROM sms_messages WHERE provider_message_id=? AND provider='logicsms' AND destination=?");$q->execute([$reply['ref'],$phone]);
            }
            $matches=$q->fetchAll();if(count($matches)!==1){$counts['unmatched']++;continue;}$message=$matches[0];
            $pdo->beginTransaction();try{
                $event=hash('sha256','reply|'.$reply['id']);
                $q=$pdo->prepare("INSERT IGNORE INTO sms_provider_events(provider,provider_event_id,provider_message_id,event_type,payload_json,created_at) VALUES('logicsms',?,?,'reply.opt_out',?,NOW())");
                $q->execute([$event,$message['provider_message_id'],json_encode($reply)]);
                if(!$q->rowCount()){$pdo->rollBack();$counts['duplicates']++;continue;}
                $q=$pdo->prepare("SELECT id FROM opt_outs WHERE user_id=? AND channel='sms' AND recipient=?");$q->execute([$message['user_id'],$phone]);
                if(!$q->fetchColumn()){$q=$pdo->prepare("INSERT INTO opt_outs(user_id,recipient,channel,reason,source,created_at,updated_at) VALUES(?,?,'sms','Recipient replied STOP','logicsms_reply',NOW(),NOW())");$q->execute([$message['user_id'],$phone]);$counts['opted_out']++;}
                $pdo->prepare("UPDATE sms_provider_events SET processed_at=NOW() WHERE provider='logicsms' AND provider_event_id=?")->execute([$event]);$pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }
        return $counts;
    }
}
