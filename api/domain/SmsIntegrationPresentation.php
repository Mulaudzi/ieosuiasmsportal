<?php
declare(strict_types=1);

/** Public integration contract: never serialize database rows or worker diagnostics. */
final class SmsIntegrationPresentation
{
    public static function result(array $request, array $counts): array
    {
        $total = (int)$counts['recipients'];
        $sent = (int)$counts['accepted'];
        $notSent = (int)$counts['not_sent'];
        // A pending/unknown recipient always keeps the whole job open, even if
        // its campaign lifecycle or cached counters already look terminal.
        $complete = $total > 0 && $sent + $notSent === $total;
        $status = !$complete ? 'queued' : ($sent === $total ? 'sent' : ($sent > 0 ? 'partially_sent' : 'not_sent'));
        $scheduled = $request['scheduled_at'];
        if ($scheduled !== null) $scheduled = (new DateTimeImmutable($scheduled, new DateTimeZone('Africa/Johannesburg')))->format(DateTimeInterface::ATOM);
        return [
            'id' => $request['id'],
            'reference' => self::reference($request['id']),
            'external_reference' => $request['external_reference'],
            'status' => $status,
            // Field-name compatibility only; state is now the same public enum.
            'state' => $status,
            'complete' => $complete,
            'scheduled_at' => $scheduled,
            'created_at' => (int)$request['created_at'],
            'recipients' => $total,
            'credits_required' => (int)$request['admitted_credits'],
            'acceptance_is_not_delivery' => true,
        ];
    }

    public static function reference(string $id): string { return 'API-'.strtoupper($id); }

    public static function campaignName(string $connection, string $requestId, int $time): string
    {
        $date = (new DateTimeImmutable('@'.$time))->setTimezone(new DateTimeZone('Africa/Johannesburg'))->format('d M Y H:i');
        $suffix = ' | '.$date.' SAST | '.strtoupper(substr($requestId, 0, 12));
        $prefix = 'API | ';
        // Names are byte bounded by the existing campaign contract. Truncate
        // at Unicode boundaries, never in the middle of a multibyte character.
        $label = '';
        foreach (preg_split('//u', $connection, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (strlen($prefix.$label.$character.$suffix) > 100) break;
            $label .= $character;
        }
        return $prefix.$label.$suffix;
    }
}
