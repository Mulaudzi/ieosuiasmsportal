<?php

final class Config
{
    public static function required(string $key): string
    {
        $value = trim((string) env($key, ''));
        if ($value === '') {
            throw new RuntimeException("Required configuration is missing: {$key}");
        }
        return $value;
    }

    public static function isProduction(): bool
    {
        return strtolower((string) env('APP_ENV', 'production')) === 'production';
    }
}
