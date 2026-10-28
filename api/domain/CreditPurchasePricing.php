<?php

final class CreditPurchasePricing
{
    public static function rules(): array
    {
        static $rules;
        return $rules ??= json_decode(file_get_contents(__DIR__.'/credit-purchase-pricing.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function quote(mixed $credits, string $method): array
    {
        $rules = self::rules();
        if ((!is_int($credits) && !(is_string($credits) && preg_match('/^[0-9]+$/D', $credits))) ||
            (int)$credits < $rules['minimum'] || (int)$credits > $rules['maximum']) {
            throw new InvalidArgumentException('Choose a whole number of credits between '.number_format($rules['minimum']).' and '.number_format($rules['maximum']).'. For purchases above '.number_format($rules['maximum']).' credits, contact sales at '.$rules['sales_email'].'.');
        }
        if (!in_array($method, ['payos', 'eft'], true)) throw new InvalidArgumentException('Choose a supported payment method.');
        $credits = (int)$credits;
        if ($method === 'payos' && $credits > $rules['online_maximum']) {
            throw new InvalidArgumentException('Purchases above '.number_format($rules['online_maximum']).' credits require direct bank-transfer EFT. Online card and Instant EFT checkout are not available for this quantity.');
        }
        foreach ($rules['tiers'] as $tier) {
            if ($credits <= $tier['max']) { $rate = $tier['cents']; break; }
        }
        $subtotal = $credits * $rate;
        // Half the normal Instant EFT fee (2%, minimum R2), rounded down to a cent.
        $discount = $method === 'eft' ? intdiv(max(200, (int)round($subtotal * .02)), 2) : 0;
        return ['pricing_version'=>$rules['version'], 'credits'=>$credits, 'unit_cents'=>$rate,
            'subtotal_cents'=>$subtotal, 'discount_cents'=>$discount, 'total_cents'=>$subtotal-$discount,
            'payment_method'=>$method, 'vat_cents'=>0];
    }
}
