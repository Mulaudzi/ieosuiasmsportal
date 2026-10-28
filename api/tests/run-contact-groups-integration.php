<?php
// Dedicated local fixture only. Never loads api/.env or production credentials.
if (PHP_SAPI !== 'cli' || getenv('CONTACT_GROUP_TEST_ENABLE') !== '1') {
    fwrite(STDERR, "Set CONTACT_GROUP_TEST_ENABLE=1 to use the isolated localhost test server.\n"); exit(1);
}
$port = (int)(getenv('CONTACT_GROUP_TEST_PORT') ?: 33148);
if ($port < 1024 || $port === 3306) { throw new RuntimeException('Use a dedicated non-default test port'); }
$name = 'sms_contact_groups_' . bin2hex(random_bytes(6)) . '_test';
if (!preg_match('/^sms_contact_groups_[a-f0-9]{12}_test$/D', $name)) { throw new RuntimeException('Unsafe fixture name'); }
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("CREATE DATABASE `$name`");
$pdo->exec("USE `$name`");
function db(): PDO { global $pdo; return $pdo; }
function env(string $key, mixed $default = null): mixed { return $default; }
class Auth { public static function id(): int { return 1; } }
class Request {
    public static array $data = [];
    public static array $query = [];
    public static array $files = [];
    public static function input(string $key, mixed $default = null): mixed { return self::$data[$key] ?? $default; }
    public static function query(string $key, mixed $default = null): mixed { return self::$query[$key] ?? $default; }
    public static function file(string $key): ?array { return self::$files[$key] ?? null; }
    public static function validate(array $rules): array { return array_intersect_key(self::$data, $rules); }
}
class Response {
    public static array $last = [];
    public static function success(array $data): void { self::$last = $data; }
    public static function error(string $message, int $status = 400): void { throw new RuntimeException("HTTP $status: $message"); }
}
require_once __DIR__ . '/../core/QueryBuilder.php';
require_once __DIR__ . '/../controllers/ContactController.php';
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
    echo "PASS $message\n";
}
function members(int $group): array {
    $q = db()->prepare('SELECT contact_id FROM group_contacts WHERE group_id=? ORDER BY contact_id');
    $q->execute([$group]); return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}
