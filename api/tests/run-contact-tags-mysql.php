<?php
declare(strict_types=1);
// Disposable loopback database only. Never loads api/.env or production config.
if(PHP_SAPI!=='cli'||getenv('CONTACT_TAGS_MYSQL_TEST_ENABLE')!=='1')exit("Set CONTACT_TAGS_MYSQL_TEST_ENABLE=1 for an isolated fixture.\n");
$port=(int)(getenv('CONTACT_TAGS_MYSQL_TEST_PORT')?:33160);if($port<1024||$port===3306)throw new RuntimeException('Use a dedicated non-default port.');
$release=getenv('CONTACT_TAGS_SETUP_RELEASE')?:'.sms-audit.local/contact-tags-setup-20261029-v2';
if(!preg_match('~^\.sms-audit\.local/contact-tags-setup-[a-z0-9-]+$~D',$release))throw new RuntimeException('Use the private local setup fixture');
$candidate=dirname(__DIR__,2).'/'.$release.'/runner.php';
define('CONTACT_TAGS_SETUP_TEST_MODE',true);require $candidate;require __DIR__.'/../domain/ContactTagService.php';
$name='sms_contact_tags_'.bin2hex(random_bytes(6)).'_test';if(!preg_match('/^sms_contact_tags_[a-f0-9]{12}_test$/D',$name))throw new RuntimeException('Invalid fixture name');
$pdo=new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$checks=0;function mysqlTagCheck(bool $v,string $label):void{global $checks;if(!$v)throw new RuntimeException($label);$checks++;}
$created=false;
try{
    $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$created=true;$pdo->exec("USE `{$name}`");
    $pdo->exec('CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB; INSERT INTO users VALUES(1),(2)');
    $pdo->exec("CREATE TABLE contacts(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,name VARCHAR(100),phone VARCHAR(50),phone_normalized VARCHAR(50),email VARCHAR(255),created_at DATETIME) ENGINE=InnoDB;INSERT INTO contacts VALUES(1,1,'First','+27820000001','+27820000001',NULL,NOW()),(2,1,'Second','+27820000002','+27820000002',NULL,NOW()),(3,2,'Private','+27820000003','+27820000003',NULL,NOW())");
    $pdo->exec('CREATE TABLE schema_migrations(version VARCHAR(100) PRIMARY KEY,applied_at DATETIME NOT NULL) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE contact_groups(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,name VARCHAR(100)) ENGINE=InnoDB;INSERT INTO contact_groups VALUES(1,1,'First group'),(2,1,'Second group');CREATE TABLE group_contacts(group_id BIGINT UNSIGNED,contact_id BIGINT UNSIGNED) ENGINE=InnoDB;INSERT INTO group_contacts VALUES(1,1),(1,2),(2,2)");
    $pdo->exec('CREATE TABLE opt_outs(user_id BIGINT UNSIGNED,channel VARCHAR(10),recipient VARCHAR(50)) ENGINE=InnoDB');
    $migrationFile=__DIR__.'/../migrations/020_contact_tags.php';$draftFile=__DIR__.'/../migrations/016_work_drafts.php';$runner=new ContactTagsSetup($pdo,$migrationFile,hash_file('sha256',$migrationFile),$draftFile,hash_file('sha256',$draftFile));
    $before=$runner->inspect();mysqlTagCheck($before['ready_to_apply']&&$before['draft_prerequisite_needed']&&!$before['tables']['work_drafts']['exists'],'Missing saved-draft prerequisite is declared and allowlisted');
    $before=$runner->inspect();mysqlTagCheck($before['ready_to_apply']&&!$before['migration_recorded'],'Preflight passes on compatible MariaDB schema');
    $pdo->exec('CREATE TABLE contact_tags(id INT PRIMARY KEY) ENGINE=InnoDB');mysqlTagCheck(!$runner->inspect()['ready_to_apply'],'Conflicting pre-existing tag table fails closed');$pdo->exec('DROP TABLE contact_tags');
    $contacts=$pdo->query('SELECT * FROM contacts ORDER BY id')->fetchAll();
    $result=$runner->apply();mysqlTagCheck($result['code']==='CONTACT_TAGS_READY'&&$result['schema_only'],'Only the approved setup applies');
    mysqlTagCheck($runner->inspect()['ready_to_apply']&&$runner->inspect()['migration_recorded'],'Columns, keys, indexes and migration history verified');
    mysqlTagCheck($runner->apply()['code']==='ALREADY_APPLIED','Repeated migration is safe and verified');
    mysqlTagCheck((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===2,'Exactly migrations 016 and 020 recorded; email excluded');
    mysqlTagCheck($pdo->query('SELECT * FROM contacts ORDER BY id')->fetchAll()===$contacts,'Historical contact records unchanged');
    $tag=ContactTagService::save($pdo,1,'Leaders 💙');$other=ContactTagService::save($pdo,2,'Private');
    mysqlTagCheck(ContactTagService::listing($pdo,1)[0]['name']==='Leaders 💙','UTF-8 custom tags round-trip');
    ContactTagService::assign($pdo,1,['contact_ids'=>[1,2],'tag_ids'=>[$tag['id']],'operation'=>'add']);
    mysqlTagCheck((int)ContactTagService::listing($pdo,1)[0]['contact_count']===2,'Bulk assignment uses actual InnoDB tables');
    mysqlTagCheck(ContactTagService::assign($pdo,1,['contact_ids'=>[1,2],'tag_ids'=>[$tag['id']],'operation'=>'add'])['changed']===0,'InnoDB assignment replay is idempotent');
    mysqlTagCheck(ContactTagService::filtered($pdo,1,['tag_ids'=>[$tag['id']],'group_ids'=>[1,2]])['total']===2,'Tag and multi-group intersection works on MariaDB');
    $rejected=false;try{ContactTagService::assign($pdo,1,['contact_ids'=>[1,3],'tag_ids'=>[$other['id']],'operation'=>'add']);}catch(ContactTagException $e){$rejected=$e->status===404;}mysqlTagCheck($rejected,'Cross-account assignment rejected atomically');
    $draft=ContactTagService::smsDraft($pdo,1,['tag_ids'=>[$tag['id']]],'11111111-2222-4333-8444-555555555555');mysqlTagCheck($draft['matched_count']===2&&$draft['draft']['payload']['groupIds']===[],'Exact private audience uses existing MySQL work drafts');
    $bad=new ContactTagsSetup($pdo,$migrationFile,str_repeat('0',64),$draftFile,hash_file('sha256',$draftFile));$rejected=false;try{$bad->apply();}catch(RuntimeException){$rejected=true;}mysqlTagCheck($rejected,'Changed migration bytes cannot run');
    $pdo->exec('DELETE FROM contacts WHERE id=1');mysqlTagCheck((int)ContactTagService::listing($pdo,1)[0]['contact_count']===1,'Contact deletion cascades only its tag memberships');
    ContactTagService::remove($pdo,1,$tag['id']);mysqlTagCheck((int)$pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn()===2,'Deleting a tag preserves contacts');
    mysqlTagCheck((int)$pdo->query('SELECT COUNT(*) FROM contact_tag_audit')->fetchColumn()>0,'Audit records persisted');
    echo json_encode(['passed'=>true,'checks'=>$checks,'database'=>'disposable_loopback_mariadb','server_version'=>$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'production_access'=>false,'email_migration_run'=>false,'sms_submitted'=>false],JSON_PRETTY_PRINT)."\n";
}finally{
    // Exact generated fixture only. Never drop another database or broad directory.
    if($created&&$pdo->query('SELECT DATABASE()')->fetchColumn()===$name)$pdo->exec("DROP DATABASE `{$name}`");
}
