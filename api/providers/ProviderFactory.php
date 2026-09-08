<?php

require_once __DIR__ . '/SmsProvider.php';
require_once __DIR__ . '/MockSmsProvider.php';
require_once __DIR__ . '/LogicSmsProvider.php';

final class ProviderFactory
{
    public static function make(): SmsProvider
    {
        return match (strtolower((string) env('SMS_GATEWAY', 'mock'))) {
            'mock' => new MockSmsProvider(),
            'logicsms' => new LogicSmsProvider(),
            default => throw new RuntimeException('Unsupported SMS_GATEWAY; automatic provider fallback is disabled'),
        };
    }
}