function rejects(callable $action, string $message): void {
    try { $action(); } catch (InvalidArgumentException|DomainException $e) { check(true, $message); return; }
    throw new RuntimeException("Did not reject: $message");
}
function change(array $data): array { return ContactGroupService::transfer(db(), 1, $data); }
$temp = null;
try {
    $pdo->exec("CREATE TABLE contacts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,name VARCHAR(100),surname VARCHAR(100),phone VARCHAR(50),phone_normalized VARCHAR(20),phone_original VARCHAR(50),email VARCHAR(255),country_code VARCHAR(10),subscription_status VARCHAR(30),subscribed_at DATETIME,created_at DATETIME,updated_at DATETIME,UNIQUE(user_id,phone_normalized)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE contact_groups (id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,name VARCHAR(100),description TEXT,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE group_contacts (group_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NOT NULL,created_at DATETIME,PRIMARY KEY(group_id,contact_id),FOREIGN KEY(group_id) REFERENCES contact_groups(id),FOREIGN KEY(contact_id) REFERENCES contacts(id)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE opt_outs (user_id BIGINT UNSIGNED,channel VARCHAR(10),recipient VARCHAR(20)) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO contact_groups(id,user_id,name) VALUES(1,1,'Source'),(2,1,'Destination'),(3,1,'Other'),(4,2,'Private'),(6,1,'Empty')");
    $insert = $pdo->prepare("INSERT INTO contacts(id,user_id,name,phone,phone_normalized,subscription_status,created_at) VALUES(?,?,?,?,?,?,NOW())");
    foreach ([[1,1,'Ada','+27820000001','subscribed'],[2,1,'Ben','+27820000002','unsubscribed'],[3,1,'Cara','+27820000003','subscribed'],[4,1,'Dana','+27820000004','subscribed'],[5,2,'Foreign','+27820000005','subscribed']] as [$id,$owner,$label,$phone,$status]) { $insert->execute([$id,$owner,$label,$phone,$phone,$status]); }
    $pdo->exec('INSERT INTO group_contacts(group_id,contact_id) VALUES(1,1),(1,2),(2,2),(2,3),(3,1),(4,5)');
    $pdo->exec("INSERT INTO opt_outs VALUES(1,'sms','+27820000002')");
    $originalContacts = $pdo->query('SELECT * FROM contacts ORDER BY id')->fetchAll();
    $controller = new ContactController();
    Request::$query = ['group_id'=>'1','search'=>'Cara']; $controller->index();
    check(Response::$last['data'] === [] && (int)Response::$last['meta']['total'] === 0, 'Search remains inside the current group');
    Request::$query = ['group_id'=>'uncategorized','search'=>'Dana']; $controller->index();
    check(count(Response::$last['data']) === 1, 'Uncategorized search remains scoped');
    $controller->groups(); check(Response::$last['total_contacts'] === 4, 'All Contacts counts unique owned contacts, not memberships');

    $result = change(['mode'=>'copy','source_group_id'=>'1','target_group_id'=>'2','ids'=>['1','2','2']]);
    check($result['added'] === 1 && $result['already_present'] === 1 && $result['matched'] === 2, 'Copy deduplicates IDs and overlapping group members');
    check(members(1) === [1,2] && members(2) === [1,2,3] && members(3) === [1], 'Copy keeps source and other memberships');
    $again = change(['mode'=>'copy','target_group_id'=>2,'ids'=>[1,2]]);
    check($again['added'] === 0 && $again['already_present'] === 2, 'Repeated copy cannot create duplicate memberships');
    foreach ([['target_group_id'=>4,'ids'=>[1]],['target_group_id'=>2,'ids'=>[1,5]],['target_group_id'=>2,'source_group_id'=>4,'ids'=>[1]]] as $invalid) {
        rejects(fn()=>change(['mode'=>'copy']+$invalid), 'Cross-account groups or contacts rejected atomically');
    }
    check(members(4) === [5], 'Other account remains unchanged');
    rejects(fn()=>change(['mode'=>'move','source_group_id'=>1,'target_group_id'=>2,'ids'=>[1,3]]), 'Moving contacts outside the chosen source is rejected');
    check(members(1) === [1,2], 'Invalid selection leaves all source memberships intact');
    foreach (['uncategorized', 'opted-out', 'all', 0, -1, '1e0', '1.1', '1x', true, []] as $invalidId) {
        rejects(fn()=>change(['mode'=>'copy','target_group_id'=>$invalidId,'ids'=>[1]]), 'Malformed or virtual target rejected');
    }
    rejects(fn()=>change(['mode'=>'copy','target_group_id'=>2,'ids'=>['1x']]), 'Malformed contact IDs are not silently cast');
    rejects(fn()=>change(['mode'=>'move','source_group_id'=>1,'target_group_id'=>1,'ids'=>[1]]), 'Source and destination cannot match');
    rejects(fn()=>change(['mode'=>'copy','target_group_id'=>2,'ids'=>[1],'delete_source'=>true]), 'Copy cannot delete source groups');
    check(ContactGroupService::duplicateKey(['id'=>20,'phone_normalized'=>null,'phone'=>'082 000 0001']) === ContactGroupService::duplicateKey(['id'=>1,'phone_normalized'=>'+27820000001','phone'=>'']), 'Duplicate checking normalizes local and international phone formats');
    $result = change(['mode'=>'move','source_group_id'=>1,'target_group_id'=>2,'ids'=>[1]]);
    check($result['already_present'] === 1 && $result['removed_from_source'] === 1 && members(3) === [1], 'Move removes only the specified source membership');
    $result = change(['mode'=>'merge','source_group_id'=>1,'target_group_id'=>2]);
    check($result['matched'] === 1 && $result['already_present'] === 1 && members(1) === [], 'Merge combines overlapping groups and keeps empty source by default');
    $result = change(['mode'=>'merge','source_group_id'=>3,'target_group_id'=>2,'delete_source'=>true]);
    check($result['source_deleted'] && (int)$pdo->query('SELECT COUNT(*) FROM contact_groups WHERE id=3')->fetchColumn() === 0, 'Explicit merge option removes only the empty source group');
    check($pdo->query('SELECT * FROM contacts ORDER BY id')->fetchAll() === $originalContacts, 'All contact details and subscription statuses are preserved');
    check((int)$pdo->query('SELECT COUNT(*) FROM opt_outs')->fetchColumn() === 1, 'Opt-out registry is preserved');

    $pdo->exec("CREATE TRIGGER fixture_reject BEFORE INSERT ON group_contacts FOR EACH ROW BEGIN IF NEW.group_id=6 AND NEW.contact_id=4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'; END IF; END");
    try { change(['mode'=>'copy','target_group_id'=>6,'ids'=>[1,4]]); throw new RuntimeException('Expected fixture SQL failure'); }
    catch (PDOException) { check(members(6) === [] && !$pdo->inTransaction(), 'An insertion failure rolls back the entire operation'); }
    $pdo->exec('DROP TRIGGER fixture_reject');

    $temp = tempnam(sys_get_temp_dir(), 'sms-contact-fixture-');
    file_put_contents($temp, "Name,Phone,Email\nDo not overwrite,0820000002,changed@example.invalid\nRepeat,27820000002,\nNew person,0820000006,new@example.invalid\nBad,not-a-phone,\n");
    Request::$files = ['file'=>['tmp_name'=>$temp,'name'=>'contacts.csv','size'=>filesize($temp),'error'=>UPLOAD_ERR_OK]];
    Request::$data = ['group_id'=>'6','skip_duplicates'=>'true','column_mapping'=>json_encode(['name'=>'Name','phone'=>'Phone','email'=>'Email'])];
    $controller->import(); $result = Response::$last;
    check($result['imported'] === 1 && $result['duplicates'] === 2 && $result['failed'] === 1 && $result['existing_added_to_group'] === 1, 'Import accurately reports new, reused, repeated and invalid contacts');
    check(count(members(6)) === 2 && in_array(2, members(6), true), 'Existing contacts join the import destination without duplicate records');
    check($pdo->query('SELECT * FROM contacts WHERE id<=5 ORDER BY id')->fetchAll() === $originalContacts, 'Import does not overwrite existing names, details or opt-outs');
    $controller->import();
    check(Response::$last['imported'] === 0 && Response::$last['existing_added_to_group'] === 0 && count(members(6)) === 2, 'Repeated import does not duplicate contacts or memberships');

    file_put_contents($temp, "Name,Phone\nNew,0820000008\nKeep Ben,820000002\nPreviously opted out,820000007\nReject,bad\nRepeat new,27820000008\n");
    Request::$files['file']['size'] = filesize($temp);
    Request::$data = ['group_id'=>'6','column_mapping'=>json_encode(['name'=>'Name','phone'=>'Phone']), 'phone_review_version'=>'1', 'phone_review_decisions'=>'[]'];
    $beforeReview = $pdo->query('SELECT * FROM contacts ORDER BY id')->fetchAll();
    try { $controller->import(); throw new LogicException('Expected review rejection'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Review the phone number'), 'Unresolved reviews are rejected by the server'); }
    check($pdo->query('SELECT * FROM contacts ORDER BY id')->fetchAll() === $beforeReview, 'A later unresolved row prevents even earlier valid rows being saved');
    $pdo->exec("INSERT INTO opt_outs VALUES(1,'sms','+27820000007')");
    Request::$data['phone_review_decisions'] = json_encode([
        ['row'=>3,'original'=>'820000002','action'=>'accept','value'=>'0820000002'],
        ['row'=>4,'original'=>'820000007','action'=>'accept','value'=>'0820000007'],
        ['row'=>5,'original'=>'bad','action'=>'reject'],
    ]);
    $controller->import();
    check(Response::$last['imported'] === 2 && Response::$last['duplicates'] === 2 && Response::$last['failed'] === 0 && Response::$last['corrected'] === 2 && Response::$last['rejected'] === 1, 'Reviewed import reports new, duplicate, corrected and rejected rows accurately');
    check(count(members(6)) === 4, 'Corrected duplicates do not create extra contacts or memberships');
    check($pdo->query('SELECT * FROM contacts WHERE id<=5 ORDER BY id')->fetchAll() === $originalContacts, 'Reconstruction leaves existing contact details and opt-out flags unchanged');
    check($pdo->query("SELECT subscription_status FROM contacts WHERE phone_normalized='+27820000007'")->fetchColumn() === 'unsubscribed', 'A corrected new contact respects the existing opt-out registry');
    check($pdo->query("SELECT phone_original FROM contacts WHERE phone_normalized='+27820000007'")->fetchColumn() === '820000007', 'Original source number is retained for an accepted correction');
    $controller->import();
    check(Response::$last['imported'] === 0 && Response::$last['duplicates'] === 4 && count(members(6)) === 4, 'Repeating approved corrections cannot duplicate contacts');

    $pdo->exec("INSERT INTO contact_groups(id,user_id,name) VALUES(7,1,'Large source')");
    $bulk = $pdo->prepare("INSERT INTO contacts(user_id,name,phone,phone_normalized,subscription_status,created_at) VALUES(1,'Bulk',?,?,'subscribed',NOW())");
    $membership = $pdo->prepare('INSERT INTO group_contacts(group_id,contact_id) VALUES(7,?)');
    for ($i=100;$i<175;$i++) { $phone = '+2782000'.$i; $bulk->execute([$phone,$phone]); $membership->execute([$pdo->lastInsertId()]); }
    $result = change(['mode'=>'merge','source_group_id'=>7,'target_group_id'=>2]);
    check($result['matched'] === 75 && $result['added'] === 75 && members(7) === [], 'Merge includes all contacts, beyond a 50-row page');
    $beforeMemberships = $pdo->query('SELECT * FROM group_contacts ORDER BY group_id,contact_id')->fetchAll();
    Request::$data = ['name'=>'Ben updated'];
    $controller->update(['id'=>2]);
    check($pdo->query('SELECT * FROM group_contacts ORDER BY group_id,contact_id')->fetchAll() === $beforeMemberships, 'Ordinary contact edits preserve all copied memberships');
    check($pdo->query('SELECT subscription_status FROM contacts WHERE id=2')->fetchColumn() === 'unsubscribed', 'Editing a copied contact does not clear its opt-out');
    echo "$checks contact group integration checks passed. No production data used.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($temp !== null && is_file($temp)) unlink($temp);
    $pdo->exec("DROP DATABASE `$name`");
}
