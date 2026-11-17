<?php
declare(strict_types=1);

/** Scheduling rules are independent of PHP's host timezone and have no side effects. */
final class SmsSchedule
{
    public static function fromInput(array $input, ?int $now = null): array
    {
        $sendNow = $input['send_now'] ?? false;
        if (!is_bool($sendNow)) throw new InvalidArgumentException('send_now must be true or false');
        $value = $input['scheduled_at'] ?? '';
        if (!is_string($value)) throw new InvalidArgumentException('scheduled_at must be a date and time');
        $value = trim($value);
        if ($sendNow && $value !== '') throw new InvalidArgumentException('Choose either Send now or a scheduled time, not both.');
        $schedule = null;
        if ($value !== '') {
            $date = self::parse($value);
            if ($date === null || $date->getTimestamp() <= ($now ?? time())) throw new InvalidArgumentException('scheduled_at must be a valid future date');
            $schedule = $date->setTimezone(new DateTimeZone('Africa/Johannesburg'))->format('Y-m-d H:i:s');
        }
        return ['send_now'=>$sendNow, 'scheduled_at'=>$schedule, 'state'=>$schedule !== null ? 'scheduled' : ($sendNow ? 'queued' : 'draft')];
    }

    public static function isDue(array $campaign, ?int $now = null): bool
    {
        $value = $campaign['scheduled_at'] ?? null;
        if ($value === null || $value === '') return true;
        if (!is_string($value)) return false;
        $date = self::parse($value);
        return $date !== null && $date->getTimestamp() <= ($now ?? time());
    }

    private static function parse(string $value): ?DateTimeImmutable
    {
        // Legacy clients without an offset continue to mean South African time.
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})?$/D', $value, $m)) return null;
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59 || (int)($m[6] ?? 0) > 59) return null;
        $offset = $m[7] ?? '';
        if ($offset !== '' && $offset !== 'Z' && ((int)substr($offset,1,2) > 14 || (int)substr($offset,4,2) > 59 || ((int)substr($offset,1,2) === 14 && (int)substr($offset,4,2) !== 0))) return null;
        try { return new DateTimeImmutable($value, new DateTimeZone('Africa/Johannesburg')); }
        catch (Exception) { return null; }
    }
}
