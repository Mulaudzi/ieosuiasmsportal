<?php
declare(strict_types=1);

require_once __DIR__.'/SmsMessageState.php';

/** One-time SELECT-only accounting evidence for the two approved test campaigns. */
final class LogicSmsReportAccountingSnapshot
{
    public const FILE_SHA256='59b298114f2f451925b30219c57cac01b7c26804ea2d2ea5405dc3d463ae3665';
    public const SEED_SHA256='3015b71b54093bc26edf7737ab353325c7d49615fa872d34f4d00df025b181f5';
    public const RECEIPT_NAME='report-accounting-59b298114f2f4519.php';
    private const APPROVAL='logicsms-owner-export-20261024-v1';
    private const PREFIX='<?php http_response_code(404); exit; /* IEOSUIA_REPORT_RECEIPT_BASE64:';

    public static function run(PDO $pdo,string $receiptPath):array
    {
        if($pdo->inTransaction())return self::failure('ACCOUNTING_SNAPSHOT_UNAVAILABLE',new DomainException('Snapshot requires its own read transaction'));
        try{
            self::location($receiptPath);
            if(file_exists($receiptPath))return self::readReceipt($receiptPath)+['from_receipt'=>true];
            self::initialize($receiptPath);
            // No data writes, provider calls, advisory locks or FOR UPDATE.
            // A read transaction keeps all accounting aggregates from one view.
            $pdo->beginTransaction();
            try{$snapshot=self::collect($pdo);}finally{if($pdo->inTransaction())$pdo->rollBack();}
            return self::persist($receiptPath,$snapshot);
        }catch(Throwable $error){return self::failure('ACCOUNTING_SNAPSHOT_UNAVAILABLE',$error);}
    }

