<?php
// Isolated fixtures only. No application bootstrap or production connection.
require_once __DIR__.'/../services/CampaignComparisonService.php';
final class ComparisonFixturePDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        // SQLite lacks MySQL's SECOND keyword. Keep the aggregation query intact.
        return parent::prepare(str_replace('TIMESTAMPDIFF(SECOND,', "TIMESTAMPDIFF('SECOND',", $query), $options);
    }
}
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $action, string $exception): void {
    try { $action(); } catch (Throwable $error) { check($error instanceof $exception, 'Wrong exception: '.get_class($error)); return; }
    throw new RuntimeException('Expected '.$exception);
}
$pdo = new ComparisonFixturePDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('TIMESTAMPDIFF', fn($unit, $start, $end) => strtotime($end) - strtotime($start), 3);
$pdo->exec('CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, created_at TEXT, actual_cost REAL)');
$pdo->exec('CREATE TABLE sms_messages(id INTEGER PRIMARY KEY, campaign_id INTEGER, user_id INTEGER, state TEXT, sent_at TEXT, delivered_at TEXT, actual_charge REAL, last_error TEXT)');
$pdo->exec("INSERT INTO sms_campaigns VALUES
    (1,7,'Alpha','2026-01-01',999), (2,7,'Beta','2026-01-02',999), (3,7,'Draft','2026-01-03',999),
    (4,7,'Bad time','2026-01-04',999), (5,7,'Immediate','2026-01-05',999), (9,8,'Other account','2026-01-06',999)");
$insert = $pdo->prepare('INSERT INTO sms_messages(campaign_id,user_id,state,sent_at,delivered_at,actual_charge,last_error) VALUES(?,?,?,?,?,?,?)');
$sent = '2026-01-01 10:00:00';
foreach ([
    [1,7,'delivered',$sent,'2026-01-01 10:01:00',.35,null],
    [1,7,'delivered',$sent,'2026-01-01 10:03:00',.70,null],
    [1,7,'failed',$sent,null,.35,'Network failure'],
    [1,7,'sent',$sent,null,.35,'DLR lookup: Cannot Find Message'],
    [1,7,'sent',null,null,.35,null],
    [1,7,'pending',null,null,0,null],
    [1,7,'processing',null,null,0,null],
    [1,7,'failed',null,null,0,'Rejected before submission'],
    [1,7,'skipped',null,null,0,'Opted out'],
    [1,8,'delivered',$sent,'2026-01-01 10:01:00',50,null],
    [2,7,'sent',$sent,null,.35,null],
    [4,7,'delivered',$sent,'2026-01-01 09:00:00',.35,null],
    [5,7,'delivered',$sent,$sent,.35,null],
    [9,8,'delivered',$sent,'2026-01-01 10:01:00',.35,null],
] as $row) $insert->execute($row);
$before = $pdo->query('SELECT * FROM sms_messages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

check(CampaignComparisonService::ids('2,1') === [2,1], 'Selection order lost');
check(count(CampaignComparisonService::ids('1,2,3,4,5')) === 5, 'Maximum selection rejected');
foreach (['', '1', '1,1', '1,2,3,4,5,9', '0,2', '-1,2', '1,2x', '1,2,', '1, 2', '1,999999999999999999999999', '1,2 OR 1=1'] as $invalid) {
    rejects(fn() => CampaignComparisonService::ids($invalid), InvalidArgumentException::class);
}
$all = CampaignComparisonService::campaigns($pdo, 7);
check(count($all) === 5, 'Picker leaked another account or excluded draft');
check(CampaignComparisonService::campaigns($pdo, 99) === [], 'Empty account data leak');
rejects(fn() => CampaignComparisonService::campaigns($pdo, 7, [1,9]), OutOfBoundsException::class);
rejects(fn() => CampaignComparisonService::campaigns($pdo, 7, [1,99]), OutOfBoundsException::class);
$rows = CampaignComparisonService::campaigns($pdo, 7, [2,1,3,4,5]);
check(array_column($rows, 'id') === ['2','1','3','4','5'], 'Comparison did not preserve requested IDs');
$alpha = $rows[1];
foreach (['sent_count'=>5, 'delivered_count'=>2, 'failed_count'=>1, 'pending_count'=>2] as $key=>$value) check($alpha[$key] === $value, $key.' incorrect');
check(abs($alpha['actual_cost'] - 2.10) < 0.00001, 'Cost not based on owned message charges');
check(abs($alpha['cost_per_delivery'] - 1.05) < 0.00001, 'Cost per delivery incorrect');
check($alpha['delivery_rate'] === 40.0, 'Sent denominator incorrect');
check($alpha['avg_delivery_time_seconds'] === 120.0, 'Confirmed average incorrect');
check($rows[0]['delivery_rate'] === 0.0 && $rows[0]['failed_count'] === 0 && $rows[0]['pending_count'] === 1, 'Pending-only campaign treated as not delivered');
check($rows[0]['avg_delivery_time_seconds'] === null && $rows[0]['cost_per_delivery'] === null, 'No-delivery campaign must not have time/value');
check($rows[2]['delivery_rate'] === null && $rows[2]['sent_count'] === 0, 'Draft is not a sent campaign');
check($rows[3]['avg_delivery_time_seconds'] === null, 'Negative timestamp interval must not win');
check($rows[4]['avg_delivery_time_seconds'] === 0.0, 'Valid immediate confirmation lost');
foreach ($rows as $row) check($row['sent_count'] === $row['delivered_count'] + $row['failed_count'] + $row['pending_count'], 'Breakdown does not sum to sent');
check($before === $pdo->query('SELECT * FROM sms_messages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Comparison mutated message records');
$pdo->exec("UPDATE sms_messages SET state='delivered',delivered_at='2026-01-01 10:02:00' WHERE campaign_id=2");
$updated = CampaignComparisonService::campaigns($pdo, 7, [2,1])[0];
check($updated['delivered_count'] === 1 && $updated['pending_count'] === 0 && $updated['delivery_rate'] === 100.0, 'Refresh did not read current delivery records');
echo "PASS: selection bounds, ownership, sent/pending identity, real charges, confirmed times, nulls, no writes, refreshed outcomes\n";
