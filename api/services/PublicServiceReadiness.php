<?php
declare(strict_types=1);

/** Public health is a strict allowlist, never a dump of private worker metadata. */
final class PublicServiceReadiness
{
    public static function report(array $rows, array $required, ?int $now = null): array
    {
        $now ??= time(); $byName = [];
        foreach ($rows as $row) $byName[(string)$row['service_name']] = $row;
        $services = []; $ready = true;
        foreach ($required as $name => $maxAge) {
            $last = $byName[$name]['last_seen_at'] ?? null;
            try { $timestamp = is_string($last) && $last !== '' ? (new DateTimeImmutable($last, new DateTimeZone('Africa/Johannesburg')))->getTimestamp() : null; }
            catch (Exception) { $timestamp = null; }
            $age = $timestamp === null ? null : max(0, $now - $timestamp);
            $healthy = $age !== null && $age <= $maxAge;
            if (!$healthy) $ready = false;
            $services[$name] = ['healthy'=>$healthy, 'last_seen_at'=>is_string($last) ? $last : null, 'age_seconds'=>$age, 'max_age_seconds'=>$maxAge];
        }
        return ['status'=>$ready ? 'ready' : 'not_ready', 'database'=>true, 'services'=>$services, 'timestamp'=>date('c', $now)];
    }
}
