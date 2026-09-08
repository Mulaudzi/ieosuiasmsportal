<?php

final class PaymentState
{
    /** Completed payments cannot be downgraded by delayed or replayed callbacks. */
    public static function merge(?string $current, string $incoming): string
    {
        $current = strtolower((string)$current);
        $incoming = strtolower($incoming);
        if ($current === 'completed' && $incoming !== 'refunded') return 'completed';
        if ($current === 'refunded') return 'refunded';
        return $incoming;
    }
}
