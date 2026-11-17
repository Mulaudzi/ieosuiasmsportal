<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../domain/SmsAdminProjection.php';
require __DIR__.'/../domain/CreditApprovalSecurity.php';
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
const OWNER_UUID = '11111111-1111-4111-8111-111111111111';
const OTHER_UUID = '22222222-2222-4222-8222-222222222222';
function fixture(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,identity_uuid TEXT UNIQUE,role TEXT,is_active INTEGER,email_verified_at TEXT,auth_version INTEGER,last_login_at TEXT,updated_at TEXT);
        CREATE TABLE admin_users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,identity_uuid TEXT UNIQUE,is_active INTEGER,last_login_at TEXT,updated_at TEXT);
        CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER,balance REAL);
        INSERT INTO users VALUES(14,'lufuno@ieosuia.com',NULL,'admin',0,'2026-01-01',3,NULL,'old');
        INSERT INTO users VALUES(2,'customer@example.invalid',NULL,'user',1,'2026-01-01',1,NULL,'old');
        INSERT INTO admin_users VALUES(1,'lufuno@ieosuia.com','".OWNER_UUID."',1,NULL,'old');
        INSERT INTO wallets VALUES(1,14,123),(2,2,99)");
    $pdo->exec(file_get_contents(__DIR__.'/fixtures/credit-approval-security.sql'));
    return $pdo;
}
function profile(): array { return ['sub'=>OWNER_UUID,'email'=>'lufuno@ieosuia.com','email_verified'=>true,'account_type'=>'admin','application'=>'sms']; }
function snapshot(PDO $pdo): string { return json_encode([$pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM admin_users ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll()]); }
function denied(PDO $pdo, array $profile, string $reason): void {
    $before=snapshot($pdo);
    try { SmsAdminProjection::synchronize($pdo,$profile); throw new RuntimeException('Expected rejection'); }
    catch (SmsAdminProjectionException $error) { check($error->getMessage()===$reason,'Expected '.$reason); }
    check(snapshot($pdo)===$before,'Rejected identity cannot change records');
    check(!$pdo->inTransaction(),'Rejected identity leaves no transaction');
}
$pdo=fixture();$wallets=$pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll();$customer=$pdo->query('SELECT * FROM users WHERE id=2')->fetch();
$owner=SmsAdminProjection::synchronize($pdo,profile());
check((int)$owner['id']===14,'Existing owner ID retained, not duplicated');
check($owner['identity_uuid']===OWNER_UUID && (int)$owner['is_active']===1,'Existing approved owner projection linked and active');
check((int)$owner['auth_version']===4,'Old local tokens revoked on binding repair');
check(CreditApprovalSecurity::status($pdo,$owner)['is_owner'],'Repaired owner receives password management');
check(!CreditApprovalSecurity::status($pdo,$customer)['is_owner'],'Customer cannot manage master password');
check($pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll()===$wallets,'Balances untouched');
check($pdo->query('SELECT * FROM users WHERE id=2')->fetch()===$customer,'Other users untouched');
check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===2,'No new account created');
$again=SmsAdminProjection::synchronize($pdo,profile());check((int)$again['auth_version']===4,'Stable re-login does not revoke unrelated sessions again');
check($pdo->query('SELECT password_hash FROM credit_approval_security')->fetchColumn()===null,'Repair does not create a master password');
$pass='fixture-owner-master-password-123';CreditApprovalSecurity::configure($pdo,$again,null,$pass,$pass);
check(CreditApprovalSecurity::status($pdo,$again)['configured'],'Repaired owner can set password');
$ordinary=$again;$ordinary['id']=2;$ordinary['email']='admin@example.invalid';
try { CreditApprovalSecurity::configure($pdo,$ordinary,$pass,$pass.'new',$pass.'new');throw new RuntimeException('Ordinary admin configured secret'); }
catch (CreditApprovalException $error) { check($error->status===403,'Ordinary admin cannot change owner password'); }
check(CreditApprovalSecurity::verify($pdo,2,$pass)===1,'Ordinary admin can verify master for approvals');
CreditApprovalSecurity::configure($pdo,$again,$pass,$pass.'new',$pass.'new');
check(CreditApprovalSecurity::verify($pdo,14,$pass.'new')===2,'Owner can rotate password');
foreach ([['account_type'=>'customer'],['application'=>'invoice'],['email_verified'=>false],['email_verified'=>'false'],['sub'=>''],['sub'=>'not-a-uuid'],['email'=>'invalid']] as $bad) denied(fixture(),array_replace(profile(),$bad),'identity_not_allowed');
$pdo=fixture();$pdo->exec('UPDATE admin_users SET is_active=0');denied($pdo,profile(),'local_access_missing');
$pdo=fixture();$pdo->exec("UPDATE admin_users SET identity_uuid='".OTHER_UUID."'");denied($pdo,profile(),'identity_conflict');
$pdo=fixture();$pdo->exec("UPDATE users SET identity_uuid='".OTHER_UUID."' WHERE id=14");denied($pdo,profile(),'identity_conflict');
$pdo=fixture();$pdo->exec("UPDATE users SET identity_uuid='".OWNER_UUID."' WHERE id=2");denied($pdo,profile(),'identity_conflict');
$pdo=fixture();$pdo->exec("UPDATE admin_users SET email='other@example.invalid'");denied($pdo,profile(),'identity_conflict');
$pdo=fixture();$pdo->exec('DELETE FROM users WHERE id=14');denied($pdo,profile(),'admin_projection_missing');
$pdo=fixture();$pdo->exec('DELETE FROM admin_users');denied($pdo,profile(),'local_access_missing');
$pdo=fixture();$pdo->exec('UPDATE admin_users SET identity_uuid=NULL');$owner=SmsAdminProjection::synchronize($pdo,profile());
check($pdo->query('SELECT identity_uuid FROM admin_users')->fetchColumn()===OWNER_UUID,'Approved unbound admin links once');
$pdo=fixture();$before=snapshot($pdo);$pdo->beginTransaction();SmsAdminProjection::synchronize($pdo,profile());check($pdo->inTransaction(),'Caller retains its repair transaction');$pdo->rollBack();check(snapshot($pdo)===$before,'Caller rollback restores both local projections');
$pdo=fixture();$owner=SmsAdminProjection::synchronize($pdo,profile(),false);check($owner['last_login_at']===null,'Maintenance does not manufacture an owner login');check($pdo->query('SELECT last_login_at FROM admin_users')->fetchColumn()===null,'Maintenance preserves administrator login history');
echo 'PASS: '.$checks.' SMS admin projection, identity conflict and owner-control checks (isolated SQLite).'.PHP_EOL;
