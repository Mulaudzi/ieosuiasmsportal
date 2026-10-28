<?php

require_once __DIR__ . '/PhoneNumber.php';

/** Changes memberships only. Contact records, opt-outs and campaign snapshots are untouched. */
final class ContactGroupService
{
    public static function id(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value)
            || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Select a valid contact group or contact.');
        }
        return (int)$value;
    }

    public static function ids(mixed $values): array
    {
        if (!is_array($values) || !$values || count($values) > 10000) {
            throw new InvalidArgumentException('Select between 1 and 10,000 contacts.');
        }
        $ids = array_values(array_unique(array_map([self::class, 'id'], $values)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public static function duplicateKey(array $contact): string
    {
        $phone = ($contact['phone_normalized'] ?? '') ?: ($contact['phone'] ?? '');
        if ($phone !== '') {
            try { return 'phone:' . PhoneNumber::normalize($phone); }
            catch (InvalidArgumentException) { /* Legacy invalid numbers are matched by contact ID only. */ }
        }
        return 'contact:' . $contact['id'];
    }

    public static function transfer(PDO $pdo, int $userId, array $input): array
    {
        $mode = $input['mode'] ?? '';
        if (!in_array($mode, ['copy', 'move', 'merge'], true)) {
            throw new InvalidArgumentException('Choose copy, move or merge.');
        }
        $target = self::id($input['target_group_id'] ?? null);
        $uncategorized = ($input['source_group_id'] ?? null) === 'uncategorized';
        $source = !$uncategorized && isset($input['source_group_id']) && $input['source_group_id'] !== ''
            ? self::id($input['source_group_id']) : null;
        if ($mode !== 'copy' && !$source && !$uncategorized) { throw new InvalidArgumentException('Select the source group.'); }
        if ($uncategorized && $mode === 'merge') { throw new InvalidArgumentException('Uncategorized is not a group to merge.'); }
        $scope = $input['scope'] ?? 'selected';
        if (!in_array($scope,['selected','group'],true) || ($scope==='group' && !$source && !$uncategorized)) { throw new InvalidArgumentException('Choose selected contacts or a source group.'); }
        if ($target === $source) { throw new InvalidArgumentException('Choose a different destination group.'); }
        $deleteSource = $input['delete_source'] ?? false;
        if (!is_bool($deleteSource) || ($deleteSource && $mode !== 'merge')) {
            throw new InvalidArgumentException('Only a merge can remove the source group.');
        }
        $ids = $mode === 'merge' || $scope === 'group' ? null : self::ids($input['ids'] ?? null);
        $pdo->beginTransaction();
        try {
            // A consistent lock order serializes opposing moves and duplicate submissions.
            $groupIds = array_values(array_unique(array_filter([$source, $target])));
            sort($groupIds, SORT_NUMERIC);
            $marks = implode(',', array_fill(0, count($groupIds), '?'));
            $q = $pdo->prepare("SELECT id,name FROM contact_groups WHERE user_id=? AND id IN ($marks) ORDER BY id FOR UPDATE");
            $q->execute(array_merge([$userId], $groupIds));
            $groups = $q->fetchAll(PDO::FETCH_ASSOC);
            if (count($groups) !== count($groupIds)) { throw new DomainException('Contact group not found or no longer available.'); }

            if ($uncategorized && $ids === null) {
                $q = $pdo->prepare('SELECT c.id,c.phone,c.phone_normalized FROM contacts c WHERE c.user_id=? AND NOT EXISTS (SELECT 1 FROM group_contacts gc WHERE gc.contact_id=c.id) ORDER BY c.id FOR UPDATE');
                $q->execute([$userId]);
            } elseif ($ids === null) {
                $q = $pdo->prepare('SELECT c.id,c.phone,c.phone_normalized FROM contacts c JOIN group_contacts gc ON gc.contact_id=c.id WHERE c.user_id=? AND gc.group_id=? ORDER BY c.id FOR UPDATE');
                $q->execute([$userId, $source]);
            } else {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $q = $pdo->prepare("SELECT id,phone,phone_normalized FROM contacts WHERE user_id=? AND id IN ($marks) ORDER BY id FOR UPDATE");
                $q->execute(array_merge([$userId], $ids));
            }
            $contacts = $q->fetchAll(PDO::FETCH_ASSOC);
            if ($scope === 'group' && count($contacts)>10000) { throw new DomainException('This group has more than 10,000 contacts. Select smaller batches to transfer safely.'); }
            if ($ids !== null && count($contacts) !== count($ids)) {
                throw new DomainException('Some selected contacts are unavailable. Refresh the list and try again.');
            }
            if ($source && $ids !== null) {
                $q = $pdo->prepare('SELECT contact_id FROM group_contacts WHERE group_id=? FOR UPDATE');
                $q->execute([$source]);
                $members = array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN), true);
                foreach ($ids as $id) {
                    if (!isset($members[$id])) { throw new DomainException('Some selected contacts are no longer in the source group. Refresh the list and try again.'); }
                }
            }
            if ($uncategorized && $ids !== null) {
                $marks = implode(',',array_fill(0,count($ids),'?'));
                $q=$pdo->prepare("SELECT contact_id FROM group_contacts WHERE contact_id IN ($marks) FOR UPDATE");
                $q->execute($ids);
                if ($q->fetchColumn()!==false) { throw new DomainException('Some contacts have already been added to a group. Refresh and try again.'); }
            }

            $q = $pdo->prepare('SELECT c.id,c.phone,c.phone_normalized FROM contacts c JOIN group_contacts gc ON gc.contact_id=c.id WHERE c.user_id=? AND gc.group_id=? ORDER BY c.id FOR UPDATE');
            $q->execute([$userId, $target]);
            $seen = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $contact) { $seen[self::duplicateKey($contact)] = true; }
            $insert = $pdo->prepare('INSERT INTO group_contacts (group_id,contact_id,created_at) VALUES (?,?,NOW())');
            $remove = $pdo->prepare('DELETE FROM group_contacts WHERE group_id=? AND contact_id=?');
            $added = 0;
            $duplicates = 0;
            $removed = 0;
            foreach ($contacts as $contact) {
                $key = self::duplicateKey($contact);
                if (isset($seen[$key])) { $duplicates++; }
                else {
                    $insert->execute([$target, $contact['id']]);
                    $seen[$key] = true;
                    $added++;
                }
                if ($mode !== 'copy' && $source) {
                    $remove->execute([$source, $contact['id']]);
                    $removed += $remove->rowCount();
                }
            }
            if ($deleteSource) {
                $q = $pdo->prepare('SELECT COUNT(*) FROM group_contacts WHERE group_id=?');
                $q->execute([$source]);
                if ((int)$q->fetchColumn() !== 0) { throw new DomainException('The source group is not empty; no changes were made.'); }
                $q = $pdo->prepare('DELETE FROM contact_groups WHERE id=? AND user_id=?');
                $q->execute([$source, $userId]);
                if ($q->rowCount() !== 1) { throw new DomainException('Source group could not be removed.'); }
            }
            $pdo->commit();
            return ['mode'=>$mode, 'matched'=>count($contacts), 'added'=>$added, 'already_present'=>$duplicates,
                'removed_from_source'=>$removed, 'source_deleted'=>$deleteSource,
                'source_group_id'=>$source, 'target_group_id'=>$target];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }
}
