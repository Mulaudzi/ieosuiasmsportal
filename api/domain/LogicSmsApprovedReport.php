<?php
declare(strict_types=1);

/** One explicitly approved export, applied by the existing cron without resends. */
final class LogicSmsApprovedReport
{
    public const FILE_SHA256='59b298114f2f451925b30219c57cac01b7c26804ea2d2ea5405dc3d463ae3665';
    public const SEED_SHA256='3015b71b54093bc26edf7737ab353325c7d49615fa872d34f4d00df025b181f5';
    public const RECEIPT_NAME='report-59b298114f2f4519.php';
    public const RESUME_RECEIPT_NAME='report-resume-59b298114f2f4519.php';
    private const RESUME_BASELINE_SHA256='1ff5fa1435f559e0a22c40641346d2743c8640e6ae15fb2292f83b61c245967e';
    private const APPROVAL='logicsms-owner-export-20261024-v1';
    private const PREFIX='<?php http_response_code(404); exit; /* IEOSUIA_REPORT_RECEIPT_BASE64:';
    private const WORKER_LOCK='ieosuia-sms-dispatch-worker';

    public static function run(PDO $pdo,array $seed,string $receiptPath):array
    {
        if($pdo->inTransaction())throw new DomainException('Approved report requires its own transactions');
        try{self::initialize($receiptPath);self::path($receiptPath);$receipt=self::readReceipt($receiptPath);}
        catch(Throwable $error){return self::base('RECEIPT_INVALID',true)+['error_fingerprint'=>self::fingerprint($error)];}
        try{self::validateSeed($seed);}
        catch(Throwable $error){$rejected=self::base('APPROVAL_SEED_REJECTED',true)+['error_fingerprint'=>self::fingerprint($error)];return $receipt===null?self::persist($receiptPath,$rejected):$rejected;}
        // An explicitly deployed resume has its own immutable receipt. It may
        // continue only the exact observed partial application, never erase or
        // silently retry a different failure or a successful original import.
        if(basename($receiptPath)===self::RESUME_RECEIPT_NAME){
            try{self::assertResumeBaseline();}
            catch(Throwable $error){return self::base('RESUME_BASELINE_REJECTED',true)+['error_fingerprint'=>self::fingerprint($error)];}
        }
        if($receipt!==null&&!empty($receipt['terminal']))return $receipt+['from_receipt'=>true];
        if(!class_exists('LogicSmsReportImport',false))require_once __DIR__.'/LogicSmsReportImport.php';
        try{
            $preview=LogicSmsReportImport::preview($pdo,$seed['report'],$seed['scopes']);
            $safePreview=self::safePreview($preview);
            if(!self::approvedPreview($preview))return self::persist($receiptPath,self::base('APPROVAL_PREVIEW_FAILED',true)+$safePreview);
        }catch(Throwable $error){return self::persist($receiptPath,self::base('APPROVAL_PREVIEW_UNAVAILABLE',true)+['error_fingerprint'=>self::fingerprint($error)]);}
        try{$lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([self::WORKER_LOCK]);}
        catch(Throwable $error){return self::persist($receiptPath,self::base('APPROVAL_APPLY_UNAVAILABLE',true)+$safePreview+['database_changes_may_have_committed'=>false,'error_fingerprint'=>self::fingerprint($error)]);}
        if((int)$lock->fetchColumn()!==1)return self::persist($receiptPath,self::base('WORKER_BUSY',false)+$safePreview);
        $changesPossible=false;
        try{
            $changesPossible=true;
            $result=LogicSmsReportImport::apply($pdo,$seed['report'],$seed['scopes'],$preview['plan_sha256']);
            $receipt=self::safeResult($result,$safePreview);
        }catch(Throwable $error){$receipt=self::base('APPROVAL_APPLY_UNAVAILABLE',true)+$safePreview+['database_changes_may_have_committed'=>$changesPossible,'error_fingerprint'=>self::fingerprint($error)];}
        finally{
            try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([self::WORKER_LOCK]);}
            catch(Throwable $error){$receipt=self::base('APPROVAL_APPLY_UNAVAILABLE',true)+$safePreview+['database_changes_may_have_committed'=>$changesPossible,'error_fingerprint'=>self::fingerprint($error)];}
        }
        // Each core message transaction already committed or rolled back. Never
        // claim a receipt filesystem failure rolled back an earlier settlement.
        if($pdo->inTransaction())return self::base('RECEIPT_WRITE_UNCERTAIN',false)+['operation_code'=>$receipt['code'],'database_changes_may_have_committed'=>$changesPossible];
        return self::persist($receiptPath,$receipt);
    }

    /** Read-only and bounded. Never executes downloaded/private PHP contents. */
    public static function readReceipt(string $receiptPath):?array
    {
        self::assertLocation($receiptPath);
        if(!file_exists(dirname(__DIR__).'/storage/reconciliation'))return null;
        self::path($receiptPath);
        if(!file_exists($receiptPath))return null;
        if(!is_file($receiptPath)||is_link($receiptPath)||filesize($receiptPath)>65536||PHP_OS_FAMILY!=='Windows'&&(fileperms($receiptPath)&0777)!==0600)throw new DomainException('Invalid private receipt file');
        $bytes=file_get_contents($receiptPath);
        if(!is_string($bytes)||!preg_match('/\A'.preg_quote(self::PREFIX,'/').'([A-Za-z0-9+\/=]+) \*\/\n\z/D',$bytes,$match))throw new DomainException('Receipt envelope unavailable');
        $json=base64_decode($match[1],true);
        if($json===false||strlen($json)>49152)throw new DomainException('Receipt is too large');
        $receipt=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($receipt)||($receipt['schema_version']??null)!==1||($receipt['approval_key']??null)!==self::APPROVAL||($receipt['file_sha256']??null)!==self::FILE_SHA256||($receipt['seed_sha256']??null)!==self::SEED_SHA256||!is_bool($receipt['terminal']??null)||($receipt['sms_submitted']??null)!==false||($receipt['provider_requests']??null)!==0)throw new DomainException('Receipt approval identity mismatch');
        $digest=$receipt['receipt_payload_sha256']??null;unset($receipt['receipt_payload_sha256']);
        if(!is_string($digest)||!preg_match('/^[a-f0-9]{64}$/D',$digest)||!hash_equals($digest,self::digest($receipt)))throw new DomainException('Receipt payload fingerprint mismatch');
        $allowed=['REPORT_RECONCILED','REPORT_PARTIALLY_APPLIED','WORKER_BUSY','APPROVAL_SEED_REJECTED','APPROVAL_PREVIEW_FAILED','APPROVAL_PREVIEW_UNAVAILABLE','APPROVAL_APPLY_UNAVAILABLE'];
        if(!in_array($receipt['code']??null,$allowed,true)||$receipt['terminal']!==($receipt['code']!=='WORKER_BUSY'))throw new DomainException('Invalid receipt terminal disposition');
        $receipt['receipt_payload_sha256']=$digest;
        return $receipt;
    }

    private static function validateSeed(array $seed):void
    {
        if(array_diff(array_keys($seed),['report','scopes','report_name'])||count($seed)!==3||($seed['report_name']??null)!=='SMSSendReport_20261024.xlsx')throw new DomainException('Different report envelope');
        $report=$seed['report']??null;$scopes=$seed['scopes']??null;
        if(!is_array($report)||($report['file_sha256']??null)!==self::FILE_SHA256||($report['row_count']??null)!==1390||!is_array($report['rows']??null)||count($report['rows'])!==14||!is_array($scopes)||count($scopes)!==3)throw new DomainException('Different source export or selected rows');
        $expected=[
            ['email'=>'blwnwupotch@gmail.com','campaign_id'=>24,'content_sha256'=>'15b885f14caa4fb07ddf3d9ab8b7ad460fb343a088ab4ce714caa65e189c58a8'],
            ['email'=>'vendaboy.lm@gmail.com','campaign_id'=>25,'content_sha256'=>'78236992ee8df86bc8ecf23b10710efd1c64da113fcd13787054dcce6f7e8acf'],
            ['email'=>'vendaboy.lm@gmail.com','content_sha256'=>'3fa2b9973cda818112c86782057fa8e35ec31d1ba9fb44cf5445b55ffedd5f88'],
        ];
        if(self::canonical($scopes)!==self::canonical($expected)||!hash_equals(self::SEED_SHA256,self::digest($seed)))throw new DomainException('Approved report rows or scopes changed');
    }

    private static function approvedPreview(array $preview):bool
    {
        $counts=$preview['counts']??[];
        $campaigns=[];
        foreach($preview['rows']??[] as $row)if(isset($row['campaign_id']))$campaigns[(int)$row['campaign_id']]=true;
        return ($preview['ok']??null)===true&&($preview['read_only']??null)===true&&($preview['file_sha256']??null)===self::FILE_SHA256
            &&($preview['relevant_rows']??null)===14&&count($preview['rows']??[])===14&&count($preview['matches']??[])===13
            &&($counts['MATCHED']??0)===13&&($counts['UNRESOLVED_STATUS']??0)===1&&array_sum($counts)===14
            &&count($campaigns)===3&&isset($campaigns[24],$campaigns[25])
            &&is_string($preview['plan_sha256']??null)&&(bool)preg_match('/^[a-f0-9]{64}$/D',$preview['plan_sha256']);
    }

    private static function safePreview(array $preview):array
    {
        $counts=[];foreach(['MATCHED','UNRESOLVED_STATUS','UNMATCHED','AMBIGUOUS'] as $key)if(isset($preview['counts'][$key])&&is_int($preview['counts'][$key]))$counts[$key]=$preview['counts'][$key];
        $rows=[];
        foreach(array_slice(is_array($preview['rows']??null)?$preview['rows']:[],0,14) as $row){
            $safe=[];foreach(['source_row','message_id','campaign_id'] as $key)if(isset($row[$key])&&is_int($row[$key]))$safe[$key]=$row[$key];
            foreach(['match_code','provider_status','current_state','status_state'] as $key)if(isset($row[$key])&&is_string($row[$key])&&preg_match('/^[A-Za-z_]{1,40}$/D',$row[$key]))$safe[$key]=$row[$key];
            $rows[]=$safe;
        }
        return ['preview_counts'=>$counts,'preview_rows'=>$rows];
    }

    private static function safeResult(array $result,array $preview):array
    {
        $complete=($result['ok']??false)===true&&($result['code']??null)==='REPORT_RECONCILED'&&empty($result['errors'])&&count($result['results']??[])===13&&count($result['campaigns']??[])===3;
        $receipt=self::base($complete?'REPORT_RECONCILED':'REPORT_PARTIALLY_APPLIED',true)+$preview;
        $receipt['database_changes_may_have_committed']=true;
        $receipt['results']=[];$receipt['campaigns']=[];$receipt['applied_count']=0;$receipt['duplicate_count']=0;$receipt['charged_count']=0;$charged=0;
        foreach(array_slice(is_array($result['results']??null)?$result['results']:[],0,13) as $row){
            $safe=[];
            if(isset($row['message_id'])&&is_int($row['message_id']))$safe['message_id']=$row['message_id'];
            if(in_array($row['state']??null,['pending','retry','processing','sent','delivered','failed','skipped','dlr_unavailable'],true))$safe['state']=$row['state'];
            foreach(['applied','duplicate','charged_now','accepted'] as $key)if(is_bool($row[$key]??null))$safe[$key]=$row[$key];
            $amount=(string)($row['charge_amount']??'');if(preg_match('/^[0-9]{1,8}\.[0-9]{4}$/D',$amount))$safe['charge_amount']=$amount;
            $receipt['results'][]=$safe;
            if(!empty($safe['applied']))$receipt['applied_count']++;
            if(!empty($safe['duplicate']))$receipt['duplicate_count']++;
            if(!empty($safe['charged_now'])){$receipt['charged_count']++;$charged+=(int)round((float)($safe['charge_amount']??0)*10000);}
        }
        $receipt['charged_amount']=number_format($charged/10000,4,'.','');
        foreach(array_slice(is_array($result['campaigns']??null)?$result['campaigns']:[],0,3) as $row){
            if(!is_array($row))continue;$safe=[];
            foreach(['id','recipient_count','sent_count','delivered_count','failed_count','queued_count','processing_count','skipped_count'] as $key)if(isset($row[$key])&&ctype_digit((string)$row[$key]))$safe[$key]=(int)$row[$key];
            if(isset($row['name'])&&is_string($row['name'])&&strlen($row['name'])<=150)$safe['name']=$row['name'];
            if(in_array($row['state']??null,['draft','scheduled','queued','processing','completed','partially_failed','failed','cancelled'],true))$safe['state']=$row['state'];
            if(preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,4})?$/D',(string)($row['actual_cost']??'')))$safe['actual_cost']=(string)$row['actual_cost'];
            $receipt['campaigns'][]=$safe;
        }
        $receipt['errors']=[];
        foreach(array_slice(is_array($result['errors']??null)?$result['errors']:[],0,13) as $row){
            $safe=[];
            if(isset($row['message_id'])&&is_int($row['message_id']))$safe['message_id']=$row['message_id'];
            if(preg_match('/^[A-Z_]{1,60}$/D',(string)($row['code']??'')))$safe['code']=$row['code'];
            if(preg_match('/^[a-f0-9]{16,64}$/D',(string)($row['error_fingerprint']??'')))$safe['error_fingerprint']=$row['error_fingerprint'];
            $receipt['errors'][]=$safe;
        }
        return $receipt;
    }

    private static function base(string $code,bool $terminal):array
    {
        return ['schema_version'=>1,'approval_key'=>self::APPROVAL,'file_sha256'=>self::FILE_SHA256,'seed_sha256'=>self::SEED_SHA256,'code'=>$code,'terminal'=>$terminal,'observed_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'sms_submitted'=>false,'provider_requests'=>0];
    }

    private static function path(string $receiptPath):void
    {
        self::assertLocation($receiptPath);
        $directory=realpath(__DIR__.'/../storage/reconciliation');
        if($directory===false||is_link(dirname(__DIR__).'/storage')||is_link(__DIR__.'/../storage/reconciliation')||realpath(dirname($receiptPath))!==$directory||is_link($receiptPath))throw new DomainException('Receipt must use the exact approved private location');
        if(PHP_OS_FAMILY!=='Windows'&&(fileperms($directory)&0777)!==0700)throw new DomainException('Reconciliation storage must be private');
    }

    /** Only run() creates the two fixed private directories; GET stays read-only. */
    private static function initialize(string $receiptPath):void
    {
        self::assertLocation($receiptPath);
        $storage=dirname(__DIR__).'/storage';$directory=$storage.'/reconciliation';
        foreach([$storage,$directory] as $folder){
            if(is_link($folder)||file_exists($folder)&&!is_dir($folder))throw new DomainException('Private storage location is not a directory');
            if(!is_dir($folder)&&!mkdir($folder,0700))throw new RuntimeException('Private storage could not be initialized');
        }
    }

    private static function assertLocation(string $receiptPath):void
    {
        if(str_contains($receiptPath,"\0")||strlen($receiptPath)>1024||!(str_starts_with($receiptPath,'/')||preg_match('/^[A-Za-z]:[\\\\\/]/',$receiptPath)))throw new DomainException('Receipt path must be absolute');
        $normalize=static function(string $path):string{
            $parts=[];foreach(explode('/',str_replace('\\','/',$path)) as $part){if($part==='.'||$part==='')continue;if($part==='..'){array_pop($parts);continue;}$parts[]=$part;}
            $value=implode('/',$parts);return PHP_OS_FAMILY==='Windows'?strtolower($value):$value;
        };
        $directory=dirname(__DIR__).'/storage/reconciliation/';
        $approved=[$normalize($directory.self::RECEIPT_NAME),$normalize($directory.self::RESUME_RECEIPT_NAME)];
        if(!in_array($normalize($receiptPath),$approved,true))throw new DomainException('Different receipt location is not approved');
    }

    private static function assertResumeBaseline():void
    {
        $path=dirname(__DIR__).'/storage/reconciliation/'.self::RECEIPT_NAME;
        $original=self::readReceipt($path);
        if($original===null||!hash_equals(self::RESUME_BASELINE_SHA256,(string)hash_file('sha256',$path))||($original['code']??null)!=='REPORT_PARTIALLY_APPLIED'||($original['applied_count']??null)!==5||($original['charged_count']??null)!==5||($original['charged_amount']??null)!=='1.7500'||array_column($original['results']??[],'message_id')!==[936,937,938,939,940]||($original['errors'][0]['message_id']??null)!==941||($original['errors'][0]['error_fingerprint']??null)!=='7246d4b4244517ed')throw new DomainException('Resume requires the exact preserved stopped report receipt');
    }

    private static function persist(string $path,array $receipt):array
    {
        $receipt['receipt_payload_sha256']=self::digest($receipt);
        $bytes=self::PREFIX.base64_encode(json_encode($receipt,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))." */\n";
        $temporary=dirname($path).'/.report-59b298114f2f4519-'.bin2hex(random_bytes(12)).'.php';
        $stream=null;
        try{
            self::path($path);$mask=umask(0077);
            try{$stream=fopen($temporary,'x+b');}finally{umask($mask);}
            if($stream===false)throw new RuntimeException('Private receipt staging unavailable');
            $offset=0;while($offset<strlen($bytes)){$written=fwrite($stream,substr($bytes,$offset));if($written===false||$written===0)throw new RuntimeException('Receipt write interrupted');$offset+=$written;}
            if(!fflush($stream))throw new RuntimeException('Receipt flush unavailable');
            if(function_exists('fsync')&&!fsync($stream))throw new RuntimeException('Receipt sync unavailable');
            fclose($stream);$stream=null;
            if(!chmod($temporary,0600)||!rename($temporary,$path))throw new RuntimeException('Receipt publication unavailable');
            $verified=self::readReceipt($path);
            if($verified===null)throw new RuntimeException('Receipt readback unavailable');
            return $verified;
        }catch(Throwable $error){
            if(is_resource($stream))fclose($stream);
            // Retain any inert staging artifact for investigation. Do not unlink
            // an earlier receipt or claim the database transactions rolled back.
            return self::base('RECEIPT_WRITE_UNCERTAIN',false)+['operation_code'=>$receipt['code'],'database_changes_may_have_committed'=>(bool)($receipt['database_changes_may_have_committed']??false),'error_fingerprint'=>self::fingerprint($error)];
        }
    }

    private static function canonical(mixed $value):mixed
    {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as $key=>$item)$value[$key]=self::canonical($item);
        return $value;
    }

    private static function digest(array $value):string
    { return hash('sha256',json_encode(self::canonical($value),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); }
    private static function fingerprint(Throwable $error):string
    { return substr(hash('sha256',get_class($error).':'.$error->getMessage()),0,16); }
}
