<?php
declare(strict_types=1);

final class SmsIntegrationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422) {
        parent::__construct($message, $status);
    }
}

final class SmsIntegrationPolicy
{
    public const SCOPES = ['sms:quote', 'sms:send', 'sms:schedule', 'sms:read', 'sms:cancel', 'wallet:read'];
    public const PRODUCTS = ['events', 'tenant-management', 'security-os', 'website', 'custom'];
    public static function text(mixed $value, string $field, int $max, bool $empty = false): string {
        if (!is_string($value) || (!$empty && trim($value) === '') || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new SmsIntegrationException('INVALID_REQUEST', "Invalid {$field}.");
        return trim($value);
    }
    public static function fields(array $input, array $allowed): void {
        if (array_diff(array_keys($input), $allowed)) throw new SmsIntegrationException('INVALID_REQUEST', 'Request contains unsupported fields. Account, payer and sender are assigned by the server.');
    }
    public static function scopes(mixed $scopes): array {
        if (!is_array($scopes) || !array_is_list($scopes) || !$scopes || count($scopes) > count(self::SCOPES)) throw new SmsIntegrationException('INVALID_SCOPES', 'Choose at least one supported permission.');
        foreach ($scopes as $scope) if (!is_string($scope) || !in_array($scope, self::SCOPES, true)) throw new SmsIntegrationException('INVALID_SCOPES', 'Unsupported API permission.');
        $scopes = array_values(array_unique($scopes)); sort($scopes); return $scopes;
    }
    public static function integer(mixed $value, string $field, int $min, int $max): int {
        if (!is_int($value) || $value < $min || $value > $max) throw new SmsIntegrationException('INVALID_REQUEST', "{$field} must be an integer between {$min} and {$max}.");
        return $value;
    }
    public static function message(array $input): array {
        self::fields($input, ['name', 'content', 'recipients', 'include_opt_out', 'scheduled_at', 'external_reference', 'max_credits']);
        $content = $input['content'] ?? null;
        if (!is_string($content) || trim($content) === '' || strlen($content) > 12000 || str_contains($content, "\0")) throw new SmsIntegrationException('INVALID_REQUEST', 'Message content is required and must fit the supported SMS size.');
        if (preg_match('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', $content)) throw new SmsIntegrationException('PERSONALIZE_ON_SERVER', 'Personalize the message in your application before submitting it. API keys do not grant contact-profile access.');
        $numbers = $input['recipients'] ?? null;
        if (!is_array($numbers) || !array_is_list($numbers) || count($numbers) < 1 || count($numbers) > 1000) throw new SmsIntegrationException('INVALID_RECIPIENTS', 'Provide 1 to 1,000 phone numbers.');
        foreach ($numbers as $number) if (!is_string($number) || strlen($number) > 40) throw new SmsIntegrationException('INVALID_RECIPIENTS', 'Each recipient must be a phone-number string.');
        $include = array_key_exists('include_opt_out', $input) ? $input['include_opt_out'] : false;
        if (!is_bool($include)) throw new SmsIntegrationException('INVALID_REQUEST', 'include_opt_out must be true or false.');
        $schedule = $input['scheduled_at'] ?? null;
        if ($schedule !== null && (!is_string($schedule) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $schedule))) throw new SmsIntegrationException('INVALID_SCHEDULE', 'Use an ISO 8601 timestamp with seconds and an explicit timezone.');
        return [
            'name' => self::text(array_key_exists('name', $input) ? $input['name'] : '', 'name', 100, true), 'content' => $content,
            'recipients' => ['manual' => $numbers], 'include_opt_out' => $include,
            'scheduled_at' => $schedule, 'send_now' => $schedule === null,
            'external_reference' => self::text(array_key_exists('external_reference', $input) ? $input['external_reference'] : '', 'external_reference', 120, true),
            'max_credits' => array_key_exists('max_credits', $input) ? self::integer($input['max_credits'], 'max_credits', 1, 6000) : null,
        ];
    }
    public static function fingerprint(array $message): string {
        $numbers = $message['recipients']['manual']; sort($numbers, SORT_STRING);
        $message['recipients']['manual'] = $numbers;
        return hash('sha256', json_encode($message, JSON_THROW_ON_ERROR));
    }
}
