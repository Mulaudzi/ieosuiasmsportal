<?php

interface SmsProvider
{
    public function name(): string;
    public function send(string $destination, string $content, ?string $senderId = null, ?string $clientReference = null): array;
}
