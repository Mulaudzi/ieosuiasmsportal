<?php
declare(strict_types=1);
require __DIR__.'/../services/PublicServiceReadiness.php';
$n=0;
function readinessCheck(bool $ok,string $label):void{global$n;$n++;if(!$ok)throw new RuntimeException($label);}
$now=(new DateTimeImmutable('2026-11-05T09:00:00Z'))->getTimestamp();
$required=['sms-worker'=>120,'sms-scheduler'=>180,'sms-dlr-poller'=>300];
$rows=[];
foreach($required as$name=>$limit)$rows[]=['service_name'=>$name,'last_seen_at'=>'2026-11-05 10:59:50','instance_id'=>'private-host','metadata_json'=>json_encode(['campaign_id'=>62,'customer'=>'private-customer','token'=>'never-public']),'metadata'=>['reference'=>'private-reference']];
$r=PublicServiceReadiness::report($rows,$required,$now);
readinessCheck($r['status']==='ready'&&$r['database']===true,'Healthy public contract preserved');
foreach($r['services']as$row){readinessCheck($row['healthy']&&$row['age_seconds']===10,'Age uses South African timestamps');readinessCheck(array_keys($row)===['healthy','last_seen_at','age_seconds','max_age_seconds'],'Only explicitly allowlisted health fields are public');}
foreach(['private-host','private-customer','never-public','campaign_id','metadata','private-reference','metrics']as$private)readinessCheck(!str_contains(json_encode($r),$private),'Private worker data omitted');
$r=PublicServiceReadiness::report(array_slice($rows,1),$required,$now);readinessCheck($r['status']==='not_ready'&&!$r['services']['sms-worker']['healthy'],'Missing heartbeat fails readiness');
$rows[0]['last_seen_at']='not-a-date';$r=PublicServiceReadiness::report($rows,$required,$now);readinessCheck($r['status']==='not_ready'&&$r['services']['sms-worker']['age_seconds']===null,'Malformed heartbeat fails readiness safely');
$rows[0]['last_seen_at']='2026-11-05 10:58:00';$r=PublicServiceReadiness::report($rows,$required,$now);readinessCheck($r['services']['sms-worker']['healthy'],'Threshold boundary is healthy');
$r=PublicServiceReadiness::report($rows,$required,$now+1);readinessCheck(!$r['services']['sms-worker']['healthy'],'Stale heartbeat fails readiness');
// Execute the real route closure without bootstrapping credentials or a live DB.
class ReadinessQuery{public function get():array{return $GLOBALS['rows'];}}
class ReadinessDatabase{public function query(string $sql):array{if($sql!=='SELECT 1')throw new RuntimeException('Unexpected query');return [];}}
function table(string $name):ReadinessQuery{if($name!=='service_heartbeats')throw new RuntimeException('Unexpected table');return new ReadinessQuery();}
function db():ReadinessDatabase{return new ReadinessDatabase();}
function env(string $key,mixed $default=null):mixed{return $default;}
class Response{public static array $payload;public static int $status;public static function success(array $data,int $status):void{self::$payload=$data;self::$status=$status;}}
class ReadinessRouter{public mixed $callback;public function get(string $path,callable $callback):void{if($path!=='/ready')throw new RuntimeException('Unexpected route');$this->callback=$callback;}}
$source=file_get_contents(__DIR__.'/../index.php');$start=strpos($source,"\$router->get('/ready', function() {");$end=strpos($source,"\n});",$start);
readinessCheck($start!==false&&$end!==false,'Real readiness route identified');
$route=substr($source,$start,$end+4-$start);$route=str_replace("__DIR__.'/services/PublicServiceReadiness.php'",var_export(realpath(__DIR__.'/../services/PublicServiceReadiness.php'),true),$route);
$rows[0]['last_seen_at']=date('Y-m-d H:i:s',time()-10);date_default_timezone_set('Africa/Johannesburg');foreach($rows as &$row)$row['last_seen_at']=date('Y-m-d H:i:s',time()-10);unset($row);
$router=new ReadinessRouter();eval($route);($router->callback)();
readinessCheck(Response::$status===200&&Response::$payload['status']==='ready','Actual public route retains successful HTTP status');
readinessCheck(!str_contains(json_encode(Response::$payload),'never-public')&&!isset(Response::$payload['services']['sms-worker']['metrics']),'Actual public response does not leak private metrics');
$rows=[];($router->callback)();readinessCheck(Response::$status===503,'Actual missing-service route retains HTTP 503');
echo "PASS: $n public readiness privacy and route checks; no live services.\n";
