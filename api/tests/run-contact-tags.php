<?php
declare(strict_types=1);
// Isolated fixtures only; no .env, provider, live account or network connection.
require_once __DIR__.'/../domain/ContactTagService.php';
require_once __DIR__.'/../domain/WorkDraftService.php';
$checks=0;
function tagCheck(bool $v,string $label):void{global $checks;$checks++;if(!$v)throw new RuntimeException($label);}
function tagFails(callable $f,int $status,string $label):void{try{$f();throw new RuntimeException('Expected error: '.$label);}catch(ContactTagException|WorkDraftException $e){tagCheck($e->status===$status,$label);}}
$p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->exec('PRAGMA foreign_keys=ON');
$p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY);INSERT INTO users VALUES(1),(2);
CREATE TABLE contacts(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,surname TEXT,phone TEXT,phone_normalized TEXT,email TEXT,subscription_status TEXT,created_at TEXT);
INSERT INTO contacts VALUES(1,1,'Alice','','+27821111111','+27821111111','alice@example.org','subscribed','2026-10-01'),(2,1,'Bob','','+27822222222','+27822222222','bob@example.org','subscribed','2026-10-02'),(3,1,'Carol','','+27823333333','+27823333333','carol@example.org','subscribed','2026-10-03'),(4,1,'Email only','',NULL,NULL,'only@example.org','subscribed','2026-10-04'),(20,2,'Private','','+27824444444','+27824444444','private@example.org','subscribed','2026-10-01');
CREATE TABLE contact_groups(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT);INSERT INTO contact_groups VALUES(1,1,'First group'),(2,1,'Second group'),(20,2,'Private');
CREATE TABLE group_contacts(group_id INTEGER,contact_id INTEGER);INSERT INTO group_contacts VALUES(1,1),(1,2),(2,2),(2,3),(20,20);
CREATE TABLE opt_outs(user_id INTEGER,channel TEXT,recipient TEXT);INSERT INTO opt_outs VALUES(1,'sms','+27822222222');
CREATE TABLE wallets(id INTEGER PRIMARY KEY,user_id INTEGER,balance TEXT,reserved TEXT);INSERT INTO wallets VALUES(1,1,'100.00','12.25');
CREATE TABLE sms_messages(id INTEGER PRIMARY KEY,state TEXT,actual_charge TEXT);INSERT INTO sms_messages VALUES(1,'sent','0.3500');
CREATE TABLE work_drafts(id TEXT PRIMARY KEY,user_id INTEGER,kind TEXT,title TEXT,payload_json TEXT,version INTEGER,created_at TEXT,updated_at TEXT);");
$snapshot=[];foreach(['contacts','contact_groups','group_contacts','opt_outs','wallets','sms_messages']as$t)$snapshot[$t]=$p->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC);
tagCheck(!ContactTagService::ready($p),'Absent migration detected without auto-migration');
tagCheck(ContactTagService::decorate($p,1,[['id'=>1]])[0]['tags']===[],'Old contacts still load with tags schema absent');
tagFails(fn()=>ContactTagService::filtered($p,1,['tag_ids'=>[str_repeat('a',32)]]),503,'Missing tag schema never broadens a selected audience');
$m=require __DIR__.'/../migrations/020_contact_tags.php';$m($p);$m($p);tagCheck(ContactTagService::ready($p),'Additive migration is rerunnable, independent of email');
$leaders=ContactTagService::save($p,1,'Leaders');$customers=ContactTagService::save($p,1,'Customers');$private=ContactTagService::save($p,2,'Leaders');
tagCheck($leaders['id']!==$private['id'],'Identical names belong to different accounts');
tagCheck(ContactTagService::save($p,1,'  leaders  ')['id']===$leaders['id'],'Repeated label creation is case/whitespace idempotent');
foreach(['',str_repeat('x',51),"Bad\nlabel"]as$name)tagFails(fn()=>ContactTagService::save($p,1,$name),422,'Invalid tag name rejected');
$c=ContactTagService::assign($p,1,['contact_ids'=>['1','2'],'tag_ids'=>[$leaders['id']],'operation'=>'add']);tagCheck($c['changed']===2,'Assign one tag to multiple contacts');
tagCheck(ContactTagService::assign($p,1,['contact_ids'=>['1','2'],'tag_ids'=>[$leaders['id']],'operation'=>'add'])['changed']===0,'Assignment replay does not duplicate membership');
ContactTagService::assign($p,1,['contact_ids'=>['2','3','4'],'tag_ids'=>[$customers['id']],'operation'=>'add']);
$all=ContactTagService::filtered($p,1,['tag_ids'=>[$leaders['id'],$customers['id']]]);tagCheck($all['total']===4,'Tags match any selected label, not duplicated rows');
$group=ContactTagService::filtered($p,1,['tag_ids'=>[$leaders['id']],'group_id'=>'2']);tagCheck(array_column($group['contacts'],'id')===[2],'Tag AND selected group intersection');
$multi=ContactTagService::filtered($p,1,['tag_ids'=>[$leaders['id'],$customers['id']],'group_ids'=>['1','2']]);tagCheck($multi['total']===3,'Multiple groups form a union with no duplicate audience');
$search=ContactTagService::filtered($p,1,['tag_ids'=>[$customers['id']],'group_ids'=>['1','2'],'search'=>'Carol']);tagCheck(array_column($search['contacts'],'id')===[3],'Search remains within group/tag scope');
tagCheck(ContactTagService::filtered($p,1,['group_id'=>'uncategorized','tag_ids'=>[$customers['id']]])['total']===1,'Uncategorized filter combines with tags');
tagCheck(ContactTagService::filtered($p,1,['group_id'=>'opted-out','tag_ids'=>[$leaders['id']]])['total']===1,'Opt-out filtering is separate from classification');
$page=ContactTagService::filtered($p,1,['tag_ids'=>[$leaders['id'],$customers['id']],'sort'=>'name'],1,1);tagCheck($page['total']===4&&$page['contacts'][0]['name']==='Bob','Filtering and sorting happen server-side before pagination');
$decorated=ContactTagService::decorate($p,1,[['id'=>2]]);tagCheck(count($decorated[0]['tags'])===2,'Multiple tags displayed on one contact');
foreach([['contact_ids'=>['1','20'],'tag_ids'=>[$leaders['id']]],['contact_ids'=>['1'],'tag_ids'=>[$leaders['id'],$private['id']]]]as$bad)tagFails(fn()=>ContactTagService::assign($p,1,$bad+['operation'=>'add']),404,'Cross-account bulk requests rejected atomically');
tagFails(fn()=>ContactTagService::filtered($p,1,['tag_ids'=>[$private['id']]]),404,'Foreign tag cannot be used as filter');tagFails(fn()=>ContactTagService::filtered($p,1,['group_ids'=>['20']]),404,'Foreign group cannot be filtered');
tagFails(fn()=>ContactTagService::save($p,1,'Foreign rename',$private['id']),404,'Foreign rename forbidden');tagFails(fn()=>ContactTagService::remove($p,1,$private['id']),404,'Foreign deletion forbidden');
ContactTagService::save($p,1,'Team leaders',$leaders['id']);tagCheck(ContactTagService::filtered($p,1,['tag_ids'=>[$leaders['id']]])['total']===2,'Renaming preserves assignments');
tagFails(fn()=>ContactTagService::save($p,1,'Customers',$leaders['id']),409,'Duplicate rename cannot overwrite another label');
ContactTagService::assign($p,1,['contact_ids'=>['2'],'tag_ids'=>[$leaders['id']],'operation'=>'remove']);tagCheck(count(ContactTagService::decorate($p,1,[['id'=>2]])[0]['tags'])===1,'Removing a chosen tag preserves other tags');
// Full filtered audience, not just the currently displayed page, becomes a private new draft.
$draftId='11111111-2222-4333-8444-555555555555';$filter=['group_ids'=>['1','2'],'tag_ids'=>[$customers['id']],'search'=>''];
$draft=ContactTagService::smsDraft($p,1,$filter,$draftId);tagCheck($draft['matched_count']===2,'Filtered draft captures all matching contacts');
tagCheck($draft['draft']['payload']['contactIds']===['3','2'],'Draft captures exact filtered contact IDs in server sort order');
tagCheck($draft['draft']['payload']['groupIds']===[]&&$draft['draft']['payload']['manual']==='','No broad group/manual selection leaks into the draft');
tagCheck($draft['draft']['payload']['step']===1&&$draft['draft']['payload']['includeOptOut']===false,'Draft starts at campaign name with opt-out default unchanged');
tagCheck(ContactTagService::smsDraft($p,1,$filter,$draftId)['draft']['id']===$draftId,'Lost draft response retry is idempotent');
tagFails(fn()=>WorkDraftService::get($p,2,$draftId),404,'Filtered draft stays private');
tagFails(fn()=>ContactTagService::smsDraft($p,1,['tag_ids'=>[$leaders['id']],'search'=>'Nobody'],'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'),422,'Empty filter cannot create a broad draft');
ContactTagService::assign($p,1,['contact_ids'=>['1'],'tag_ids'=>[$customers['id']],'operation'=>'add']);tagFails(fn()=>ContactTagService::smsDraft($p,1,$filter,$draftId),409,'Changed audience cannot overwrite a previously prepared draft');
// The per-contact cap fails the whole multi-contact operation, preserving earlier tags.
$extra=[];for($i=0;$i<19;$i++)$extra[]=ContactTagService::save($p,1,'Extra '.$i)['id'];ContactTagService::assign($p,1,['contact_ids'=>['3'],'tag_ids'=>$extra,'operation'=>'add']);
$limit=ContactTagService::save($p,1,'Over limit');$before=(int)$p->query('SELECT COUNT(*) FROM contact_tag_memberships')->fetchColumn();
tagFails(fn()=>ContactTagService::assign($p,1,['contact_ids'=>['1','3'],'tag_ids'=>[$limit['id']],'operation'=>'add']),409,'Tag-cap failure rolls back the complete bulk action');tagCheck((int)$p->query('SELECT COUNT(*) FROM contact_tag_memberships')->fetchColumn()===$before,'No partial earlier-contact assignment');
ContactTagService::remove($p,1,$leaders['id']);tagCheck((int)$p->query('SELECT COUNT(*) FROM contacts')->fetchColumn()===5,'Delete tag never deletes contacts');
foreach($snapshot as$t=>$rows)tagCheck($rows===$p->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC),'Preserved existing '.$t);
tagCheck((int)$p->query('SELECT COUNT(*) FROM contact_tag_audit')->fetchColumn()>0,'Classification changes have an audit trail');
tagCheck(!(bool)$p->query("SELECT name FROM sqlite_master WHERE name='email_campaign_jobs'")->fetchColumn(),'Email migration not needed or applied');
echo "Contact tags: {$checks} checks passed. Local SQLite only; no sending, wallet or production changes.\n";
