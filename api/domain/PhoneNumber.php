<?php

final class PhoneNumber
{
    public static function normalize(string $input): string
    {
        $value = preg_replace('/[^0-9+]/', '', trim($input));
        if ($value === '' || substr_count($value, '+') > 1 || (str_contains($value, '+') && $value[0] !== '+')) {
            throw new InvalidArgumentException('Invalid phone number');
        }

        if (preg_match('/^0(6|7|8)[0-9]{8}$/', $value)) {
            $value = '+27' . substr($value, 1);
        } elseif (preg_match('/^27(6|7|8)[0-9]{8}$/', $value)) {
            $value = '+' . $value;
        } elseif ($value[0] !== '+') {
            throw new InvalidArgumentException('International numbers must include a country code prefixed with +');
        }

        if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $value)) {
            throw new InvalidArgumentException('Phone number must be valid E.164');
        }
        return $value;
    }

    public static function mask(string $phone): string
    {
        return strlen($phone) <= 6 ? '***' : substr($phone, 0, 3) . str_repeat('*', max(3, strlen($phone) - 7)) . substr($phone, -4);
    }
}
