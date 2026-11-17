<?php
declare(strict_types=1);

final class MetaWhatsAppConnectException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

/** Dedicated customer onboarding settings. The owner's sending token is never used. */
final class MetaWhatsAppConnectConfig
{
    public readonly string $origin;
    public readonly string $key;

    public function __construct(
        public readonly string $appId,
        public readonly string $appSecret,
        public readonly string $graphVersion,
        public readonly string $configId,
        public readonly string $redirectUri,
        string $tokenKey,
        public readonly string $featureType = '',
        public readonly bool $adminPilotOnly = false,
        public readonly string $pilotEmails = '',
    ) {
        if (!preg_match('/^[0-9]{5,32}$/D', $appId) || !preg_match('/^[0-9]{5,32}$/D', $configId)
            || !preg_match('/^[a-f0-9]{32}$/Di', $appSecret) || !preg_match('/^v[0-9]{2}\.0$/D', $graphVersion)) {
            throw new MetaWhatsAppConnectException('WhatsApp connection setup is incomplete.', 503);
        }
        $url = parse_url($redirectUri);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || isset($url['port'])
            || ($url['path'] ?? '') !== '/api/whatsapp-meta-oauth.php') {
            throw new MetaWhatsAppConnectException('WhatsApp connection setup is incomplete.', 503);
        }
        $key = base64_decode($tokenKey, true);
        if ($key === false || strlen($key) !== 32 || !function_exists('openssl_encrypt')) {
            throw new MetaWhatsAppConnectException('WhatsApp credential protection is unavailable.', 503);
        }
        $this->origin = 'https://'.$url['host'];
        $this->key = $key;
        if (!in_array($featureType, ['', 'whatsapp_business_app_onboarding'], true)) {
            throw new MetaWhatsAppConnectException('WhatsApp signup flow is not configured.', 503);
        }
    }

    public static function fromEnvironment(): self
    {
        if (strtolower((string)env('WHATSAPP_CONNECT_ENABLED', 'false')) !== 'true') {
            throw new MetaWhatsAppConnectException('Customer WhatsApp connections are awaiting setup.', 503);
        }
        return new self((string)env('WHATSAPP_CONNECT_APP_ID', ''), (string)env('WHATSAPP_META_APP_SECRET', ''),
            (string)env('WHATSAPP_META_GRAPH_VERSION', ''), (string)env('WHATSAPP_CONNECT_CONFIG_ID', ''),
            (string)env('WHATSAPP_CONNECT_REDIRECT_URI', ''), (string)env('WHATSAPP_CONNECT_TOKEN_KEY', ''),
            (string)env('WHATSAPP_CONNECT_FEATURE_TYPE', ''),
            strtolower((string)env('WHATSAPP_CONNECT_ADMIN_PILOT_ONLY', 'false')) === 'true',
            (string)env('WHATSAPP_CONNECT_PILOT_EMAILS', ''));
    }

    public function assertPilotAccess(bool $isAdmin, string $email = ''): void
    {
        $allowed = array_filter(array_map(static fn(string $value): string => strtolower(trim($value)), explode(',', $this->pilotEmails)));
        if ($this->adminPilotOnly && !$isAdmin && ($email === '' || !in_array(strtolower($email), $allowed, true))) {
            throw new MetaWhatsAppConnectException('Customer WhatsApp connections are awaiting setup.', 503);
        }
    }

    public function authorizeUrl(string $state): string
    {
        $extras = ['version'=>'v4', 'sessionInfoVersion'=>'3'];
        if ($this->featureType !== '') $extras['featureType'] = $this->featureType;
        return 'https://business.facebook.com/messaging/whatsapp/onboard/?'.http_build_query([
            'app_id'=>$this->appId, 'config_id'=>$this->configId, 'redirect_uri'=>$this->redirectUri,
            'state'=>$state, 'response_type'=>'code',
            'extras'=>json_encode($extras, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function seal(array $value, string $context): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt(json_encode($value, JSON_THROW_ON_ERROR), 'aes-256-gcm', $this->key,
            OPENSSL_RAW_DATA, $iv, $tag, $context, 16);
        if ($encrypted === false) throw new MetaWhatsAppConnectException('Could not protect WhatsApp credentials.', 503);
        return base64_encode($iv.$tag.$encrypted);
    }

    public function unseal(string $value, string $context): array
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 29) throw new MetaWhatsAppConnectException('WhatsApp credentials are unavailable.', 503);
        $json = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16), $context);
        if ($json === false) throw new MetaWhatsAppConnectException('WhatsApp credentials are unavailable.', 503);
        $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new MetaWhatsAppConnectException('WhatsApp credentials are unavailable.', 503);
        return $value;
    }
}
