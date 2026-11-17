<?php
declare(strict_types=1);

/** Direct Meta transport foundation. Not registered with the SMS dispatcher. */
final class MetaWhatsAppProvider
{
    private ?Closure $transport;

    public function __construct(private array $config, ?callable $transport = null)
    {
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public static function configuration(): array
    {
        $result = [];
        foreach (['WHATSAPP_SEND_ENABLED', 'WHATSAPP_WEBHOOK_ENABLED', 'WHATSAPP_META_GRAPH_VERSION', 'WHATSAPP_META_PHONE_NUMBER_ID',
            'WHATSAPP_META_WABA_ID', 'WHATSAPP_META_ACCESS_TOKEN', 'WHATSAPP_META_APP_SECRET',
            'WHATSAPP_META_VERIFY_TOKEN'] as $key) {
            $value = function_exists('env') ? env($key, '') : getenv($key);
            $result[$key] = is_string($value) ? trim($value) : '';
        }
        return $result;
    }

    /** Configuration presence only; this does not contact Meta or prove token validity. */
    public static function readiness(array $config): array
    {
        $missing = [];
        foreach (['WHATSAPP_META_GRAPH_VERSION', 'WHATSAPP_META_PHONE_NUMBER_ID', 'WHATSAPP_META_WABA_ID',
            'WHATSAPP_META_ACCESS_TOKEN', 'WHATSAPP_META_APP_SECRET', 'WHATSAPP_META_VERIFY_TOKEN'] as $key) {
            if (!is_string($config[$key] ?? null) || trim($config[$key]) === '') $missing[] = $key;
        }
        $invalid = [];
        foreach (['WHATSAPP_META_PHONE_NUMBER_ID', 'WHATSAPP_META_WABA_ID'] as $key) {
            if (!empty($config[$key]) && !preg_match('/^[1-9][0-9]{0,31}$/D', $config[$key])) $invalid[] = $key;
        }
        if (!empty($config['WHATSAPP_META_GRAPH_VERSION']) && !preg_match('/^v[1-9][0-9]*\.0$/D', $config['WHATSAPP_META_GRAPH_VERSION'])) {
            $invalid[] = 'WHATSAPP_META_GRAPH_VERSION';
        }
        if (!empty($config['WHATSAPP_META_ACCESS_TOKEN']) && preg_match('/[\x00-\x20\x7f]/', $config['WHATSAPP_META_ACCESS_TOKEN'])) {
            $invalid[] = 'WHATSAPP_META_ACCESS_TOKEN';
        }
        return ['configuration_complete' => !$missing && !$invalid, 'missing_keys' => $missing,
            'invalid_keys' => $invalid, 'sending_enabled' => ($config['WHATSAPP_SEND_ENABLED'] ?? '') === 'true',
            'network_checked' => false];
    }

    /** Body-text parameters only for this initial adapter. Caller must enforce consent and ownership. */
    public function sendTemplate(string $destination, string $template, string $language, array $bodyParameters = []): array
    {
        if (($this->config['WHATSAPP_SEND_ENABLED'] ?? '') !== 'true') return self::failure('WHATSAPP_SENDING_DISABLED', false);
        if (!self::readiness($this->config)['configuration_complete']) return self::failure('WHATSAPP_NOT_CONFIGURED', false);
        if (!preg_match('/^\+?[1-9][0-9]{6,14}$/D', $destination)
            || !preg_match('/^[a-z0-9_]{1,512}$/D', $template)
            || !preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D', $language)
            || !array_is_list($bodyParameters) || count($bodyParameters) > 100) {
            return self::failure('WHATSAPP_INVALID_TEMPLATE_REQUEST', false);
        }
        $parameters = [];
        foreach ($bodyParameters as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 4096 || !preg_match('//u', $value)) {
                return self::failure('WHATSAPP_INVALID_TEMPLATE_REQUEST', false);
            }
            $parameters[] = ['type' => 'text', 'text' => $value];
        }
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
            'to' => ltrim($destination, '+'), 'type' => 'template',
            'template' => ['name' => $template, 'language' => ['code' => $language]]];
        if ($parameters) $payload['template']['components'] = [['type' => 'body', 'parameters' => $parameters]];
        $url = 'https://graph.facebook.com/'.$this->config['WHATSAPP_META_GRAPH_VERSION'].'/'
            .$this->config['WHATSAPP_META_PHONE_NUMBER_ID'].'/messages';
        $headers = ['Authorization: Bearer '.$this->config['WHATSAPP_META_ACCESS_TOKEN'], 'Content-Type: application/json'];
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $response = $this->transport ? ($this->transport)($url, $headers, $body) : self::post($url, $headers, $body);
            if (!is_array($response)) return self::failure('WHATSAPP_SUBMISSION_UNCONFIRMED', true);
            return self::parseResponse((string)($response['body'] ?? ''), (int)($response['status'] ?? 0), (int)($response['errno'] ?? 0));
        } catch (Throwable) {
            // Never expose raw HTTP bodies, tokens or transport exception text.
            return self::failure('WHATSAPP_SUBMISSION_UNCONFIRMED', true);
        }
    }

    private static function post(string $url, array $headers, string $body): array
    {
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('WhatsApp transport unavailable');
        try {
            $output = ''; $tooLarge = false;
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$output, &$tooLarge): int {
                    if (strlen($output) + strlen($chunk) > 262144) { $tooLarge = true; return 0; }
                    $output .= $chunk; return strlen($chunk);
                }]);
            curl_exec($handle);
            return ['status' => (int)curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'errno' => $tooLarge ? CURLE_WRITE_ERROR : curl_errno($handle), 'body' => $output];
        } finally { curl_close($handle); }
    }

    public static function parseResponse(string $body, int $status, int $errno = 0): array
    {
        if ($errno !== 0 || strlen($body) > 262144) return self::failure('WHATSAPP_SUBMISSION_UNCONFIRMED', true);
        try { $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { return self::failure('WHATSAPP_SUBMISSION_UNCONFIRMED', true); }
        if (!is_array($data)) return self::failure('WHATSAPP_SUBMISSION_UNCONFIRMED', true);
        $messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
        $id = is_array($messages[0] ?? null) ? ($messages[0]['id'] ?? null) : null;
        if ($status >= 200 && $status < 300 && !isset($data['error'])
            && ($data['messaging_product'] ?? '') === 'whatsapp' && count($messages) === 1
            && is_string($id) && preg_match('/^wamid\.[A-Za-z0-9+\/_=.-]{1,1024}$/D', $id)) {
            return ['success' => true, 'provider' => 'meta-whatsapp', 'message_id' => $id,
                'status' => 'accepted', 'uncertain' => false, 'retryable' => false];
        }
        // Only an explicit structured client error confirms rejection. Never auto-retry a POST.
        if ($status >= 400 && $status < 500 && is_int($data['error']['code'] ?? null) && !isset($data['messages'])) {
            return self::failure('WHATSAPP_PROVIDER_REJECTED', false) + ['provider_error_code' => $data['error']['code']];
        }
        return self::failure('WHATSAPP_SUBMISSION_UNCONFIRMED', true);
    }

    private static function failure(string $code, bool $uncertain): array
    {
        return ['success' => false, 'provider' => 'meta-whatsapp', 'error_code' => $code,
            'uncertain' => $uncertain, 'retryable' => false];
    }
}
