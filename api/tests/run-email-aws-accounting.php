<?php
// Disposable SQLite financial/protocol fixtures. No application configuration or network.
declare(strict_types=1);
function env(string $name,mixed $default=null):mixed{return$_ENV[$name]??$default;}
require_once __DIR__.'/../domain/EmailAwsAccounting.php';
require_once __DIR__.'/../domain/EmailAwsSignature.php';
$checks=0;
function check(bool $value,string $message):void{global$checks;$checks++;if(!$value)throw new RuntimeException($message);}
function denied(callable $f,int $status):void{try{$f();throw new RuntimeException('Expected denial');}catch(EmailAwsException $e){check($e->status===$status,'Expected domain status');}catch(RuntimeException $e){check($e->getCode()===$status,'Expected signature status');}}
function fixture():array {
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,is_active INTEGER,email_verified_at TEXT);INSERT INTO users VALUES(1,'owner@example.org',1,'verified'),(2,'other@example.org',1,'verified');CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER,balance NUMERIC,reserved NUMERIC,currency TEXT,updated_at TEXT);INSERT INTO wallets VALUES(1,1,100.0000,10.0000,'ZAR','before'),(2,2,100,0,'ZAR','before');CREATE TABLE wallet_ledger(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,wallet_id INTEGER,campaign_id INTEGER,payment_id INTEGER,operation TEXT,direction TEXT,amount NUMERIC,idempotency_key TEXT UNIQUE,metadata_json TEXT,created_at TEXT);CREATE TABLE opt_outs(user_id INTEGER,channel TEXT,recipient TEXT);");
    $m=require __DIR__.'/../migrations/029_email_aws_bridge.php';$m($p);$m($p);$m=require __DIR__.'/../migrations/030_email_reply_to_account_binding.php';$m($p);$m($p);
    $_ENV['SMS_PRICE_PER_SEGMENT']='0.35';return[$p,new EmailAwsAccounting($p)];
}
function campaign(string $id='a'):array{return['id'=>str_repeat($id,32),'user_id'=>1,'fingerprint'=>str_repeat('b',64),'state'=>'approved','sender'=>['reply_to'=>'owner@example.org'],'unit_cents'=>7,'recipient_count'=>2,'recipients'=>[['id'=>str_repeat($id==='a'?'c':'e',32),'email'=>'one@example.org'],['id'=>str_repeat($id==='a'?'d':'f',32),'email'=>'two@example.org']]];}
function command(array $c,int $index):array{return['id'=>$c['id'],'user_id'=>1,'fingerprint'=>$c['fingerprint'],'recipient_id'=>$c['recipients'][$index]['id'],'email'=>$c['recipients'][$index]['email']];}
[$p,$s]=fixture();$c=campaign();$proof=$s->reserve(1,$c);
check($proof['unit_cents']===7,'Server derives exact price');check($s->reserve(1,$c)===$proof,'Reserve retry returns same proof');
check((int)$p->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn()===1,'No duplicate reservation');
check(abs((float)$p->query('SELECT reserved FROM wallets WHERE id=1')->fetchColumn()-10.14)<.00001,'Email reserve preserves previous SMS reservation');
denied(fn()=>$s->reserve(2,$c),409);
$changed=$c;$changed['recipients'][0]['email']='changed@example.org';denied(fn()=>$s->reserve(1,$changed),409);
$bad=command($c,0);$bad['email']='foreign@example.org';denied(fn()=>$s->command('attempt',$bad),409);
check($s->command('attempt',command($c,0))['allowed'],'Attempt issued');check($s->command('attempt',command($c,0))['existing'],'Duplicate permit lookup is idempotent');
$cancel=$s->cancel(1,$c['id']);check($cancel['released_cents']===7,'Cancellation releases only unissued recipient');
check($s->cancel(1,$c['id'])['released_cents']===0,'Cancellation retry cannot release twice');
check(!$s->command('attempt',command($c,1))['allowed'],'Cancelled recipient cannot receive permit');
check(!$s->command('release',command($c,0))['released'],'Issued attempt cannot be refunded as unattempted');
$settlement=command($c,0)+['provider_id'=>'ses-reference-one'];check($s->command('settle',$settlement)['settled'],'Issued accepted recipient settles');
check($s->command('settle',$settlement)['duplicate'],'Duplicate provider evidence settles once');
denied(fn()=>$s->command('settle',array_replace($settlement,['provider_id'=>'different-provider-reference'])),409);
check(abs((float)$p->query('SELECT balance FROM wallets WHERE id=1')->fetchColumn()-99.93)<.00001,'Only one seven-cent debit');
check(abs((float)$p->query('SELECT reserved FROM wallets WHERE id=1')->fetchColumn()-10)<.00001,'Unrelated SMS reservation remains intact');
check($s->command('status',command($c,0))['charged_cents']===7,'Status uses committed financial data');
[$p,$s]=fixture();$c=campaign();$s->reserve(1,$c);$p->exec("INSERT INTO opt_outs VALUES(1,'email','one@example.org')");
check(!$s->command('attempt',command($c,0))['allowed'],'Existing email opt-out checked at permit time');
check($s->command('release',command($c,0))['duplicate'],'Opt-out release cannot be charged twice');
[$p,$s]=fixture();$c=campaign();$p->exec("UPDATE wallets SET balance=10.01 WHERE id=1");denied(fn()=>$s->reserve(1,$c),409);
check((int)$p->query('SELECT COUNT(*) FROM email_aws_reservations')->fetchColumn()===0,'Failed reservation rolls back all new state');
check((int)$p->query('SELECT COUNT(*) FROM wallet_ledger')->fetchColumn()===0,'Failed reservation creates no ledger entry');
[$p,$s]=fixture();$c=campaign();$s->reserve(1,$c);denied(fn()=>$s->command('settle',command($c,0)+['provider_id'=>'no-issued-attempt']),409);
$p->exec("CREATE TRIGGER reject_email_debit BEFORE INSERT ON wallet_ledger WHEN NEW.direction='debit' BEGIN SELECT RAISE(ABORT,'synthetic debit failure'); END;");
$s->command('attempt',command($c,0));try{$s->command('settle',command($c,0)+['provider_id'=>'rollback-test-reference']);throw new RuntimeException('Expected failed debit');}catch(PDOException){}
check($p->query("SELECT state FROM email_aws_attempts WHERE id='".$c['recipients'][0]['id']."'")->fetchColumn()==='issued','Failed debit preserves issued attempt');
check((float)$p->query('SELECT balance FROM wallets WHERE id=1')->fetchColumn()===100.0,'Failed debit preserves balance');
$body='{"action":"status","data":{}}';$now=1800000000;$headers=EmailAwsSignature::sign(str_repeat('k',64),'email-accounting','/api/email-aws-accounting.php',$body,$now);
$seed=$p->prepare('INSERT INTO email_aws_nonces(key_id,nonce_hash,expires_at)VALUES(?,?,?)');$seed->execute(['fixture-expired',str_repeat('e',64),$now-1]);$seed->execute(['fixture-current',str_repeat('f',64),$now+100]);
$map=[];foreach($headers as$header){[$name,$value]=explode(': ',$header,2);$map[strtolower($name)]=$value;}
EmailAwsSignature::verify($p,['v1'=>str_repeat('k',64)],'email-accounting','/api/email-aws-accounting.php',$body,$map,$now);check(true,'Valid service signature');
check((int)$p->query("SELECT COUNT(*) FROM email_aws_nonces WHERE key_id='fixture-expired'")->fetchColumn()===0,'Authenticated requests purge expired replay records');
check((int)$p->query("SELECT COUNT(*) FROM email_aws_nonces WHERE key_id='fixture-current'")->fetchColumn()===1,'Cleanup preserves unexpired replay protection');
denied(fn()=>EmailAwsSignature::verify($p,['v1'=>str_repeat('k',64)],'email-accounting','/api/email-aws-accounting.php',$body,$map,$now),409);
denied(fn()=>EmailAwsSignature::verify($p,['v1'=>str_repeat('k',64)],'email-accounting','/api/email-aws-accounting.php',$body.' ',$map,$now),401);
denied(fn()=>EmailAwsSignature::verify($p,['v1'=>str_repeat('k',64)],'email-accounting','/api/email-aws-accounting.php',$body,$map,$now+121),401);
denied(fn()=>EmailAwsSignature::verify($p,['v1'=>str_repeat('k',64)],'email-campaign-api','/service',$body,$map,$now),401);
require_once __DIR__.'/../services/EmailAwsClient.php';
$_ENV['EMAIL_AWS_API_URL']='https://'.str_repeat('a',26).'.lambda-url.eu-north-1.on.aws';$_ENV['EMAIL_AWS_PORTAL_KEY']=str_repeat('p',64);
$captured=null;$client=new EmailAwsClient(static function($url,$headers,$body)use(&$captured){$captured=json_decode($body);return['status'=>200,'body'=>'{"success":true,"enabled":false}'];});
$client->call(['id'=>1,'role'=>'user'],'limits');check($captured->data instanceof stdClass,'Empty PHP command is an object in the Python service protocol');check($captured->operation==='limits','Gateway preserves explicit command');
[$p,$s]=fixture();$c=campaign();$s->reserve(1,$c);$s->command('attempt',command($c,0));$p->exec('UPDATE users SET is_active=0 WHERE id=1');
check($s->command('settle',command($c,0)+['provider_id'=>'inactive-account-accepted'])['settled'],'Already accepted work can settle after account deactivation');
denied(fn()=>$s->command('attempt',command($c,1)),403);
[$p,$s]=fixture();$c=campaign();$c['sender']=['user_id'=>1,'reply_to'=>'replies@example.org','account_email'=>'owner@example.org','reply_to_verification'=>str_repeat('a',32),'reply_to_verified_at'=>1800000000];
$s->reserve(1,$c);check($s->command('attempt',command($c,0))['allowed'],'Verified alternate Reply-To preserves payer authorization');
$row=$p->query('SELECT reply_to_sha256,account_email_sha256 FROM email_aws_reservations')->fetch(PDO::FETCH_ASSOC);check($row['reply_to_sha256']===hash('sha256','replies@example.org')&&$row['account_email_sha256']===hash('sha256','owner@example.org'),'Distinct reply and account email bindings retained');
$p->exec("UPDATE users SET email='changed@example.org' WHERE id=1");check(!$s->command('attempt',command($c,1))['allowed'],'Changed login email blocks future permits with alternate Reply-To');
[$p,$s]=fixture();$c=campaign();$c['sender']['reply_to']='foreign@example.org';denied(fn()=>$s->reserve(1,$c),409);
[$p,$s]=fixture();$c=campaign();$s->reserve(1,$c);$p->exec('UPDATE email_aws_reservations SET account_email_sha256=NULL');check($s->command('attempt',command($c,0))['allowed'],'Existing reservations retain legacy account binding');
echo "AWS email accounting: {$checks} checks passed. SQLite only; no live wallets, AWS or email.\n";
