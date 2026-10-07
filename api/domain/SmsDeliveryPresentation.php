<?php

// Customer-facing wording only. Never change stored states or provider diagnostics.
final class SmsDeliveryPresentation
{
    public static function label(string $state, ?string $detail = null): string
    {
        if ($state === 'dlr_unavailable' || ($state === 'sent' && str_starts_with((string)$detail, 'DLR lookup:'))) return 'Delivery Status Unavailable';
        return match ($state) {
            'sent' => 'Delivery Confirmation Pending',
            'pending', 'queued' => 'Waiting to send',
            'processing' => 'Sending',
            'retry' => 'Retry scheduled',
            'delivered' => 'Delivered',
            'failed' => 'Not Delivered',
            'skipped' => 'Skipped',
            default => ucfirst(str_replace('_', ' ', $state)),
        };
    }

    public static function details(string $state, ?string $detail): string
    {
        if ($state === 'dlr_unavailable' || ($state === 'sent' && str_starts_with((string)$detail, 'DLR lookup:'))) {
            return 'SMS was successfully sent, but the network did not provide a delivery status. The recipient may have received it.';
        }
        if ($state === 'sent') return 'SMS was successfully sent, but delivery confirmation is still pending.';
        if ($state === 'delivered') return 'Network confirmed the SMS was delivered.';
        if ($state === 'failed') return 'Network reported that the SMS could not be delivered.';
        if ($state === 'skipped') return 'SMS was not sent, for example because the recipient had opted out.';
        return $detail ?: 'No additional delivery details';
    }
}
