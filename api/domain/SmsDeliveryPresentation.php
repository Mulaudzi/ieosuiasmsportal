<?php

// Customer-facing wording only. Never change stored states or provider diagnostics.
require_once __DIR__.'/LogicSmsDeliveryStatus.php';
final class SmsDeliveryPresentation
{
    public static function reasonCode(string $state,?string $detail,bool $submitted=true):?string
    {
        return $state==='failed'&&$submitted&&LogicSmsDeliveryStatus::isBlocked($detail)?'provider_blocked':null;
    }

    public static function label(string $state, ?string $detail = null, bool $submitted = true): string
    {
        if ($state === 'dlr_unavailable') return 'Sent - Confirmation Unavailable';
        return match ($state) {
            'sent' => 'Sent - Awaiting Confirmation',
            'pending', 'queued' => 'Waiting to send',
            'processing' => 'Sending',
            'retry' => 'Retry scheduled',
            'delivered' => 'Delivered',
            'failed' => $submitted ? 'Not Delivered' : 'Failed to send',
            'skipped' => 'Not Sent',
            default => ucfirst(str_replace('_', ' ', $state)),
        };
    }

    public static function details(string $state, ?string $detail, bool $submitted = true): string
    {
        if ($state === 'dlr_unavailable') {
            return 'Your SMS was successfully sent for delivery, but a final delivery confirmation is unavailable. This does not necessarily mean that the message was not delivered.';
        }
        if ($state === 'sent') return 'Your SMS has been successfully sent for delivery. We are awaiting confirmation from the mobile network.';
        if ($state === 'delivered') return 'Delivery confirmed by the mobile network.';
        if (self::reasonCode($state,$detail,$submitted)==='provider_blocked') return 'Blocked by the messaging provider or recipient restrictions. The exact reason is unavailable.';
        if ($state === 'failed') return $submitted
            ? 'The mobile network reported that your SMS could not be delivered.'
            : 'The SMS provider did not accept this message for delivery. It was not successfully sent.';
        if ($state === 'skipped') return 'This SMS was excluded before sending, for example because the recipient opted out.';
        return match($state){
            'pending','queued'=>'This SMS is waiting to be sent. The provider has not accepted it for delivery yet.',
            'processing'=>'This SMS is being submitted. Successful sending has not yet been confirmed.',
            'retry'=>'A retry is scheduled for an unsuccessful submission. This is not a delivery confirmation.',
            default=>'No confirmed delivery outcome is available yet.',
        };
    }
}
