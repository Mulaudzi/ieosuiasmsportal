<?php

final class SmsPricing
{
    public static function pricePerSegment(): float
    {
        $raw = trim((string) env('SMS_PRICE_PER_SEGMENT', ''));
        if ($raw === '' || !is_numeric($raw) || (float) $raw <= 0) {
            throw new RuntimeException('SMS_PRICE_PER_SEGMENT must be configured to a positive amount');
        }
        return round((float) $raw, 4);
    }

    public static function estimate(int $recipients, int $segmentsPerMessage): float
    {
        return round($recipients * $segmentsPerMessage * self::pricePerSegment(), 4);
    }
}
