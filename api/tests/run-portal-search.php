<?php
require_once __DIR__.'/../domain/PortalSearch.php';
$p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec("CREATE TABLE sms_campaigns(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT);CREATE TABLE contacts(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,phone TEXT,email TEXT);CREATE TABLE templates(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,type TEXT,content TEXT);
INSERT INTO sms_campaigns VALUES(1,1,'Sunday service'),(2,2,'Sunday private'),(3,1,'Literal 10%_off');INSERT INTO contacts VALUES(1,1,'Sunday Leader','+27821111111','secret@example.invalid'),(2,2,'Sunday Secret','+27822222222','private@example.invalid');INSERT INTO templates VALUES(1,1,'Sunday reminder','sms','Private SMS body'),(2,2,'Sunday foreign','sms','Other body'),(3,1,'Sunday email','email','Not released')");
$n=0;function check($ok,$label){global$n;$n++;if(!$ok)throw new RuntimeException($label);}
$r=PortalSearch::find($p,1,'Sunday');check(count($r)===3,'Matches each owned, released kind only');check(array_column($r,'type')===['campaign','contact','template'],'Kinds');
check(!str_contains(json_encode($r),'Secret')&&!str_contains(json_encode($r),'private')&&!str_contains(json_encode($r),'email'),'No foreign results or email template');
check(!str_contains(json_encode($r),'body')&&!str_contains(json_encode($r),'@'),'No message bodies or email details');
check(count(PortalSearch::find($p,1,'+278211'))===1,'Phone search');check(PortalSearch::find($p,1,'a')===[],'Minimum length');
check(PortalSearch::find($p,1,"' OR 1=1 --")===[],'SQL input not executable');check(count(PortalSearch::find($p,1,'10%_'))===1,'Literal SQL wildcard search');check(PortalSearch::find($p,1,'%%')===[],'Wildcards cannot enumerate');
try{PortalSearch::find($p,1,str_repeat('x',81));check(false,'Overlong accepted');}catch(InvalidArgumentException){check(true,'Length bounded');}
for($i=10;$i<30;$i++)$p->exec("INSERT INTO sms_campaigns VALUES($i,1,'Sunday $i')");
check(count(array_filter(PortalSearch::find($p,1,'Sunday'),fn($v)=>$v['type']==='campaign'))===5,'Five suggestions per kind');
echo "PASS: $n account-scoped bounded search checks\n";
