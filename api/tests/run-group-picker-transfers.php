<?php
require __DIR__.'/../domain/ContactGroupService.php';
class GroupFixture extends PDO {public function prepare(string $sql,array $options=[]):PDOStatement|false{return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options);}}
$pdo=new GroupFixture('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->sqliteCreateFunction('NOW',fn()=>'2026-10-23 12:00:00');
$pdo->exec('CREATE TABLE contacts(id INTEGER PRIMARY KEY,user_id INTEGER,phone TEXT,phone_normalized TEXT);CREATE TABLE contact_groups(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT);CREATE TABLE group_contacts(group_id INTEGER,contact_id INTEGER,created_at TEXT,PRIMARY KEY(group_id,contact_id));INSERT INTO contact_groups VALUES(1,1,\'First\'),(2,1,\'Second\'),(3,2,\'Private\')');
$q=$pdo->prepare('INSERT INTO contacts VALUES(?,1,?,?)');for($i=1;$i<=75;$i++)$q->execute([$i,'+2782000'.str_pad((string)$i,4,'0',STR_PAD_LEFT),'+2782000'.str_pad((string)$i,4,'0',STR_PAD_LEFT)]);
$pdo->exec("INSERT INTO contacts VALUES(76,2,'+27839999999','+27839999999')");$checks=0;
function checkGroup(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function rejectsGroup(callable $action,string $label):void{try{$action();}catch(DomainException|InvalidArgumentException){checkGroup(true,$label);return;}throw new RuntimeException($label);}
$before=$pdo->query('SELECT * FROM contacts')->fetchAll();
$r=ContactGroupService::transfer($pdo,1,['mode'=>'copy','scope'=>'group','source_group_id'=>'uncategorized','target_group_id'=>1]);
checkGroup($r['matched']===75 && $r['added']===75,'All uncategorized contacts including other pages are added, excluding other tenants');
checkGroup($pdo->query('SELECT * FROM contacts')->fetchAll()===$before,'Membership operations never mutate contact records');
$r=ContactGroupService::transfer($pdo,1,['mode'=>'copy','scope'=>'group','source_group_id'=>1,'target_group_id'=>2]);
checkGroup($r['matched']===75 && $r['added']===75,'Whole-group copy includes all pages');
$r=ContactGroupService::transfer($pdo,1,['mode'=>'move','scope'=>'group','source_group_id'=>1,'target_group_id'=>2]);
checkGroup($r['already_present']===75 && $r['removed_from_source']===75,'Whole-group move deduplicates and removes only source memberships');
rejectsGroup(fn()=>ContactGroupService::transfer($pdo,1,['mode'=>'copy','source_group_id'=>'uncategorized','ids'=>[1],'target_group_id'=>1]),'Stale uncategorized selection cannot move already grouped contacts');
rejectsGroup(fn()=>ContactGroupService::transfer($pdo,1,['mode'=>'copy','ids'=>[76],'target_group_id'=>1]),'Cross-account selected contact is rejected');
rejectsGroup(fn()=>ContactGroupService::transfer($pdo,1,['mode'=>'copy','scope'=>'group','source_group_id'=>2,'target_group_id'=>3]),'Cross-account destination is rejected');
rejectsGroup(fn()=>ContactGroupService::transfer($pdo,1,['mode'=>'copy','scope'=>'group','target_group_id'=>1]),'Whole-group scope requires an explicit source');
rejectsGroup(fn()=>ContactGroupService::transfer($pdo,1,['mode'=>'merge','source_group_id'=>'uncategorized','target_group_id'=>1]),'Virtual group cannot be deleted or merged');
$pdo->exec("INSERT INTO contacts VALUES(77,1,'+27830000001','+27830000001')");
$r=ContactGroupService::transfer($pdo,1,['mode'=>'move','source_group_id'=>'uncategorized','ids'=>[77],'target_group_id'=>1]);
checkGroup($r['added']===1 && $r['removed_from_source']===0,'Selected uncategorized move adds membership without deleting contacts');
echo "PASS: $checks isolated group transfer checks.\n";
