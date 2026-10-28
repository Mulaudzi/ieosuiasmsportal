<?php

final class WorkDraftException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status) { parent::__construct($message); }
}

/** Private form storage only. No sending, scheduling, orders, payments or wallet writes. */
final class WorkDraftService
{
    public static function kind(mixed $kind): string
    {
        if (!in_array($kind,['sms','purchase'],true)) throw new InvalidArgumentException('Choose SMS or purchase drafts.');
        return $kind;
    }
    private static function id(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id)) throw new InvalidArgumentException('Invalid draft reference.');
    }
    private static function text(array $p,string $field,int $max): string
    {
        if (!is_string($p[$field]??null) || strlen($p[$field])>$max) throw new InvalidArgumentException('Invalid draft field: '.$field.'.');
        return $p[$field];
    }
    private static function integer(mixed $value,int $min,int $max): int
    {
        if (!is_int($value)||$value<$min||$value>$max) throw new InvalidArgumentException('Invalid draft number.');
        return $value;
    }
    public static function payload(string $kind,mixed $input): array
    {
        if (!is_array($input)) throw new InvalidArgumentException('Draft content is required.');
        $fields=$kind==='sms'
            ? ['version','step','name','manual','groupIds','contactIds','excluded','selectedTemplate','content','includeOptOut','schedule','submissionKey']
            : ['version','step','credits','method','requestKey'];
        if (array_diff(array_keys($input),$fields)) throw new InvalidArgumentException('Drafts cannot contain calculated totals, payment details or other fields.');
        if (($input['version']??null)!==1) throw new InvalidArgumentException('Unsupported draft format.');
        $out=['version'=>1];
        if ($kind==='sms') {
            $out['step']=self::integer($input['step']??null,1,5);
            foreach (['name'=>100,'manual'=>100000,'selectedTemplate'=>80,'content'=>16000,'schedule'=>32,'submissionKey'=>80] as $key=>$max) $out[$key]=self::text($input,$key,$max);
            if (!preg_match('/^[a-zA-Z0-9-]{1,80}$/D',$out['submissionKey'])) throw new InvalidArgumentException('Invalid campaign draft key.');
            if (!is_bool($input['includeOptOut']??null)) throw new InvalidArgumentException('Invalid opt-out choice.');
            $out['includeOptOut']=$input['includeOptOut'];
            foreach (['groupIds','contactIds','excluded'] as $field) {
                $values=$input[$field]??null;
                if (!is_array($values)||!array_is_list($values)||count($values)>10000) throw new InvalidArgumentException('Invalid draft recipients.');
                foreach ($values as $v) if (!is_string($v)||strlen($v)>80) throw new InvalidArgumentException('Invalid draft recipient reference.');
                $out[$field]=array_values(array_unique($values));
            }
        } else {
            if (!in_array($input['step']??null,['order','payment'],true)||!in_array($input['method']??null,['payos','eft'],true)) throw new InvalidArgumentException('Invalid purchase draft options.');
            $out['step']=$input['step'];$out['method']=$input['method'];
            // Zero permits saving an unfinished quantity. Checkout still validates its own minimum.
            $out['credits']=self::integer($input['credits']??null,0,1000000);
            $out['requestKey']=self::text($input,'requestKey',80);
            if (!preg_match('/^[a-zA-Z0-9-]{1,80}$/D',$out['requestKey'])) throw new InvalidArgumentException('Invalid purchase draft key.');
        }
        if (strlen(json_encode($out,JSON_THROW_ON_ERROR))>250000) throw new InvalidArgumentException('This draft is too large. Use saved contact groups for larger audiences.');
        return $out;
    }
    private static function present(array $row,bool $payload=true): array
    {
        $row['version']=(int)$row['version'];unset($row['user_id']);
        if ($payload) $row['payload']=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);
        unset($row['payload_json']);return $row;
    }
    public static function listing(PDO $pdo,int $user,string $kind): array
    {
        self::kind($kind);
        $q=$pdo->prepare('SELECT id,kind,title,version,created_at,updated_at FROM work_drafts WHERE user_id=? AND kind=? ORDER BY updated_at DESC,id DESC LIMIT 100');
        $q->execute([$user,$kind]);return array_map(static fn($r)=>self::present($r,false),$q->fetchAll(PDO::FETCH_ASSOC));
    }
    public static function get(PDO $pdo,int $user,string $id): array
    {
        self::id($id);$q=$pdo->prepare('SELECT * FROM work_drafts WHERE id=? AND user_id=?');$q->execute([$id,$user]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new WorkDraftException('Draft not found. It may have been deleted or belongs to another account.',404);
        return self::present($row);
    }
    public static function save(PDO $pdo,int $user,string $id,array $input): array
    {
        self::id($id);$kind=self::kind($input['kind']??null);$payload=self::payload($kind,$input['payload']??null);
        $title=trim(self::text($input,'title',100));if ($title==='') throw new InvalidArgumentException('Add a draft name.');
        $version=self::integer($input['expected_version']??null,0,2147483646);
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$now=date('Y-m-d H:i:s');
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
        $pdo->beginTransaction();
        try {
            // Serialize one account's saves, including its limit check. Never update the user row.
            $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$q->execute([$user]);
            if (!$q->fetchColumn()) throw new WorkDraftException('Account not found.',404);
            $q=$pdo->prepare('SELECT * FROM work_drafts WHERE id=?'.$lock);$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if ($old && ((int)$old['user_id']!==$user||$old['kind']!==$kind)) throw new WorkDraftException('Draft not found.',404);
            if ($old && (int)$old['version']!==$version) {
                // A lost save response can be retried without creating another draft.
                if ((int)$old['version']===$version+1 && $old['title']===$title && $old['payload_json']===$json) {$pdo->commit();return self::present($old);}
                throw new WorkDraftException('This draft changed in another tab. Reopen it before saving so that no work is overwritten.',409);
            }
            if ($old) {
                $q=$pdo->prepare('UPDATE work_drafts SET title=?,payload_json=?,version=version+1,updated_at=? WHERE id=? AND user_id=? AND version=?');
                $q->execute([$title,$json,$now,$id,$user,$version]);
            } else {
                if ($version!==0) throw new WorkDraftException('This draft was deleted. Save your work as a new draft.',404);
                $q=$pdo->prepare('SELECT COUNT(*) FROM work_drafts WHERE user_id=? AND kind=?');$q->execute([$user,$kind]);
                if ((int)$q->fetchColumn()>=100) throw new WorkDraftException('You have 100 saved drafts of this type. Delete an old draft before adding another.',409);
                $q=$pdo->prepare('INSERT INTO work_drafts(id,user_id,kind,title,payload_json,version,created_at,updated_at) VALUES(?,?,?,?,?,1,?,?)');$q->execute([$id,$user,$kind,$title,$json,$now,$now]);
            }
            $result=self::get($pdo,$user,$id);$pdo->commit();return $result;
        } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
    }
    public static function delete(PDO $pdo,int $user,string $id,mixed $version): void
    {
        self::id($id);self::integer($version,1,2147483647);
        $q=$pdo->prepare('DELETE FROM work_drafts WHERE id=? AND user_id=? AND version=?');$q->execute([$id,$user,$version]);
        if ($q->rowCount()===0) {self::get($pdo,$user,$id);throw new WorkDraftException('This draft changed in another tab. Refresh before deleting it.',409);}
    }
}
