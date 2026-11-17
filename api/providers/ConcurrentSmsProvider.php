<?php

require_once __DIR__.'/SmsProvider.php';

/** Each handle is one recipient POST, not a provider bulk-send request. */
interface ConcurrentSmsProvider extends SmsProvider
{
    /** Create an unexecuted request, or return a known pre-network failure. */
    public function createSubmissionHandle(array $message): CurlHandle|array;
    public function parseSubmissionResponse(string $body, int $curlResult, int $httpStatus): array;
}
