<?php
// Pure projections and real read-only queue SQL against an isolated SQLite fixture.
require_once __DIR__.'/../domain/AdminSmsSummary.php';
$checks=0;
function checkSummary(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$credits=AdminSmsSummary::credits(['balance'=>'170.1000','reserved'=>'1.4000'],.35);
checkSummary($credits['sms_credits_total']===486&&$credits['sms_credits_reserved']===4&&$credits['sms_credits_available']===482,'Available credits subtract reservations, not raw wallet rand');
checkSummary(AdminSmsSummary::credits(['balance'=>.3499,'reserved'=>0],.35)['sms_credits_available']===0,'Do not round fractional credit up');
checkSummary(AdminSmsSummary::credits(['balance'=>.7,'reserved'=>.35],.35)['sms_credits_available']===1,'Decimal arithmetic boundary');
checkSummary(AdminSmsSummary::credits(['balance'=>0,'reserved'=>.35],.35)['sms_credits_available']===0,'Clamp negative availability');
checkSummary(AdminSmsSummary::credits([], .35)['sms_credits_total']===0,'Missing wallet has zero credits');
foreach([0,-1,INF,NAN] as $invalid){try{AdminSmsSummary::credits([], $invalid);throw new RuntimeException('Invalid price accepted');}catch(InvalidArgumentException){$checks++;}}
checkSummary(AdminSmsSummary::customers([['id'=>5,'balance'=>.7,'reserved'=>.35]],.35)[0]['id']===5,'Projection preserves original customer fields');
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,state TEXT);CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,campaign_id INTEGER,user_id INTEGER,state TEXT,next_attempt_at TEXT,sent_at TEXT,actual_charge REAL,last_error TEXT)');
$pdo->exec("INSERT INTO sms_campaigns VALUES(1,7,'queued'),(2,8,'processing'),(3,7,'scheduled'),(4,7,'completed'),(5,7,'cancelled')");
$insert=$pdo->prepare('INSERT INTO sms_messages VALUES(?,?,?,?,?,?,?,?)');
foreach([
    [1,1,7,'pending',null,null,0,null], [2,1,7,'retry','2026-10-24 12:00:00',null,0,null],
    [3,2,8,'processing','9999-12-31 23:59:59',null,0,'Submission started'],
    [4,2,8,'retry','9999-12-31 23:59:59',null,0,'Hold'],
    [5,2,8,'sent',null,'2026-10-23 20:00:00',.35,null],
    [6,2,8,'failed',null,'2026-10-23 20:00:00',.35,'Network non-delivery'],
    [7,2,8,'skipped',null,null,0,null], [8,3,7,'pending',null,null,0,null],
    [9,4,7,'pending',null,null,0,null], [10,5,7,'pending',null,null,0,null],
    [11,1,99,'pending',null,null,0,null], [12,2,8,'retry',null,'2026-10-23 20:00:00',.35,null],
] as $row)$insert->execute($row);
$before=$pdo->query('SELECT * FROM sms_messages ORDER BY id')->fetchAll();
$summary=AdminSmsSummary::dispatch($pdo);
checkSummary($summary['jobs']===['active'=>2,'queued'=>1,'processing'=>1],'Jobs are campaigns, not message count');
checkSummary($summary['messages']===['waiting'=>2,'processing'=>1,'held'=>1],'Queue separates waiting, in-flight and held; excludes scheduled, terminal, accepted, foreign-tenant join');
checkSummary($before===$pdo->query('SELECT * FROM sms_messages ORDER BY id')->fetchAll(),'Summary is read-only');
$pdo->exec("UPDATE sms_campaigns SET state='completed'");$summary=AdminSmsSummary::dispatch($pdo);
checkSummary($summary['jobs']===['active'=>0,'queued'=>0,'processing'=>0]&&$summary['messages']===['waiting'=>0,'processing'=>0,'held'=>0],'Empty queue returns integer zeroes');
echo "PASS $checks admin credit and dispatch-summary checks; isolated fixtures only.\n";
