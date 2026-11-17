<?php
declare(strict_types=1);
require_once __DIR__.'/PhoneNumber.php';

/** Explicitly replace a mistyped group's membership with an existing contact.
 * Neither contact's details, suppression records or historical SMS are altered. */
final class ContactNumberReplacement
{
    public static function apply(PDO $pdo,int $userId,int $sourceId,string $phone):array
    {
        $phone=PhoneNumber::normalize($phone);
        $pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT id,phone_normalized FROM contacts WHERE user_id=? AND (id=? OR phone_normalized=?) ORDER BY id FOR UPDATE');$q->execute([$userId,$sourceId,$phone]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            $source=null;$target=null;foreach($rows as $row){if((int)$row['id']===$sourceId)$source=$row;if($row['phone_normalized']===$phone)$target=$row;}
            if(!$source||!$target||(int)$target['id']===$sourceId)throw new DomainException('The existing contact changed. Refresh and try again.');
            $q=$pdo->prepare('SELECT gc.group_id FROM group_contacts gc JOIN contact_groups g ON g.id=gc.group_id WHERE gc.contact_id=? AND g.user_id=? ORDER BY gc.group_id FOR UPDATE');$q->execute([$sourceId,$userId]);$groups=$q->fetchAll(PDO::FETCH_COLUMN);
            if(!$groups)throw new DomainException('This contact has no groups to replace. Add the existing contact to your chosen group instead.');
            $insert=$pdo->prepare('INSERT INTO group_contacts(group_id,contact_id,created_at) SELECT ?,?,NOW() WHERE NOT EXISTS (SELECT 1 FROM group_contacts WHERE group_id=? AND contact_id=?)');
            $delete=$pdo->prepare('DELETE FROM group_contacts WHERE group_id=? AND contact_id=?');
            foreach($groups as $group){$insert->execute([$group,$target['id'],$group,$target['id']]);$delete->execute([$group,$sourceId]);}
            $q=$pdo->prepare('SELECT * FROM contacts WHERE id=? AND user_id=?');$q->execute([$target['id'],$userId]);$contact=$q->fetch(PDO::FETCH_ASSOC);
            $pdo->commit();return ['contact'=>$contact,'existing_contact_reused'=>true,'replaced_group_count'=>count($groups),'original_contact_preserved'=>true];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
