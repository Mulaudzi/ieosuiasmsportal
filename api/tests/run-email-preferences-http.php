<?php
// Real local HTTP/form requests against the actual preference controller; no SMTP/provider/config.
if(PHP_SAPI!=='cli')exit(1);
$path=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ses-callback-fixture-'.bin2hex(random_bytes(12)).'.sqlite';$p=new PDO('sqlite:'.$path);$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY);INSERT INTO users VALUES(1);CREATE TABLE wallets(balance NUMERIC,reserved NUMERIC);INSERT INTO wallets VALUES(100,1);CREATE TABLE wallet_ledger(id INTEGER PRIMARY KEY);");
$migration=require __DIR__.'/../migrations/019_email_campaigns.php';$migration($p);
$id=str_repeat('a',32);$token=str_repeat('b',64);$q=$p->prepare("INSERT INTO email_campaign_recipients(id,campaign_id,user_id,email,state,message_id,unsubscribe_hash,updated_at) VALUES(?,'campaign',1,'fixture@example.org','accepted','<fixture@example.org>',?,'2026-01-01')");$q->execute([$id,hash('sha256',$token)]);
$p->exec("INSERT INTO email_sender_profiles(user_id,business_name,postal_address,reply_to,state,daily_limit,updated_at) VALUES(1,'Fixture organization','Synthetic address','fixture@example.org','approved',100,'2026-01-01')");
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);putenv('IEOSUIA_EMAIL_FIXTURE_DB='.$path);
$log=tempnam(sys_get_temp_dir(),'ses-http-log-');$pipes=[];$process=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/fixtures/email-preferences-router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes);if(!is_resource($process))throw new RuntimeException('Local fixture unavailable');fclose($pipes[0]);
$count=0;function httpCheck(bool $v,string $message):void{global $count;$count++;if(!$v)throw new RuntimeException($message);}
function requestFixture(string $path,string $method='GET',string $body=''):array{global $port;$context=stream_context_create(['http'=>['method'=>$method,'content'=>$body,'header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'ignore_errors'=>true,'timeout'=>5]]);$result=file_get_contents('http://127.0.0.1:'.$port.$path,false,$context);return [$http_response_header,$result];}
try{
    $ready=false;for($i=0;$i<60;$i++){$sock=@fsockopen('127.0.0.1',$port,$e,$msg,.1);if($sock){fclose($sock);$ready=true;break;}usleep(100000);}if(!$ready)throw new RuntimeException('Fixture did not start');
    $url='/email/preferences/'.$id.'?token='.$token;$initial=json_decode(requestFixture('/fixture-state')[1],true);
    [$headers,$html]=requestFixture($url);httpCheck(str_contains($headers[0],'200')&&str_contains($html,'Confirm unsubscribe'),'Anonymous GET displays actual confirmation form while sending disabled');
    httpCheck(str_contains(implode("\n",$headers),'Referrer-Policy: no-referrer')&&str_contains(implode("\n",$headers),"frame-ancestors 'none'"),'Public capability has no-referrer and clickjacking protections');
    requestFixture($url);httpCheck(json_decode(requestFixture('/fixture-state')[1],true)===$initial,'Repeated scanner GET cannot unsubscribe or change credits');
    [$headers]=requestFixture('/email/preferences/'.$id.'?token='.str_repeat('c',64),'POST','List-Unsubscribe=One-Click');httpCheck(str_contains($headers[0],'404'),'Wrong preference capability rejected');
    [$headers,$html]=requestFixture($url,'POST','List-Unsubscribe=One-Click');httpCheck(str_contains($headers[0],'200')&&str_contains($html,'You are unsubscribed'),'RFC 8058 form POST applies unsubscribe without login');
    $state=json_decode(requestFixture('/fixture-state')[1],true);httpCheck($state['suppressions']===1&&$state['sender']==='approved','One-click unsubscribe does not punish sender or duplicate suppressions');
    requestFixture($url,'POST','List-Unsubscribe=One-Click');httpCheck(json_decode(requestFixture('/fixture-state')[1],true)===$state,'Repeated one-click POST idempotent');
    requestFixture($url.'&report=1');httpCheck(json_decode(requestFixture('/fixture-state')[1],true)===$state,'Scanner GET cannot report abuse');
    requestFixture($url.'&report=1','POST');$reported=json_decode(requestFixture('/fixture-state')[1],true);httpCheck($reported['sender']==='suspended'&&$reported['suppressions']===1,'Explicit abuse POST suppresses recipient and pauses sender');
    httpCheck($reported['wallet']===$initial['wallet']&&$reported['ledger']===$initial['ledger'],'Preferences never touch balances, reservations or ledger');
    echo "Email preferences: $count real loopback HTTP checks passed; no login, sending, live data or cloud access.\n";
}finally{proc_terminate($process);proc_close($process);$q=null;$p=null;gc_collect_cycles();if(!unlink($path))throw new RuntimeException('Synthetic fixture file needs cleanup: '.$path);putenv('IEOSUIA_EMAIL_FIXTURE_DB');echo 'Synthetic HTTP logs retained at '.$log.PHP_EOL;}
