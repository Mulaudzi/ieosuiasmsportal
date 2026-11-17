<?php
declare(strict_types=1);
// Offline integration tests: real SQLite transactions/encryption, synthetic Meta transport only.
require_once __DIR__.'/../domain/MetaWhatsAppConnections.php';
$checks = 0;
function check(bool $condition, string $name): void {
    global $checks; $checks++;
    if (!$condition) { fwrite(STDERR, 'FAIL: '.$name.PHP_EOL); exit(1); }
}
function denied(callable $call, int $status, string $name): void {
    try { $call(); check(false,$name); }
    catch (MetaWhatsAppConnectException $e) { check($e->status === $status,$name); }
}
function fixture(): array {
    if (getenv('WHATSAPP_TEST_MYSQL_PORT') !== false) {
        static $admin = null;
        if ($admin === null) {
            if (getenv('WHATSAPP_TEST_MYSQL_PORT') !== '33176') throw new RuntimeException('Only the dedicated fixture port is allowed');
            $admin = new PDO('mysql:host=127.0.0.1;port=33176;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $actual = strtolower(rtrim(str_replace('\\','/',(string)$admin->query('SELECT @@datadir')->fetchColumn()),'/'));
            $expected = strtolower(rtrim(str_replace('\\','/',(string)getenv('WHATSAPP_TEST_MYSQL_DATADIR')),'/'));
            if ($expected === '' || $actual !== $expected || !str_contains($expected,'/whatsapp-customer-connect-20261103/')) throw new RuntimeException('Not the owned disposable database');
        }
        $database = 'wa_connect_fixture_'.bin2hex(random_bytes(8));
        $admin->exec('CREATE DATABASE `'.$database.'`');
        register_shutdown_function(static function() use ($admin,$database) {
            if (preg_match('/^wa_connect_fixture_[a-f0-9]{16}$/D',$database)) $admin->exec('DROP DATABASE `'.$database.'`');
        });
        $pdo = new PDO('mysql:host=127.0.0.1;port=33176;dbname='.$database.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    } else {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    }
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY,is_active INTEGER,auth_version INTEGER,role VARCHAR(16) NOT NULL DEFAULT 'user')");
    $pdo->exec("INSERT INTO users VALUES (1,1,1,'user'),(2,1,1,'user')");
    $pdo->exec('CREATE TABLE revoked_auth_tokens (token_hash VARCHAR(64) PRIMARY KEY,expires_at TEXT)');
    $pdo->exec("CREATE TABLE sms_sentinel (id INTEGER PRIMARY KEY,value TEXT); INSERT INTO sms_sentinel VALUES (1,'preserve')");
    $migrate = require __DIR__.'/../migrations/026_whatsapp_customer_connections.php'; $migrate($pdo); $migrate($pdo);
    $config = new MetaWhatsAppConnectConfig('123456789','a'.str_repeat('b',31),'v25.0','987654321',
        'https://portal.example/api/whatsapp-meta-oauth.php',base64_encode(str_repeat('k',32)));
    $stub = (object)['now'=>1800000000,'calls'=>[], 'failure'=>false,'debug'=>[], 'phones'=>[], 'onCall'=>null];
    $token = 'OFFLINE_FIXTURE_'.str_repeat('T',80);
    $stub->debug = ['data'=>['is_valid'=>true,'app_id'=>'123456789','type'=>'SYSTEM_USER','expires_at'=>$stub->now+5184000,
        'scopes'=>['whatsapp_business_management','whatsapp_business_messaging'], 'granular_scopes'=>[
            ['scope'=>'whatsapp_business_management','target_ids'=>['111111111']],
            ['scope'=>'whatsapp_business_messaging','target_ids'=>['111111111']]]]];
    $stub->phones = ['data'=>[['id'=>'222222222','display_phone_number'=>'+27 00 000 0000','verified_name'=>'Fixture business']]];
    $meta = new MetaWhatsAppOnboardingClient($config, static function(string $url, string $bearer) use ($stub,$token) {
        $stub->calls[] = $url;
        if ($stub->onCall !== null) ($stub->onCall)($url);
        if ($stub->failure) throw new RuntimeException('OFFLINE sensitive error '.$token.' '.$url);
        $path = parse_url($url,PHP_URL_PATH);
        return match($path) {
            '/v25.0/oauth/access_token'=>['access_token'=>$token],
            '/v25.0/debug_token'=>$stub->debug,
            '/v25.0/111111111'=>['id'=>'111111111','name'=>'Fixture business'],
            '/v25.0/111111111/phone_numbers'=>$stub->phones,
            default=>throw new RuntimeException('Unexpected fixture request'),
        };
    });
    return [$pdo,$config,$stub,$token,new MetaWhatsAppConnections($pdo,$config,$meta,static fn()=>$stub->now),$meta];
}
function beginAttempt(MetaWhatsAppConnections $service, object $stub, int $owner = 1): array {
    $session = hash('sha256','fixture-session-'.$owner); $browser = str_repeat((string)$owner,64);
    $result = $service->start($owner,1,$session,$browser,$stub->now+3600);
    parse_str((string)parse_url($result['authorize_url'],PHP_URL_QUERY),$q);
    return [$q['state'],hash('sha256',$q['state']),$session,$browser];
}
function authorize(MetaWhatsAppConnections $service, object $stub, int $owner = 1): array {
    $attempt = beginAttempt($service,$stub,$owner);
    $service->callback(['state'=>$attempt[0],'code'=>'OFFLINE-code','phone_number_id'=>'999999999','waba_id'=>'999999999'],$attempt[3]);
    return $attempt;
}

[$pdo,$config,$stub,$token,$service,$meta] = fixture();
$pilot = new MetaWhatsAppConnectConfig('123456789',str_repeat('b',32),'v25.0','987654321',
    'https://portal.example/api/whatsapp-meta-oauth.php',base64_encode(str_repeat('k',32)),'whatsapp_business_app_onboarding',true);
parse_str((string)parse_url($pilot->authorizeUrl(str_repeat('a',64)),PHP_URL_QUERY),$pilotQuery);
check(json_decode($pilotQuery['extras'],true) === ['version'=>'v4','sessionInfoVersion'=>'3','featureType'=>'whatsapp_business_app_onboarding'],'generated URL preserves owner-provided Meta signup options');
check($pilotQuery['state'] === str_repeat('a',64) && $pilotQuery['config_id'] === '987654321','signup options retain state and configured ID');
denied(fn()=>$pilot->assertPilotAccess(false),503,'administrator pilot rejects customer access');
$pilot->assertPilotAccess(true);check(true,'administrator pilot allows authenticated administrators');
$service->assertReady(); check($pdo->query('SELECT value FROM sms_sentinel')->fetchColumn() === 'preserve','additive idempotent migration preserves unrelated data');
$sealed = $config->seal(['token'=>$token],'owner:1');
check(!str_contains($sealed,$token) && $sealed !== $config->seal(['token'=>$token],'owner:1'),'randomized authenticated encryption');
check($config->unseal($sealed,'owner:1')['token'] === $token,'credential round trip');
denied(fn()=>$config->unseal($sealed,'owner:2'),503,'ciphertext cannot be moved to another owner context');
$raw = base64_decode($sealed); $raw[28] = chr(ord($raw[28]) ^ 1);
denied(fn()=>$config->unseal(base64_encode($raw),'owner:1'),503,'ciphertext tampering rejected');
foreach (['http://portal.example/api/whatsapp-meta-oauth.php','https://evil.example@portal.example/api/whatsapp-meta-oauth.php',
    'https://portal.example/api/whatsapp-meta-oauth.php?redirect=evil','https://portal.example/api/whatsapp-meta-webhook.php'] as $uri) {
    denied(fn()=>new MetaWhatsAppConnectConfig('123456789',str_repeat('b',32),'v25.0','987654321',$uri,base64_encode(str_repeat('k',32))),503,'invalid callback URI rejected');
}
[$state,$hash,$session,$browser] = beginAttempt($service,$stub);
$intent = $pdo->query('SELECT * FROM whatsapp_connect_intents')->fetch(PDO::FETCH_ASSOC);
check($intent['state_hash'] === $hash && $intent['browser_hash'] !== $browser && $intent['expires_at'] === $stub->now+600,'only hashed state/browser binding and short expiry stored');
check(!str_contains(json_encode($intent),$state),'raw OAuth state is not persisted');
denied(fn()=>$service->callback(['state'=>$state,'code'=>'OFFLINE-code'],str_repeat('f',64)),400,'wrong browser rejected');
denied(fn()=>$service->callback(['state'=>str_repeat('f',64),'code'=>'OFFLINE-code'],$browser),400,'unknown state rejected');
denied(fn()=>$service->callback(['state'=>[$state],'code'=>'OFFLINE-code'],$browser),400,'array state rejected');
denied(fn()=>$service->callback(['code'=>'OFFLINE-code'],$browser),400,'hosted callback without returned state fails closed');
check(count($stub->calls) === 0,'invalid callbacks never exchange codes');
$returnPath = $service->callback(['state'=>$state,'code'=>'OFFLINE-code','phone_number_id'=>'999999999'],$browser);
check($returnPath === '/settings?tab=whatsapp','customer signup returns to customer workspace');
$pending = $service->status(1,$session,$browser)['pending'];
check($pending['options'][0]['phone_number_id'] === '222222222','phone options come from Meta not browser');
check(!str_contains(json_encode($service->status(1,$session,$browser)),$token),'browser status never exposes a token');
check($pdo->query('SELECT token_cipher FROM whatsapp_connect_intents')->fetchColumn() !== $token,'pending token encrypted at rest');
$calls = count($stub->calls);
denied(fn()=>$service->callback(['state'=>$state,'code'=>'OFFLINE-code'],$browser),409,'callback replay rejected');
check(count($stub->calls) === $calls,'replay never repeats token exchange');
denied(fn()=>$service->confirm(2,$session,$browser,$hash,'222222222'),409,'other owner cannot confirm intent');
denied(fn()=>$service->confirm(1,hash('sha256','other-session'),$browser,$hash,'222222222'),409,'other session cannot confirm intent');
denied(fn()=>$service->confirm(1,$session,str_repeat('f',64),$hash,'222222222'),409,'other browser cannot confirm intent');
denied(fn()=>$service->confirm(1,$session,$browser,$hash,'999999999'),422,'ungranted phone cannot be selected');
check($service->status(2,$session,$browser)['pending'] === null,'pending options isolated by owner');
$service->confirm(1,$session,$browser,$hash,'222222222');
$row = $pdo->query('SELECT * FROM whatsapp_customer_connections')->fetch(PDO::FETCH_ASSOC);
check((int)$row['owner_id'] === 1 && $row['status'] === 'authorized','explicit confirmation links verified number');
check($config->unseal($row['token_cipher'],'connection:1:111111111:222222222')['token'] === $token,'saved token encrypted and bound to exact owner/WABA/phone');
check($pdo->query('SELECT token_cipher FROM whatsapp_connect_intents')->fetchColumn() === null,'confirmation erases pending grant');
check($service->status(1,$session,$browser)['sending_enabled'] === false,'linking never enables sending');
check($service->status(2,$session,$browser)['connections'] === [],'connection listing isolated by owner');
$calls = count($stub->calls);
denied(fn()=>$service->confirm(1,$session,$browser,$hash,'222222222'),409,'confirmation replay rejected');
check(count($stub->calls) === $calls,'confirmation replay does not call Meta');
denied(fn()=>$service->disconnect(2,'222222222'),404,'other owner cannot disconnect number');

$other = authorize($service,$stub,2);
denied(fn()=>$service->confirm(2,$other[2],$other[3],$other[1],'222222222'),409,'existing WABA cannot be claimed by a second owner');
check((int)$pdo->query('SELECT owner_id FROM whatsapp_customer_connections')->fetchColumn() === 1,'cross-owner claim leaves original connection intact');
$service->disconnect(1,'222222222');
$row = $pdo->query('SELECT status,token_cipher FROM whatsapp_customer_connections')->fetch(PDO::FETCH_ASSOC);
check($row['status'] === 'disconnected' && $row['token_cipher'] === null,'disconnect removes saved credentials');
check((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_customer_accounts')->fetchColumn() === 1,'disconnect retains WABA ownership reservation');
$stub->phones['data'][0]['id'] = '333333333';
$other = authorize($service,$stub,2);
denied(fn()=>$service->confirm(2,$other[2],$other[3],$other[1],'333333333'),409,'another phone in reserved WABA cannot bypass ownership');
check((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_customer_connections')->fetchColumn() === 1,'failed ownership claim rolls back');

[$pdo,$config,$stub,$token,$service,$meta] = fixture();
[$state,$hash,$session,$browser] = beginAttempt($service,$stub);
$stub->failure = true;
try { $service->callback(['state'=>$state,'code'=>'OFFLINE-code'],$browser); check(false,'transport failure expected'); }
catch (MetaWhatsAppConnectException $e) { check($e->status === 502 && !str_contains($e->getMessage(),$token) && !str_contains($e->getMessage(),'graph.facebook'),'provider errors redacted'); }
$calls = count($stub->calls);
denied(fn()=>$service->callback(['state'=>$state,'code'=>'OFFLINE-code'],$browser),409,'uncertain exchange cannot retry same intent');
check($calls === 1 && count($stub->calls) === 1,'timeout has exactly one exchange attempt');
$stub->failure = false;
[$state,$hash,$session,$browser] = beginAttempt($service,$stub);
$stub->now += 601;
denied(fn()=>$service->callback(['state'=>$state,'code'=>'OFFLINE-code'],$browser),400,'expired state rejected before exchange');

[$pdo,$config,$stub,$token,$service,$meta] = fixture();
[$state,$hash,$session,$browser] = beginAttempt($service,$stub);
$pdo->exec('UPDATE users SET auth_version = 2 WHERE id = 1');
denied(fn()=>$service->callback(['state'=>$state,'code'=>'OFFLINE-code'],$browser),409,'changed auth version invalidates callback');
check(count($stub->calls) === 0,'invalidated session cannot exchange code');
$pdo->exec('UPDATE users SET auth_version = 1 WHERE id = 1');
$s = $pdo->prepare('INSERT INTO revoked_auth_tokens VALUES (?,?)'); $s->execute([$session,gmdate('Y-m-d H:i:s',$stub->now+3600)]);
denied(fn()=>$service->callback(['state'=>$state,'code'=>'OFFLINE-code'],$browser),409,'logged-out session invalidates callback');
$pdo->exec('DELETE FROM revoked_auth_tokens');
$pdo->exec('UPDATE users SET is_active = 0 WHERE id = 1');
denied(fn()=>$service->callback(['state'=>$state,'code'=>'OFFLINE-code'],$browser),409,'inactive owner cannot complete callback');

[$pdo,$config,$stub,$token,$service,$meta] = fixture();
$first = beginAttempt($service,$stub); $second = beginAttempt($service,$stub);
denied(fn()=>$service->callback(['state'=>$first[0],'code'=>'OFFLINE-code'],$first[3]),409,'restart invalidates older state');
$service->cancel(1,$second[2],$second[3]);
denied(fn()=>$service->callback(['state'=>$second[0],'code'=>'OFFLINE-code'],$second[3]),409,'cancel prevents stale callback');
for ($i=0;$i<3;$i++) beginAttempt($service,$stub);
denied(fn()=>beginAttempt($service,$stub),429,'connection starts rate limited per owner');
check(count($stub->calls) === 0,'starting/cancelling never calls Graph');

foreach (['wrong-app','user-token','expired','missing-scope','no-targets','different-targets','invalid'] as $case) {
    [$pdo,$config,$stub,$token,$service,$meta] = fixture();
    switch ($case) {
        case 'wrong-app': $stub->debug['data']['app_id']='555555555'; break;
        case 'user-token': $stub->debug['data']['type']='USER'; break;
        case 'expired': $stub->debug['data']['expires_at']=$stub->now-1; break;
        case 'missing-scope': $stub->debug['data']['scopes']=['whatsapp_business_management']; break;
        case 'no-targets': $stub->debug['data']['granular_scopes']=[]; break;
        case 'different-targets': $stub->debug['data']['granular_scopes'][1]['target_ids']=['444444444']; break;
        case 'invalid': $stub->debug['data']['is_valid']=false; break;
    }
    denied(fn()=>$meta->inspect($token,$stub->now),422,'reject invalid grant: '.$case);
}
[$pdo,$config,$stub,$token,$service,$meta] = fixture();
$attempt = authorize($service,$stub);
$stub->debug['data']['is_valid'] = false;
denied(fn()=>$service->confirm(1,$attempt[2],$attempt[3],$attempt[1],'222222222'),422,'revoked grant rechecked before saving');
check((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_customer_connections')->fetchColumn() === 0,'revoked grant creates no connection');
check($pdo->query('SELECT token_cipher FROM whatsapp_connect_intents')->fetchColumn() === null,'failed confirmation erases transient credentials');

[$pdo,$config,$stub,$token,$service,$meta] = fixture();
$attempt = authorize($service,$stub); $stub->now += 601;
check($service->status(1,$attempt[2],$attempt[3])['pending'] === null,'expired review no longer offered');
check($pdo->query('SELECT token_cipher FROM whatsapp_connect_intents')->fetchColumn() === null,'expired pending token scrubbed');
denied(fn()=>$service->confirm(1,$attempt[2],$attempt[3],$attempt[1],'222222222'),409,'expired review cannot save number');

[$pdo,$config,$stub,$token,$service,$meta] = fixture();
$result = $service->start(1,1,hash('sha256','fixture'),str_repeat('1',64),$stub->now+5);
check($result['expires_at'] === $stub->now+5,'intent cannot outlive initiating portal session');
denied(fn()=>$service->start(1,1,hash('sha256','fixture'),str_repeat('1',64),$stub->now-1),401,'expired initiating session rejected');
$stub->phones['paging'] = ['next'=>'https://evil.example/steal','cursors'=>['after'=>'fixture-cursor']];
denied(fn()=>$meta->discover($token,['111111111']),422,'repeated pagination cursor fails closed');
check(count(array_filter($stub->calls,fn($url)=>!str_starts_with($url,'https://graph.facebook.com/v25.0/'))) === 0,'pagination never follows untrusted next URLs');

[$pdo,$config,$stub,$token,$service,$meta] = fixture();
$attempt = authorize($service,$stub);
$stub->phones['data'][0]['id'] = '333333333';
denied(fn()=>$service->confirm(1,$attempt[2],$attempt[3],$attempt[1],'222222222'),409,'phone grants refreshed before confirmation');
check((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_customer_connections')->fetchColumn() === 0,'removed phone grant never saved');

[$pdo,$config,$stub,$token,$service,$meta] = fixture();
$attempt = authorize($service,$stub);
$service->confirm(1,$attempt[2],$attempt[3],$attempt[1],'222222222');
$stub->now += 5184001;
check($service->status(1,$attempt[2],$attempt[3])['connections'][0]['status'] === 'expired','expired business token requires reconnection');
$stub->debug['data']['expires_at'] = $stub->now + 3600;
$attempt = authorize($service,$stub);
$service->confirm(1,$attempt[2],$attempt[3],$attempt[1],'222222222');
check((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_customer_connections')->fetchColumn() === 1,'owner reconnect updates existing record without duplicates');
$attempt = authorize($service,$stub);
$service->disconnect(1,'222222222');
denied(fn()=>$service->confirm(1,$attempt[2],$attempt[3],$attempt[1],'222222222'),409,'disconnect invalidates pending reconnection');

[$pdo,$config,$stub,$token,$service,$meta] = fixture();
$attempt = beginAttempt($service,$stub);
$stub->onCall = static function(string $url) use ($pdo) {
    if (str_contains($url,'/phone_numbers')) $pdo->exec('UPDATE users SET auth_version = 2 WHERE id = 1');
};
denied(fn()=>$service->callback(['state'=>$attempt[0],'code'=>'OFFLINE-code'],$attempt[3]),409,'session changes during exchange checked before pending storage');
check($pdo->query('SELECT token_cipher FROM whatsapp_connect_intents')->fetchColumn() === null,'mid-exchange session invalidation leaves no stored credentials');

[$pdo,$config,$stub,$token,$service,$meta] = fixture();
$pdo->exec("UPDATE users SET role = 'admin' WHERE id = 1");
$attempt = beginAttempt($service,$stub);
check($service->callback(['state'=>$attempt[0],'code'=>'OFFLINE-code','return_to'=>'https://evil.example'],$attempt[3]) === '/guymhan/whatsapp','administrator signup returns to Guymhan using verified owner role');
echo 'PASS: '.$checks.' WhatsApp connection checks ('.(getenv('WHATSAPP_TEST_MYSQL_PORT') !== false ? 'disposable MySQL-compatible fixture' : 'SQLite fixture').'; no Meta messages or live DB).'.PHP_EOL;
