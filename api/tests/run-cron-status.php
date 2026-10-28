<?php
// Isolated controller regression: no database, network or campaigns are touched.
class Auth { public static string $role='admin'; public static function user(): array {return ['role'=>self::$role];} }
class CapturedResponse extends RuntimeException { public function __construct(public array $payload, public int $status=200) {parent::__construct('captured');} }
class Response {
    public static function success(array $data): void {throw new CapturedResponse($data);}
    public static function error(string $message,int $status): void {throw new CapturedResponse(['message'=>$message],$status);}
}
class FixtureQuery {public function fetchAll(): array {return FixtureDatabase::$rows;} }
class FixtureDatabase { public static array $rows=[]; public function query(string $sql): FixtureQuery {return new FixtureQuery();} }
function db(): FixtureDatabase {return new FixtureDatabase();}
require __DIR__.'/../controllers/CronController.php';
$checks=0;
function check(bool $pass,string $message): void {global $checks;if(!$pass)throw new RuntimeException($message);$checks++;}
foreach ([[], [['job_name'=>'sms-scheduler','last_seen_at'=>'2026-10-21 08:00:00','age_seconds'=>5]], [['job_name'=>'sms-worker','age_seconds'=>121],['job_name'=>'sms-scheduler','age_seconds'=>5]]] as $rows) {
    FixtureDatabase::$rows=$rows;
    try {(new CronController())->status();} catch(CapturedResponse $result) {
        check(count($result->payload['jobs'])===count($rows),'Every heartbeat is retained');
        foreach($result->payload['jobs'] as $i=>$job) {
            check(is_array($job)&&$job['job_name']===$rows[$i]['job_name'],'Last heartbeat is not replaced with null');
            check($job['status']===($job['job_name']==='sms-worker'?'stale':'healthy'),'Heartbeat health is preserved');
        }
    }
}
Auth::$role='user';
try {(new CronController())->status();throw new RuntimeException('Unauthorized status accepted');} catch(CapturedResponse $result) {check($result->status===403,'Scheduler access remains admin only');}
echo "PASS: $checks scheduler status checks; no external state changed.\n";
