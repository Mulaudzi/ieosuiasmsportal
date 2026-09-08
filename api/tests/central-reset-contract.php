<?php
// Isolated handler tests: no production bootstrap, database, email or credentials.
declare(strict_types=1);
final class Rendered extends RuntimeException {}
final class Security {
    public static array $events=[];
    public static function requireCsrf():void {}
    public static function normalizeEmail(string $email):string {return strtolower(trim($email));}
    public static function opaqueToken(int $length):string {return str_repeat('x',$length);}
    public static function hashToken(string $token):string {return hash('sha256',$token);}
    public static function audit($type,$uuid,$event):void {self::$events[]=$event;}
}
final class TestStatement {
    public function __construct(private string $sql) {}
    public function execute(array $params):void {Database::$queries[]=$this->sql;}
    public function fetch():array|false {return Database::$account?['uuid'=>'test','name'=>'Test','email'=>'test@example.invalid']:false;}
}
final class Database {
    public static bool $account=true;
    public static array $queries=[];
    public static function connection():self {return new self;}
    public function prepare(string $sql):TestStatement {return new TestStatement($sql);}
}
final class Mailer {
    public static bool $accepted=true;
    public static int $calls=0;
    public static function send(...$args):bool {self::$calls++;return self::$accepted;}
}
function rateLimit(...$args):bool {return true;}
function url(string $path):string {return 'https://example.invalid'.$path;}
function renderForgotPassword(?string $error=null,bool $sent=false):never {throw new Rendered($sent?'received':'error');}
$source=file_get_contents($argv[1]??'');
if(!preg_match('/function handleForgotPassword\(\): never\s*\{.*?\n\}/s',$source,$match))throw new RuntimeException('Handler missing');
eval($match[0]);
foreach([[true,true],[true,false],[false,true]] as [$account,$accepted]) {
    Database::$account=$account;Database::$queries=[];Security::$events=[];Mailer::$calls=0;Mailer::$accepted=$accepted;
    $_POST=['email'=>'test@example.invalid'];
    try {handleForgotPassword();} catch(Rendered $result) {if($result->getMessage()!=='received')throw new RuntimeException('Response differs');}
    if(Mailer::$calls!==($account?1:0))throw new RuntimeException('Wrong send count');
    $expected=$account?[$accepted?'password_reset_email_accepted':'password_reset_email_failed']:[];
    if(Security::$events!==$expected)throw new RuntimeException('Wrong audit result');
    $deletes=array_values(array_filter(Database::$queries,fn($sql)=>str_starts_with($sql,'DELETE')));
    if(count($deletes)!==($account&&!$accepted?1:0))throw new RuntimeException('Previous links removed');
    if($deletes&&!str_contains($deletes[0],'token_hash=?'))throw new RuntimeException('Failure cleanup is not token-scoped');
    echo 'PASS '.($account?($accepted?'SMTP accepted':'SMTP rejected'):'unknown account').PHP_EOL;
}
