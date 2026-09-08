<?php

require_once __DIR__ . '/PhoneNumber.php';

final class RecipientResolver
{
    public static function resolve(int $userId, array $source): array
    {
        $raw = [];
        foreach (($source['manual'] ?? []) as $number) $raw[] = (string) $number;

        $contactIds = array_values(array_unique(array_map('intval', $source['contact_ids'] ?? [])));
        if ($contactIds) {
            $contacts = table('contacts')->where('user_id', $userId)->whereIn('id', $contactIds)->get();
            foreach ($contacts as $contact) $raw[] = (string) ($contact['phone_normalized'] ?? $contact['phone'] ?? '');
        }

        $groupIds = array_values(array_unique(array_map('intval', $source['group_ids'] ?? [])));
        if ($groupIds) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $stmt = db()->prepare("SELECT DISTINCT c.phone_normalized, c.phone FROM contacts c JOIN group_contacts gc ON gc.contact_id=c.id JOIN contact_groups g ON g.id=gc.group_id WHERE c.user_id=? AND g.user_id=? AND g.id IN ({$placeholders})");
            $stmt->execute(array_merge([$userId, $userId], $groupIds));
            foreach ($stmt->fetchAll() as $contact) $raw[] = (string) ($contact['phone_normalized'] ?? $contact['phone'] ?? '');
        }

        $valid = [];
        $invalid = [];
        $duplicates = 0;
        foreach ($raw as $number) {
            try {
                $normalized = PhoneNumber::normalize($number);
                if (isset($valid[$normalized])) {
                    $duplicates++;
                } else {
                    $valid[$normalized] = $normalized;
                }
            } catch (InvalidArgumentException) {
                $invalid[] = $number;
            }
        }

        $optedOut = [];
        if ($valid) {
            $rows = table('opt_outs')->where('user_id', $userId)->where('channel', 'sms')->whereIn('recipient', array_values($valid))->get();
            foreach ($rows as $row) $optedOut[(string) $row['recipient']] = true;
        }
        $excluded = [];
        foreach (($source['excluded'] ?? []) as $number) {
            try { $excluded[PhoneNumber::normalize((string) $number)] = true; }
            catch (InvalidArgumentException) { /* Ignore stale client exclusions. */ }
        }
        $sendable = array_values(array_filter(
            $valid,
            fn(string $number) => !isset($optedOut[$number]) && !isset($excluded[$number])
        ));

        return [
            'entered_count' => count($raw),
            'valid_count' => count($valid),
            'invalid_count' => count($invalid),
            'duplicate_count' => $duplicates,
            'opted_out_count' => count($optedOut),
            'excluded_count' => count(array_intersect_key($valid, $excluded)),
            'sendable_count' => count($sendable),
            'recipients' => $sendable,
            'invalid' => array_slice($invalid, 0, 100),
        ];
    }
}
