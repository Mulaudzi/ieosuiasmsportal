<?php
final class ContactTagException extends RuntimeException {public function __construct(string $message,public readonly int $status=422){parent::__construct($message);}}

final class ContactTagService
{
    private static function query(PDO $p,string $sql,array $args=[]): PDOStatement {$s=$p->prepare($sql);$s->execute($args);return $s;}
    private static function lock(PDO $p):string{return $p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    public static function ready(PDO $p):bool
    {
        try{$p->query('SELECT id FROM contact_tags LIMIT 0');return true;}
        catch(PDOException $e){if($e->getCode()==='42S02'||str_contains($e->getMessage(),'no such table'))return false;throw $e;}
    }
    private static function requireReady(PDO $p):void {if(!self::ready($p))throw new ContactTagException('Contact tags are awaiting database setup. Your contacts and groups are unchanged.',503);}
    public static function ids(mixed $value,bool $tags=false,int $max=200):array
    {
        if(is_string($value))$value=$value===''?[]:explode(',',$value);
        if(!is_array($value)||!array_is_list($value)||count($value)>$max)throw new ContactTagException('Too many or invalid filter selections.');
        $out=[];foreach($value as $id){if(!is_string($id)&&!is_int($id))throw new ContactTagException('Invalid filter reference.');$id=(string)$id;if(!preg_match($tags?'/^[a-f0-9]{32}$/D':'/^[1-9][0-9]{0,17}$/D',$id))throw new ContactTagException('Invalid filter reference.');$out[$id]=$id;}
        $out=array_values($out);sort($out,SORT_STRING);return $out;
    }
    private static function owned(PDO $p,int $user,string $table,array $ids):void
    {
        if(!$ids)return;$marks=implode(',',array_fill(0,count($ids),'?'));
        $found=self::query($p,"SELECT id FROM {$table} WHERE user_id=? AND id IN ({$marks})",[$user,...$ids])->fetchAll(PDO::FETCH_COLUMN);
        if(count($found)!==count($ids))throw new ContactTagException('One or more contacts, groups or tags were not found in your account.',404);
    }
    private static function transaction(PDO $p,int $user,callable $f):mixed
    {
        self::requireReady($p);$p->beginTransaction();
        try{if(!self::query($p,'SELECT id FROM users WHERE id=?'.self::lock($p),[$user])->fetchColumn())throw new ContactTagException('Account not found.',404);$v=$f();$p->commit();return $v;}
        catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    }
    private static function name(mixed $input):array
    {
        if(!is_string($input)||!preg_match('//u',$input)||preg_match('/[\x00-\x1F\x7F]/',$input))throw new ContactTagException('Use a short tag name without line breaks.');
        $name=preg_replace('/\s+/u',' ',preg_replace('/^\s+|\s+$/u','',$input));
        $length=preg_match_all('/./us',$name);
        if($length<1||$length>50||strlen($name)>200)throw new ContactTagException('Tag names must contain 1–50 characters.');
        return [$name,hash('sha256',function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name))];
    }
    private static function audit(PDO $p,int $user,string $operation,array $details):void {self::query($p,'INSERT INTO contact_tag_audit(id,user_id,operation,details_json,created_at) VALUES(?,?,?,?,?)',[bin2hex(random_bytes(16)),$user,$operation,json_encode($details,JSON_THROW_ON_ERROR),date('Y-m-d H:i:s')]);}
    public static function listing(PDO $p,int $user):array
    {
        self::requireReady($p);
        return self::query($p,'SELECT t.id,t.name,COUNT(c.id) contact_count FROM contact_tags t LEFT JOIN contact_tag_memberships m ON m.tag_id=t.id AND m.user_id=t.user_id LEFT JOIN contacts c ON c.id=m.contact_id AND c.user_id=t.user_id WHERE t.user_id=? GROUP BY t.id,t.name ORDER BY t.name,t.id',[$user])->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function save(PDO $p,int $user,mixed $name,?string $id=null):array
    {
        [$name,$key]=self::name($name);
        if($id!==null)self::ids([$id],true);
        return self::transaction($p,$user,static function()use($p,$user,$name,$key,$id){
            if($id!==null)self::owned($p,$user,'contact_tags',[$id]);
            $same=self::query($p,'SELECT id,name FROM contact_tags WHERE user_id=? AND name_key=?',[$user,$key])->fetch(PDO::FETCH_ASSOC);
            if($same&&$same['id']!==$id){if($id!==null)throw new ContactTagException('That tag name already exists.',409);return $same;}
            if($id===null&&(int)self::query($p,'SELECT COUNT(*) FROM contact_tags WHERE user_id=?',[$user])->fetchColumn()>=200)throw new ContactTagException('You can create up to 200 tags. Rename or reuse an existing tag.',409);
            $now=date('Y-m-d H:i:s');$tag=$id??bin2hex(random_bytes(16));
            if($id===null)self::query($p,'INSERT INTO contact_tags(id,user_id,name,name_key,created_at,updated_at) VALUES(?,?,?,?,?,?)',[$tag,$user,$name,$key,$now,$now]);
            else self::query($p,'UPDATE contact_tags SET name=?,name_key=?,updated_at=? WHERE id=? AND user_id=?',[$name,$key,$now,$tag,$user]);
            self::audit($p,$user,$id===null?'created':'renamed',['tag_id'=>$tag,'name'=>$name]);return ['id'=>$tag,'name'=>$name];
        });
    }
    public static function remove(PDO $p,int $user,string $id):void
    {
        self::ids([$id],true);self::transaction($p,$user,static function()use($p,$user,$id){self::owned($p,$user,'contact_tags',[$id]);self::query($p,'DELETE FROM contact_tag_memberships WHERE tag_id=? AND user_id=?',[$id,$user]);self::query($p,'DELETE FROM contact_tags WHERE id=? AND user_id=?',[$id,$user]);self::audit($p,$user,'deleted',['tag_id'=>$id]);});
    }
    public static function assign(PDO $p,int $user,array $input):array
    {
        $contacts=self::ids($input['contact_ids']??[],false,500);$tags=self::ids($input['tag_ids']??[],true,20);$operation=$input['operation']??'';
        if(!$contacts||!$tags||!in_array($operation,['add','remove'],true))throw new ContactTagException('Choose contacts, tags and add or remove.');
        return self::transaction($p,$user,static function()use($p,$user,$contacts,$tags,$operation){
            self::owned($p,$user,'contacts',$contacts);self::owned($p,$user,'contact_tags',$tags);$changed=0;
            foreach($contacts as $contact){
                $existing=self::query($p,'SELECT tag_id FROM contact_tag_memberships WHERE user_id=? AND contact_id=?',[$user,$contact])->fetchAll(PDO::FETCH_COLUMN);
                if($operation==='add'&&count(array_unique([...$existing,...$tags]))>20)throw new ContactTagException('A contact can have up to 20 tags. Remove unused tags first.',409);
                foreach($tags as $tag){if($operation==='add'&&!in_array($tag,$existing,true)){$changed+=self::query($p,'INSERT INTO contact_tag_memberships(tag_id,contact_id,user_id,created_at) VALUES(?,?,?,?)',[$tag,$contact,$user,date('Y-m-d H:i:s')])->rowCount();}
                    elseif($operation==='remove')$changed+=self::query($p,'DELETE FROM contact_tag_memberships WHERE tag_id=? AND contact_id=? AND user_id=?',[$tag,$contact,$user])->rowCount();}
            }
            if($changed)self::audit($p,$user,'tags_'.$operation,['contact_ids'=>$contacts,'tag_ids'=>$tags,'changed'=>$changed]);return ['changed'=>$changed,'contacts'=>count($contacts)];
        });
    }
    public static function decorate(PDO $p,int $user,array $contacts):array
    {
        foreach($contacts as &$c)$c['tags']=[];unset($c);
        if(!$contacts||!self::ready($p))return $contacts;
        $ids=array_column($contacts,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
        $rows=self::query($p,"SELECT m.contact_id,t.id,t.name FROM contact_tag_memberships m JOIN contact_tags t ON t.id=m.tag_id AND t.user_id=m.user_id JOIN contacts c ON c.id=m.contact_id AND c.user_id=m.user_id WHERE m.user_id=? AND m.contact_id IN ({$marks}) ORDER BY t.name,t.id",[$user,...$ids])->fetchAll(PDO::FETCH_ASSOC);$by=[];
        foreach($rows as $r)$by[(string)$r['contact_id']][]=['id'=>$r['id'],'name'=>$r['name']];foreach($contacts as &$c)$c['tags']=$by[(string)$c['id']]??[];unset($c);return $contacts;
    }
    public static function filtered(PDO $p,int $user,array $filter,int $limit=200,int $offset=0):array
    {
        $tags=self::ids($filter['tag_ids']??[],true);$groups=self::ids($filter['group_ids']??[]);$base=(string)($filter['group_id']??'');
        $where='c.user_id=?';$args=[$user];
        if($tags){self::requireReady($p);self::owned($p,$user,'contact_tags',$tags);$marks=implode(',',array_fill(0,count($tags),'?'));$where.=" AND EXISTS (SELECT 1 FROM contact_tag_memberships tm JOIN contact_tags t ON t.id=tm.tag_id AND t.user_id=tm.user_id WHERE tm.contact_id=c.id AND tm.user_id=c.user_id AND t.id IN ({$marks}))";array_push($args,...$tags);}
        if($base==='uncategorized')$where.=' AND NOT EXISTS (SELECT 1 FROM group_contacts gc JOIN contact_groups g ON g.id=gc.group_id AND g.user_id=c.user_id WHERE gc.contact_id=c.id)';
        elseif($base==='opted-out')$where.=" AND EXISTS (SELECT 1 FROM opt_outs o WHERE o.user_id=c.user_id AND o.channel='sms' AND o.recipient=c.phone_normalized)";
        elseif($base!==''&&$base!=='all')$groups=self::ids([...$groups,$base]);
        if($groups){self::owned($p,$user,'contact_groups',$groups);$marks=implode(',',array_fill(0,count($groups),'?'));$where.=" AND EXISTS (SELECT 1 FROM group_contacts gm JOIN contact_groups g ON g.id=gm.group_id WHERE gm.contact_id=c.id AND g.user_id=c.user_id AND g.id IN ({$marks}))";array_push($args,...$groups);}
        $search=$filter['search']??'';if(!is_string($search)||strlen($search)>200)throw new ContactTagException('Search is too long.');
        if($search!==''){$where.=' AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';array_push($args,...array_fill(0,3,'%'.$search.'%'));}
        $order=match($filter['sort']??'newest'){'name'=>'c.name ASC,c.id ASC','oldest'=>'c.created_at ASC,c.id ASC',default=>'c.created_at DESC,c.id DESC'};
        $total=(int)self::query($p,'SELECT COUNT(*) FROM contacts c WHERE '.$where,$args)->fetchColumn();
        $limit=max(1,min(10001,$limit));$offset=max(0,$offset);
        $rows=self::query($p,"SELECT c.*, (SELECT GROUP_CONCAT(g.id) FROM group_contacts gm JOIN contact_groups g ON g.id=gm.group_id WHERE gm.contact_id=c.id AND g.user_id=c.user_id) group_id,(SELECT GROUP_CONCAT(g.name) FROM group_contacts gm JOIN contact_groups g ON g.id=gm.group_id WHERE gm.contact_id=c.id AND g.user_id=c.user_id) group_name FROM contacts c WHERE {$where} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}",$args)->fetchAll(PDO::FETCH_ASSOC);
        return ['contacts'=>$rows,'total'=>$total];
    }
    public static function smsDraft(PDO $p,int $user,array $filter,string $draftId):array
    {
        require_once __DIR__.'/WorkDraftService.php';
        $matched=self::filtered($p,$user,$filter,10001);
        if(!$matched['total'])throw new ContactTagException('No matching contacts. Change the filters first.');
        if($matched['total']>10000)throw new ContactTagException('This selection is too large for a draft. Narrow the group or tag filters first.',413);
        $ids=array_map('strval',array_column($matched['contacts'],'id'));$key=str_replace('-','',$draftId);
        $draft=WorkDraftService::save($p,$user,$draftId,['kind'=>'sms','title'=>'Filtered contact audience','expected_version'=>0,'payload'=>['version'=>1,'step'=>1,'name'=>'','manual'=>'','groupIds'=>[],'contactIds'=>$ids,'excluded'=>[],'selectedTemplate'=>'none','content'=>'','includeOptOut'=>false,'schedule'=>'','submissionKey'=>$key]]);
        return ['draft'=>$draft,'matched_count'=>count($ids),'message'=>'Draft prepared only. Invalid and opted-out SMS numbers will be excluded during recipient review.'];
    }
}
