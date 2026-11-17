<?php
/** Account-scoped, bounded suggestions. No message bodies or account directory. */
final class PortalSearch
{
 public static function find(PDO $pdo,int $user,string $query):array
 {
  $query=trim($query);if(mb_strlen($query)<2)return [];
  if(mb_strlen($query)>80)throw new InvalidArgumentException('Search must be at most 80 characters.');
  // Explicit escape works identically on MySQL and SQLite; input is never SQL.
  $like='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';$out=[];
  foreach([
   ['campaign',"SELECT id,name AS label FROM sms_campaigns WHERE user_id=? AND name LIKE ? ESCAPE '!' ORDER BY id DESC LIMIT 5"],
   ['contact',"SELECT id,name AS label,phone FROM contacts WHERE user_id=? AND (name LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!') ORDER BY id DESC LIMIT 5"],
   ['template',"SELECT id,name AS label FROM templates WHERE user_id=? AND type='sms' AND name LIKE ? ESCAPE '!' ORDER BY id DESC LIMIT 5"],
  ]as[$type,$sql]){
   $q=$pdo->prepare($sql);$q->execute($type==='contact'?[$user,$like,$like]:[$user,$like]);
   foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$out[]=['type'=>$type,'id'=>(string)$row['id'],'label'=>trim((string)$row['label'])?:($type==='contact'?'Unnamed contact':'Untitled'),'search'=>$type==='contact'?(string)($row['phone']?:$row['label']):(string)$row['label']];
  }
  return $out;
 }
}
