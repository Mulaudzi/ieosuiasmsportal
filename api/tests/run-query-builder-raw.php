<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO {return $GLOBALS['pdo'];}
require_once __DIR__.'/../core/QueryBuilder.php';
$pdo->exec('CREATE TABLE contact_email_logs(id INTEGER PRIMARY KEY,created_at TEXT,purpose TEXT)');
$insert=$pdo->prepare('INSERT INTO contact_email_logs VALUES(?,?,?)');
$insert->execute([1,'2026-10-19 23:59:59','support']);
$insert->execute([2,'2026-10-20 00:00:00','support']);
$insert->execute([3,'2026-10-20 23:59:59','sales']);
$insert->execute([4,'2026-10-21 00:00:00','support']);
function same($want,$got):void {if($want!==$got)throw new RuntimeException('Assertion failed: '.json_encode([$want,$got]));}
same(2,(new QueryBuilder('contact_email_logs'))->whereRaw('DATE(created_at) = ?', ['2026-10-20'])->count());
same(1,(new QueryBuilder('contact_email_logs'))->where('purpose','support')->whereRaw('DATE(created_at) >= ?', ['2026-10-20'])->whereRaw('DATE(created_at) <= ?', ['2026-10-20'])->count());
same(1,count((new QueryBuilder('contact_email_logs'))->whereRaw('DATE(created_at) >= ?', ['2026-10-20'])->whereRaw('DATE(created_at) <= ?', ['2026-10-20'])->where('purpose','sales')->get()));
same(0,(new QueryBuilder('contact_email_logs'))->whereRaw('DATE(created_at) = ?', ["2026-10-20' OR 1=1 --"])->count());
same(3,(new QueryBuilder('contact_email_logs'))->whereRaw('1=1')->where('purpose','support')->count());
same(1,(new QueryBuilder('contact_email_logs'))->where('purpose','support')->whereRaw('id=? OR id=?',[1,3])->count());
try {(new QueryBuilder('contact_email_logs'))->whereRaw(' ');throw new RuntimeException('Expected empty expression rejection');}catch(InvalidArgumentException){}
same(4,(new QueryBuilder('contact_email_logs'))->count());
echo "PASS: 8 query-builder assertions; SQLite fixtures only, no production access.\n";
