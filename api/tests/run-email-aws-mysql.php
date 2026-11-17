<?php
// A separately started, loopback-only disposable MariaDB is mandatory.
declare(strict_types=1);
function env(string $key,mixed $default=null):mixed{return$key==='SMS_PRICE_PER_SEGMENT'?'0.35':$default;}
require_once __DIR__.'/../domain/EmailAwsAccounting.php';require_once __DIR__.'/../domain/WalletService.php';
function connection(string $database=''):PDO{return new PDO('mysql:host=127.0.0.1;port=33169;'.($database?'dbname='.$database.';':'').'charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
if(($argv[1]??'')==='child'){
    $args=json_decode(base64_decode($argv[2]),true,12,JSON_THROW_ON_ERROR);if(!preg_match('/^email_aws_test_[a-f0-9]{12}$/D',$args['database']))exit(2);
    $until=microtime(true)+8;while(!file_exists($args['barrier'])&&microtime(true)<$until)usleep(10000);
    if(!file_exists($args['barrier']))exit(3);
    $p=connection($args['database']);$service=new EmailAwsAccounting($p);
    try{
        if($args['action']==='reserve')$value=$service->reserve(1,$args['campaign']);
        elseif($args['action']==='cancel')$value=$service->cancel(1,$args['data']['id']);
        elseif($args['action']==='sms'){$p->beginTransaction();WalletService::reserve($p,1,42,89.90);$p->commit();$value=true;}
        else $value=$service->command($args['action'],$args['data']);
        echo json_encode(['ok'=>true,'value'=>$value]);
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();echo json_encode(['ok'=>false,'class'=>get_class($e),'fixture_error'=>$e->getMessage()]);}
    exit;
}
$p=connection();$database='email_aws_test_'.bin2hex(random_bytes(6));$p->exec('CREATE DATABASE '.$database);$p=connection($database);$checks=0;
function ok(bool $v,string $label):void{global$checks;$checks++;if(!$v)throw new RuntimeException($label);}
function fixture(PDO $p):void {
    foreach(['email_aws_nonces','email_aws_attempts','email_aws_reservations','wallet_ledger','wallets','users','opt_outs']as$t)$p->exec('DROP TABLE IF EXISTS '.$t);
    $p->exec("CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(254),is_active INT,email_verified_at DATETIME)ENGINE=InnoDB;INSERT INTO users VALUES(1,'owner@example.org',1,NOW());CREATE TABLE wallets(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,balance DECIMAL(14,4),reserved DECIMAL(14,4),currency VARCHAR(3),updated_at DATETIME)ENGINE=InnoDB;INSERT INTO wallets VALUES(1,1,100,10,'ZAR',NOW());CREATE TABLE wallet_ledger(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED,wallet_id BIGINT UNSIGNED,campaign_id BIGINT UNSIGNED NULL,payment_id BIGINT UNSIGNED NULL,operation VARCHAR(40),direction VARCHAR(20),amount DECIMAL(14,4),idempotency_key VARCHAR(120) UNIQUE,metadata_json TEXT,created_at DATETIME)ENGINE=InnoDB;CREATE TABLE opt_outs(user_id BIGINT UNSIGNED,channel VARCHAR(20),recipient VARCHAR(254))ENGINE=InnoDB;");
    $m=require __DIR__.'/../migrations/029_email_aws_bridge.php';$m($p);$m($p);$m=require __DIR__.'/../migrations/030_email_reply_to_account_binding.php';$m($p);$m($p);
}
function race(string $database,array $jobs):array {
    $barrier=sys_get_temp_dir().DIRECTORY_SEPARATOR.'email-aws-barrier-'.bin2hex(random_bytes(10));$children=[];
    try{
        foreach($jobs as$job){$arg=base64_encode(json_encode($job+['database'=>$database,'barrier'=>$barrier],JSON_THROW_ON_ERROR));$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'child',$arg],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($process))throw new RuntimeException('Cannot start isolated child');fclose($pipes[0]);$children[]=[$process,$pipes];}
        file_put_contents($barrier,'start');$results=[];
        foreach($children as[$process,$pipes]){$raw=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0||$error!=='')throw new RuntimeException('Isolated concurrency child failed');$results[]=json_decode($raw,true,12,JSON_THROW_ON_ERROR);}
        return$results;
    }finally{if(is_file($barrier))unlink($barrier);}
}
try{
    $campaign=['id'=>str_repeat('a',32),'user_id'=>1,'fingerprint'=>str_repeat('b',64),'state'=>'approved','sender'=>['reply_to'=>'owner@example.org'],'unit_cents'=>7,'recipient_count'=>2,'recipients'=>[['id'=>str_repeat('c',32),'email'=>'one@example.org'],['id'=>str_repeat('d',32),'email'=>'two@example.org']]];
    $command=['id'=>$campaign['id'],'user_id'=>1,'fingerprint'=>$campaign['fingerprint'],'recipient_id'=>str_repeat('c',32),'email'=>'one@example.org'];
    fixture($p);$r=race($database,[['action'=>'reserve','campaign'=>$campaign],['action'=>'reserve','campaign'=>$campaign]]);
    ok($r[0]['ok']&&$r[1]['ok'],'Concurrent identical reservations both acknowledge: '.json_encode($r));ok((int)$p->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn()===1,'One reservation ledger entry');ok($p->query('SELECT reserved FROM wallets')->fetchColumn()==='10.1400','Exact reservation with prior SMS funds');
    $s=new EmailAwsAccounting($p);$s->command('attempt',$command);
    $r=race($database,[['action'=>'settle','data'=>$command+['provider_id'=>'same-provider-reference']],['action'=>'settle','data'=>$command+['provider_id'=>'same-provider-reference']]]);
    ok($r[0]['ok']&&$r[1]['ok'],'Concurrent settlement retry acknowledges');ok($p->query('SELECT balance FROM wallets')->fetchColumn()==='99.9300','One exact debit');ok((int)$p->query("SELECT COUNT(*) FROM wallet_ledger WHERE direction='debit'")->fetchColumn()===1,'One debit ledger entry');
    fixture($p);$s=new EmailAwsAccounting($p);$s->reserve(1,$campaign);
    $r=race($database,[['action'=>'attempt','data'=>$command],['action'=>'cancel','data'=>$command]]);
    ok($r[0]['ok']&&$r[1]['ok'],'Permit/cancel race is serialized');$state=$p->query("SELECT state FROM email_aws_attempts WHERE id='".$command['recipient_id']."'")->fetchColumn();$reserved=$p->query('SELECT reserved FROM wallets')->fetchColumn();ok(($state==='issued'&&$reserved==='10.0700')||($state==='released'&&$reserved==='10.0000'),'Cancellation never refunds issued attempt');
    fixture($p);$r=race($database,[['action'=>'reserve','campaign'=>$campaign],['action'=>'sms']]);
    ok(count(array_filter($r,fn($v)=>$v['ok']))===1,'SMS/email competing reservations cannot overspend');ok((float)$p->query('SELECT reserved FROM wallets')->fetchColumn()<=100,'Reserved funds remain bounded by balance');
    fixture($p);$s=new EmailAwsAccounting($p);$s->reserve(1,$campaign);$s->command('attempt',$command);
    $r=race($database,[['action'=>'cancel','data'=>$command],['action'=>'settle','data'=>$command+['provider_id'=>'cancel-settle-reference']]]);
    ok($r[0]['ok']&&$r[1]['ok'],'Cancellation/acceptance race succeeds');ok($p->query('SELECT balance FROM wallets')->fetchColumn()==='99.9300','Accepted recipient charged once through cancellation');ok($p->query('SELECT reserved FROM wallets')->fetchColumn()==='10.0000','Only unrelated SMS reservation remains');
    fixture($p);$alternate=$campaign;$alternate['sender']=['user_id'=>1,'reply_to'=>'replies@example.org','account_email'=>'owner@example.org','reply_to_verification'=>str_repeat('a',32),'reply_to_verified_at'=>1800000000];
    $r=race($database,[['action'=>'reserve','campaign'=>$alternate],['action'=>'reserve','campaign'=>$alternate]]);ok($r[0]['ok']&&$r[1]['ok'],'Alternate reply inbox preserves concurrent reservation idempotency');ok((int)$p->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn()===1,'Alternate reply inbox still reserves exactly once');
    echo "AWS email bridge MySQL: {$checks} checks passed; genuine parallel processes, disposable loopback database only.\n";
}finally{$p=null;$cleanup=connection();$cleanup->exec('DROP DATABASE '.$database);}