    /** Reads an inert envelope; never executes PHP or creates directories. */
    public static function readReceipt(string $path):array
    {
        self::location($path);self::directory($path);
        if(!is_file($path)||is_link($path)||filesize($path)>524288||PHP_OS_FAMILY!=='Windows'&&(fileperms($path)&0777)!==0600)throw new DomainException('Snapshot receipt unavailable');
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||!preg_match('/\A'.preg_quote(self::PREFIX,'/').'([A-Za-z0-9+\/=]+) \*\/\n\z/D',$bytes,$match))throw new DomainException('Snapshot envelope unavailable');
        $json=base64_decode($match[1],true);
        if($json===false||strlen($json)>393216)throw new DomainException('Snapshot envelope too large');
        $value=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($value)||($value['schema_version']??null)!==1||($value['approval_key']??null)!==self::APPROVAL||($value['file_sha256']??null)!==self::FILE_SHA256||($value['seed_sha256']??null)!==self::SEED_SHA256||($value['code']??null)!=='REPORT_ACCOUNTING_SNAPSHOT'||($value['read_only']??null)!==true||($value['database_changes']??null)!==false||($value['sms_submitted']??null)!==false||($value['provider_requests']??null)!==0)throw new DomainException('Snapshot identity mismatch');
        $digest=$value['receipt_payload_sha256']??null;unset($value['receipt_payload_sha256']);
        if(!is_string($digest)||!preg_match('/^[a-f0-9]{64}$/D',$digest)||!hash_equals($digest,self::digest($value)))throw new DomainException('Snapshot fingerprint mismatch');
        return $value+['receipt_payload_sha256'=>$digest];
    }

    private static function collect(PDO $pdo):array
    {
        $q=$pdo->prepare('SELECT c.id,c.user_id,c.state,c.recipient_count,c.sent_count,c.queued_count,c.processing_count,c.skipped_count,c.actual_cost,u.email FROM sms_campaigns c JOIN users u ON u.id=c.user_id WHERE c.id IN (25,26) ORDER BY c.id');$q->execute();$campaigns=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($campaigns)!==2||(int)$campaigns[0]['id']!==25||(int)$campaigns[1]['id']!==26||(int)$campaigns[0]['user_id']<1||(int)$campaigns[0]['user_id']!==(int)$campaigns[1]['user_id'])throw new DomainException('Approved campaign ownership unavailable');
        foreach($campaigns as $campaign)if(strtolower(trim((string)$campaign['email']))!=='vendaboy.lm@gmail.com')throw new DomainException('Approved owner mismatch');
        $owner=(int)$campaigns[0]['user_id'];
        $q=$pdo->prepare('SELECT id,user_id,balance,reserved,currency FROM wallets WHERE user_id=? ORDER BY id');$q->execute([$owner]);$wallets=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($wallets)!==1||(int)$wallets[0]['user_id']!==$owner)throw new DomainException('Approved wallet ownership unavailable');
        $wallet=$wallets[0];$walletId=(int)$wallet['id'];
        if(!preg_match('/^[A-Z]{3}$/D',(string)$wallet['currency']))throw new DomainException('Wallet currency unavailable');
        $q=$pdo->prepare('SELECT COUNT(*) FROM wallet_ledger WHERE (wallet_id=? OR user_id=?) AND (wallet_id<>? OR user_id<>? OR wallet_id IS NULL OR user_id IS NULL)');$q->execute([$walletId,$owner,$walletId,$owner]);
        if((int)$q->fetchColumn()!==0)throw new DomainException('Wallet ledger ownership mismatch');
        $q=$pdo->prepare('SELECT COUNT(*) FROM wallet_ledger l JOIN sms_campaigns c ON c.id=l.campaign_id WHERE l.wallet_id=? AND l.user_id=? AND c.user_id<>?');$q->execute([$walletId,$owner,$owner]);
        if((int)$q->fetchColumn()!==0)throw new DomainException('Ledger campaign ownership mismatch');
        $q=$pdo->prepare('SELECT COUNT(*) FROM sms_messages m LEFT JOIN sms_campaigns c ON c.id=m.campaign_id WHERE (m.user_id=? OR c.user_id=?) AND (m.user_id<>c.user_id OR c.id IS NULL OR m.user_id IS NULL)');$q->execute([$owner,$owner]);
        if((int)$q->fetchColumn()!==0)throw new DomainException('Message campaign ownership mismatch');

        $q=$pdo->prepare('SELECT campaign_id,operation,direction,COUNT(*) AS entries,COALESCE(SUM(amount),0) AS amount FROM wallet_ledger WHERE wallet_id=? AND user_id=? GROUP BY campaign_id,operation,direction ORDER BY campaign_id,operation,direction');$q->execute([$walletId,$owner]);$groups=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($groups)>2048)throw new DomainException('Accounting snapshot exceeds bounded group limit');
        $safeGroups=[];$totals=['entries'=>0,'reservation_entries'=>0,'settlement_entries'=>0,'release_entries'=>0,'other_entries'=>0,'noncanonical_reservation_entries'=>0];
        $reserve=0;$settle=0;$release=0;$all=0;
        foreach($groups as $group){
            $amount=self::units($group['amount']);$entries=(int)$group['entries'];$operation=(string)$group['operation'];$direction=(string)$group['direction'];$campaignId=$group['campaign_id']===null?null:(int)$group['campaign_id'];
            $safe=['campaign_id'=>$campaignId,'operation'=>self::label($operation),'direction'=>self::label($direction),'entries'=>$entries,'amount'=>self::money($amount)];
            if($safe['operation']==='OTHER')$safe['operation_sha256']=hash('sha256',$operation);
            if($safe['direction']==='OTHER')$safe['direction_sha256']=hash('sha256',$direction);
            $safeGroups[]=$safe;$totals['entries']+=$entries;$all+=$amount;
            if(in_array($operation,['reservation','retry_reservation'],true)&&$direction==='reserve'){$reserve+=$amount;$totals['reservation_entries']+=$entries;}
            elseif($operation==='sms_settlement'&&$direction==='debit'){$settle+=$amount;$totals['settlement_entries']+=$entries;}
            elseif($operation==='reservation_release'&&$direction==='release'){$release+=$amount;$totals['release_entries']+=$entries;}
            else{$totals['other_entries']+=$entries;if(in_array($direction,['reserve','release'],true)||($campaignId!==null&&$campaignId>0&&$direction==='debit'))$totals['noncanonical_reservation_entries']+=$entries;}
        }
        $outstanding=$reserve-$settle-$release;$stored=self::units($wallet['reserved']);
        $totals+=['all_entry_amount'=>self::money($all),'reserved_amount'=>self::money($reserve),'settled_amount'=>self::money($settle),'released_amount'=>self::money($release),'canonical_outstanding'=>self::money($outstanding),'stored_reserved'=>self::money($stored),'stored_minus_canonical'=>self::money($stored-$outstanding),'reservation_matches'=>($stored===$outstanding)];
        $global=self::messages($pdo,$owner,null);
        $safeCampaigns=[];
        foreach($campaigns as $campaign){
            $id=(int)$campaign['id'];$snapshot=['id'=>$id,'state'=>self::label((string)$campaign['state'])];
            foreach(['recipient_count','sent_count','queued_count','processing_count','skipped_count'] as $field)$snapshot[$field]=(int)$campaign[$field];
            $snapshot['actual_cost']=self::money(self::units($campaign['actual_cost']));$snapshot['messages']=self::messages($pdo,$owner,$id);
            $r=$s=$l=0;foreach($groups as $group)if((int)($group['campaign_id']??0)===$id){$amount=self::units($group['amount']);if(in_array($group['operation'],['reservation','retry_reservation'],true)&&$group['direction']==='reserve')$r+=$amount;elseif($group['operation']==='sms_settlement'&&$group['direction']==='debit')$s+=$amount;elseif($group['operation']==='reservation_release'&&$group['direction']==='release')$l+=$amount;}
            $snapshot['canonical_reservation']=self::money($r);$snapshot['canonical_settled']=self::money($s);$snapshot['canonical_released']=self::money($l);$snapshot['canonical_outstanding']=self::money($r-$s-$l);$safeCampaigns[]=$snapshot;
        }
        $legacy=self::legacy($pdo,$owner);
        return self::base('REPORT_ACCOUNTING_SNAPSHOT')+['ok'=>true,'terminal'=>true,'user_id'=>$owner,'wallet'=>['id'=>$walletId,'user_id'=>$owner,'balance'=>self::money(self::units($wallet['balance'])),'reserved'=>self::money($stored),'currency'=>$wallet['currency']],'ledger_groups'=>$safeGroups,'ledger_totals'=>$totals,'global_messages'=>$global,'campaigns'=>$safeCampaigns]+$legacy;
    }

    /** Schema-driven allowlists cover legacy SMS/email holds without reading PII. */
    private static function legacy(PDO $pdo,int $owner):array
    {
        $result=['owned_campaigns'=>[],'legacy_campaigns'=>[],'email_campaigns'=>[],'legacy_message_groups'=>[],'wallet_precision'=>[]];
        foreach(['sms_campaigns'=>'owned_campaigns','campaigns'=>'legacy_campaigns','email_campaigns'=>'email_campaigns'] as $table=>$output){
            $columns=self::columns($pdo,$table);if(!$columns)continue;
            if(!isset($columns['id'],$columns['user_id']))throw new DomainException('Campaign aggregate ownership unavailable');
            $fields=array_values(array_intersect(['id','type','state','status','recipient_count','total_recipients','queued_count','processing_count','sent_count','delivered_count','failed_count','skipped_count','estimated_cost','actual_cost'],array_keys($columns)));
            $q=$pdo->prepare('SELECT '.implode(',',$fields).' FROM '.$table.' WHERE user_id=? ORDER BY id');$q->execute([$owner]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);if(count($rows)>2048)throw new DomainException('Campaign aggregate exceeds bounded limit');
            foreach($rows as $row){$safe=[];foreach($row as $key=>$value){if(in_array($key,['type','state','status'],true))$safe[$key]=self::label((string)$value);elseif(in_array($key,['estimated_cost','actual_cost'],true))$safe[$key]=self::money(self::units($value??0));else $safe[$key]=(int)$value;}$result[$output][]=$safe;}
        }
        $messageColumns=self::columns($pdo,'messages');$campaignColumns=self::columns($pdo,'campaigns');
        if(isset($messageColumns['campaign_id'],$messageColumns['status'],$campaignColumns['id'],$campaignColumns['user_id'])){
            $cost=isset($messageColumns['cost'])?'COALESCE(SUM(m.cost),0)':'0';$type=isset($campaignColumns['type'])?'c.type':"'unavailable'";
            if(isset($messageColumns['user_id'])){$q=$pdo->prepare('SELECT COUNT(*) FROM messages m JOIN campaigns c ON c.id=m.campaign_id WHERE (m.user_id=? OR c.user_id=?) AND (m.user_id<>c.user_id OR m.user_id IS NULL)');$q->execute([$owner,$owner]);if((int)$q->fetchColumn()!==0)throw new DomainException('Legacy message ownership mismatch');}
            $q=$pdo->prepare("SELECT c.id AS campaign_id,$type AS campaign_type,m.status,COUNT(*) AS messages,$cost AS cost FROM messages m JOIN campaigns c ON c.id=m.campaign_id WHERE c.user_id=? GROUP BY c.id,$type,m.status ORDER BY c.id,m.status");$q->execute([$owner]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$result['legacy_message_groups'][]=['campaign_id'=>(int)$row['campaign_id'],'campaign_type'=>self::label((string)$row['campaign_type']),'status'=>self::label((string)$row['status']),'messages'=>(int)$row['messages'],'cost'=>self::money(self::units($row['cost']))];
            if(count($result['legacy_message_groups'])>2048)throw new DomainException('Legacy aggregate exceeds bounded limit');
        }
        $columns=self::columns($pdo,'wallets');
        foreach(['balance','reserved'] as $field)if(isset($columns[$field])){$row=$columns[$field];$safe=['column'=>$field];$type=(string)($row['COLUMN_TYPE']??'');if(preg_match('/^[A-Za-z0-9(), ]{1,60}$/D',$type))$safe['column_type']=$type;foreach(['NUMERIC_PRECISION'=>'precision','NUMERIC_SCALE'=>'scale'] as $key=>$target)if(isset($row[$key])&&ctype_digit((string)$row[$key]))$safe[$target]=(int)$row[$key];$result['wallet_precision'][]=$safe;}
        return $result;
    }

    private static function columns(PDO $pdo,string $table):array
    {
        if(!in_array($table,['sms_campaigns','campaigns','email_campaigns','messages','wallets'],true))throw new DomainException('Unapproved schema inspection');
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$q=$pdo->prepare('SELECT name AS COLUMN_NAME,type AS COLUMN_TYPE FROM pragma_table_info(?)');$q->execute([$table]);}
        else{$q=$pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,NUMERIC_PRECISION,NUMERIC_SCALE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');$q->execute([$table]);}
        $result=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$result[$row['COLUMN_NAME']]=$row;return $result;
    }

    private static function messages(PDO $pdo,int $owner,?int $campaign):array
    {
        $accepted=SmsMessageState::acceptedSql('m');$where='m.user_id=?';$values=[$owner];
        if($campaign!==null){$where.=' AND m.campaign_id=?';$values[]=$campaign;}
        $liability="(NOT $accepted AND m.state IN ('pending','queued','processing','retry'))";
        $q=$pdo->prepare("SELECT COUNT(*) AS messages,COALESCE(SUM(m.estimated_charge),0) AS estimated_amount,COALESCE(SUM(m.actual_charge),0) AS actual_amount,COALESCE(SUM(CASE WHEN $accepted THEN 1 ELSE 0 END),0) AS accepted_count,COALESCE(SUM(CASE WHEN $liability THEN 1 ELSE 0 END),0) AS liability_count,COALESCE(SUM(CASE WHEN $liability THEN m.estimated_charge ELSE 0 END),0) AS liability_amount FROM sms_messages m WHERE $where");$q->execute($values);$row=$q->fetch(PDO::FETCH_ASSOC);
        $safe=[];foreach(['messages','accepted_count','liability_count'] as $field)$safe[$field]=(int)$row[$field];foreach(['estimated_amount','actual_amount','liability_amount'] as $field)$safe[$field]=self::money(self::units($row[$field]));
        $q=$pdo->prepare("SELECT m.state,COUNT(*) AS messages FROM sms_messages m WHERE $where GROUP BY m.state ORDER BY m.state");$q->execute($values);$safe['states']=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $state)$safe['states'][]=['state'=>self::label((string)$state['state']),'messages'=>(int)$state['messages']];
        // Count exact canonical keys without exposing any key or free-form metadata.
        $q=$pdo->prepare("SELECT m.id,m.actual_charge,(SELECT COUNT(*) FROM wallet_ledger l JOIN wallets w ON w.id=l.wallet_id WHERE l.user_id=m.user_id AND w.user_id=m.user_id AND l.campaign_id=m.campaign_id AND l.operation='sms_settlement' AND l.direction='debit' AND l.payment_id IS NULL AND l.idempotency_key=CONCAT('sms-message:',m.id,':settle')) AS settlement_count FROM sms_messages m WHERE $where");$q->execute($values);
        $safe['canonical_settlement_entries']=0;$safe['messages_with_settlement']=0;$safe['messages_with_multiple_settlements']=0;$safe['charged_messages_without_settlement']=0;
        while($message=$q->fetch(PDO::FETCH_ASSOC)){$n=(int)$message['settlement_count'];$safe['canonical_settlement_entries']+=$n;if($n>0)$safe['messages_with_settlement']++;if($n>1)$safe['messages_with_multiple_settlements']++;if($n===0&&self::units($message['actual_charge'])>0)$safe['charged_messages_without_settlement']++;}
        return $safe;
    }

    private static function persist(string $path,array $value):array
    {
        self::directory($path);$value['receipt_payload_sha256']=self::digest($value);
        $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($json)>393216)throw new DomainException('Snapshot receipt too large');
        $bytes=self::PREFIX.base64_encode($json)." */\n";$temporary=dirname($path).'/.report-accounting-59b298114f2f4519-'.bin2hex(random_bytes(12)).'.php';
        $mask=umask(0077);try{$stream=fopen($temporary,'x+b');}finally{umask($mask);}if($stream===false)throw new RuntimeException('Private snapshot staging unavailable');
        try{$offset=0;while($offset<strlen($bytes)){$n=fwrite($stream,substr($bytes,$offset));if($n===false||$n===0)throw new RuntimeException('Snapshot write interrupted');$offset+=$n;}if(!fflush($stream)||function_exists('fsync')&&!fsync($stream))throw new RuntimeException('Snapshot flush unavailable');}finally{fclose($stream);}
        if(!chmod($temporary,0600))throw new RuntimeException('Snapshot permissions unavailable');
        // A hard link publishes atomically without ever replacing an existing receipt.
        // Keep the inert staging link as evidence; do not unlink owner artifacts.
        if(!link($temporary,$path)){if(file_exists($path))return self::readReceipt($path)+['from_receipt'=>true];throw new RuntimeException('No-overwrite snapshot publication unavailable');}
        return self::readReceipt($path);
    }

    private static function location(string $path):void
    {
        if(str_contains($path,"\0")||strlen($path)>1024||!(str_starts_with($path,'/')||preg_match('/^[A-Za-z]:[\\\\\/]/',$path)))throw new DomainException('Snapshot path must be absolute');
        $normalize=static function(string $value):string{$parts=[];foreach(explode('/',str_replace('\\','/',$value)) as $part){if($part===''||$part==='.')continue;if($part==='..'){array_pop($parts);continue;}$parts[]=$part;}$value=implode('/',$parts);return PHP_OS_FAMILY==='Windows'?strtolower($value):$value;};
        if($normalize($path)!==$normalize(dirname(__DIR__).'/storage/reconciliation/'.self::RECEIPT_NAME))throw new DomainException('Different accounting snapshot location is not approved');
    }
    private static function initialize(string $path):void
    {
        self::location($path);foreach([dirname(__DIR__).'/storage',dirname(__DIR__).'/storage/reconciliation'] as $dir){if(is_link($dir)||file_exists($dir)&&!is_dir($dir))throw new DomainException('Private snapshot directory unavailable');if(!is_dir($dir)&&!mkdir($dir,0700))throw new RuntimeException('Private snapshot directory cannot be initialized');}self::directory($path);
    }
    private static function directory(string $path):void
    {
        $dir=dirname(__DIR__).'/storage/reconciliation';if(is_link(dirname(__DIR__).'/storage')||is_link($dir)||!is_dir($dir)||realpath(dirname($path))!==realpath($dir)||is_link($path)||PHP_OS_FAMILY!=='Windows'&&(fileperms($dir)&0777)!==0700)throw new DomainException('Snapshot location is not private');
    }
    private static function base(string $code):array
    {return ['schema_version'=>1,'approval_key'=>self::APPROVAL,'file_sha256'=>self::FILE_SHA256,'seed_sha256'=>self::SEED_SHA256,'code'=>$code,'observed_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'read_only'=>true,'database_changes'=>false,'sms_submitted'=>false,'provider_requests'=>0];}
    private static function failure(string $code,Throwable $error):array{return self::base($code)+['ok'=>false,'error_fingerprint'=>substr(hash('sha256',get_class($error).':'.$error->getMessage()),0,16)];}
    private static function label(string $value):string{return preg_match('/^[A-Za-z_]{1,48}$/D',$value)?$value:'OTHER';}
    private static function units(mixed $value):int{if(!is_numeric($value)||!is_finite((float)$value)||abs((float)$value)>1000000000)throw new DomainException('Accounting amount unavailable');return (int)round((float)$value*10000);}
    private static function money(int $units):string{return number_format($units/10000,4,'.','');}
    private static function canonical(mixed $value):mixed{if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as $key=>$item)$value[$key]=self::canonical($item);return $value;}
    private static function digest(array $value):string{return hash('sha256',json_encode(self::canonical($value),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
}
