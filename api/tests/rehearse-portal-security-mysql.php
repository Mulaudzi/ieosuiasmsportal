<?php
// Schema-only rehearsal on the owned loopback fixture. Never imports customer rows.
if(PHP_SAPI!=='cli')exit(64);
$file=$argv[1]??'';if(!$file||!is_file($file))throw new RuntimeException('Captured schema file required');
$captured=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$ddl=$captured['schema']['users']['ddl']??'';
if(!str_starts_with($ddl,'CREATE TABLE `users`'))throw new RuntimeException('Unexpected captured table');
$root=new PDO('mysql:host=127.0.0.1;port=33163','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db='sms_schema_fixture_'.bin2hex(random_bytes(8));$root->exec('CREATE DATABASE '.$db);
try{
 $p=new PDO('mysql:host=127.0.0.1;port=33163;dbname='.$db,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec('SET FOREIGN_KEY_CHECKS=0');$p->exec($ddl);$p->exec('SET FOREIGN_KEY_CHECKS=1');
 $migration=require __DIR__.'/../migrations/023_portal_login_security.php';$migration($p);$migration($p);
 foreach(['sms_login_security','sms_login_challenges','portal_security_events']as$table){$q=$p->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);if(strtolower((string)$q->fetchColumn())!=='innodb'||(int)$p->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('Schema verification failed');}
 if((int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn()!==0)throw new RuntimeException('Unexpected fixture records');
 echo json_encode(['passed'=>true,'production_users_schema_rehearsed'=>true,'migration_rerun_passed'=>true,'new_tables'=>3,'customer_rows_imported'=>0,'production_changes'=>false]).PHP_EOL;
}finally{if(!preg_match('/^sms_schema_fixture_[a-f0-9]{16}$/D',$db))throw new RuntimeException('Unexpected fixture target');$p=null;$root->exec('DROP DATABASE '.$db);}
