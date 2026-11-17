<?php
declare(strict_types=1);
// Defense in depth: this maintenance script is never executable through HTTP.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
$usage="Credit-transfer database setup (schema only)\n\nInspect, default/read-only:\n  php api/bin/setup-credit-transfers.php\n\nApply after reviewing inspection and verifying a database backup:\n  php api/bin/setup-credit-transfers.php --apply --confirm=021_credit_transfers --backup-confirmed\n\nOnly if inspect says prerequisite 015 is missing, append:\n  --include-security-prerequisite\n\nOnly after reviewing a partially applied/unrecorded setup, append:\n  --resume-partial\n\nUses existing api/.env on the server; never pass credentials here.\nAllowlisted migrations: 021 and explicitly permitted prerequisite 015 only.\nEmail migration 019 is excluded. No credits move and the feature is not enabled.\nDo not run the general migrate.php script for this setup.\n";
$args=array_slice($argv,1);if($args===['--help']){echo $usage;exit;}
$allowed=['--apply','--confirm=021_credit_transfers','--backup-confirmed','--include-security-prerequisite','--resume-partial'];
if(array_diff($args,$allowed)||count($args)!==count(array_unique($args))||(!in_array('--apply',$args,true)&&$args)){
    fwrite(STDERR,$usage);exit(2);
}
$apply=in_array('--apply',$args,true);
if($apply&&(!in_array('--confirm=021_credit_transfers',$args,true)||!in_array('--backup-confirmed',$args,true))){fwrite(STDERR,$usage);exit(2);}
try{
    if(!is_file(__DIR__.'/../.env'))throw new RuntimeException('Server api/.env is missing. Install this tool in the existing portal; do not supply credentials in arguments.');
    require_once __DIR__.'/../config/database.php';
    require_once __DIR__.'/../domain/CreditTransferSetup.php';
    $runner=new CreditTransferSetup(db(),__DIR__.'/../migrations');
    $result=$apply?$runner->apply('021_credit_transfers',true,in_array('--include-security-prerequisite',$args,true),in_array('--resume-partial',$args,true)):$runner->inspect();
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit(!empty($result['ready_to_apply'])||($apply&&!empty($result['ok']))?0:1);
}catch(Throwable $e){
    $fingerprint=substr(hash('sha256',get_class($e).':'.$e->getMessage()),0,16);
    error_log('Credit-transfer schema setup failed class='.get_class($e).' fingerprint='.$fingerprint);
    $safe=$e instanceof RuntimeException&&!$e instanceof PDOException?$e->getMessage():'Database setup could not finish. Inspect the schema before any further attempt.';
    echo json_encode(['ok'=>false,'code'=>'CREDIT_TRANSFER_SETUP_UNAVAILABLE','message'=>$safe,'error_fingerprint'=>$fingerprint,'inspect_before_retry'=>true,'ddl_may_have_partially_completed'=>$apply,'credits_moved'=>false,'sms_submitted'=>false,'emails_sent'=>false,'payments_processed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}
