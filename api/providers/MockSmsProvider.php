<?php

final class MockSmsProvider implements SmsProvider
{
    public function name(): string { return 'mock'; }
    public function send(string $destination, string $content, ?string $senderId = null, ?string $clientReference = null): array
    {
        if (strtolower((string) env('APP_ENV', 'production')) !== 'testing') {
            throw new RuntimeException('Mock provider is only available in testing');
        }
        return ['success' => true, 'message_id' => 'mock-' . bin2hex(random_bytes(8)), 'status' => 'queued'];
    }
}
