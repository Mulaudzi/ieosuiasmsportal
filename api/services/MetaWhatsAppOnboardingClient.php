<?php
declare(strict_types=1);
require_once __DIR__.'/../domain/MetaWhatsAppConnectConfig.php';

/** Read-only Graph operations and one authorization-code exchange. Never sends messages. */
final class MetaWhatsAppOnboardingClient
{
    public function __construct(private MetaWhatsAppConnectConfig $config, private $transport = null) {}

    private function request(string $path, array $query, string $bearer = ''): array
    {
        $url = 'https://graph.facebook.com/'.$this->config->graphVersion.'/'.$path;
        if ($bearer !== '') $query['appsecret_proof'] = hash_hmac('sha256', $bearer, $this->config->appSecret);
        $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        try {
            if ($this->transport !== null) {
                $result = ($this->transport)($url, $bearer);
            } else {
                if (!function_exists('curl_init')) throw new RuntimeException();
                $curl = curl_init($url);
                $body = '';
                curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXREDIRS=>0,
                    CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true,
                    CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                    CURLOPT_HTTPHEADER=>array_values(array_filter(['Accept: application/json',
                        $bearer !== '' ? 'Authorization: Bearer '.$bearer : null])),
                    CURLOPT_WRITEFUNCTION=>static function($handle, string $chunk) use (&$body): int {
                        if (strlen($body) + strlen($chunk) > 1048576) return 0;
                        $body .= $chunk; return strlen($chunk);
                    }]);
                $ok = curl_exec($curl);
                $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
                if ($ok === false || $status !== 200) throw new RuntimeException();
                $result = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            }
            if (!is_array($result) || isset($result['error'])) throw new RuntimeException();
            return $result;
        } catch (Throwable) {
            // No URL, token, authorization code or provider error text reaches logs/browser.
            throw new MetaWhatsAppConnectException('Meta authorization could not be verified. Start a new connection attempt.', 502);
        }
    }

    public static function id(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9]{5,32}$/D', $value)) {
            throw new MetaWhatsAppConnectException('Meta returned an invalid account identifier.', 502);
        }
        return $value;
    }

    private static function label(mixed $value): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', is_string($value) ? $value : '') ?? '', 0, 200);
    }

    /** Verify app, token type, expiry and the intersection of both permissions' WABA grants. */
    public function inspect(string $token, int $now): array
    {
        $debug = $this->request('debug_token', ['input_token'=>$token], $this->config->appId.'|'.$this->config->appSecret)['data'] ?? [];
        if (($debug['is_valid'] ?? false) !== true || (string)($debug['app_id'] ?? '') !== $this->config->appId
            || ($debug['type'] ?? '') !== 'SYSTEM_USER') {
            throw new MetaWhatsAppConnectException('Meta did not grant the expected business authorization.', 422);
        }
        $expiry = null;
        foreach (['expires_at', 'data_access_expires_at'] as $key) {
            if (!isset($debug[$key]) || !is_int($debug[$key]) || $debug[$key] < 0) {
                if ($key === 'expires_at') throw new MetaWhatsAppConnectException('Meta did not confirm token expiry.', 422);
                continue;
            }
            if ($debug[$key] > 0) {
                if ($debug[$key] <= $now) throw new MetaWhatsAppConnectException('Meta authorization has expired. Reconnect WhatsApp.', 422);
                $expiry = $expiry === null ? $debug[$key] : min($expiry, $debug[$key]);
            }
        }
        $sets = [];
        foreach (['whatsapp_business_management','whatsapp_business_messaging'] as $scope) {
            if (!in_array($scope, $debug['scopes'] ?? [], true)) throw new MetaWhatsAppConnectException('Both WhatsApp permissions are required.', 422);
            $ids = [];
            foreach ($debug['granular_scopes'] ?? [] as $grant) {
                if (($grant['scope'] ?? '') === $scope) {
                    foreach ($grant['target_ids'] ?? [] as $id) $ids[] = self::id($id);
                }
            }
            $sets[] = array_unique($ids);
        }
        $wabas = array_values(array_intersect($sets[0], $sets[1]));
        if (!$wabas || count($wabas) > 20) throw new MetaWhatsAppConnectException('Meta did not confirm a bounded set of shared WhatsApp accounts.', 422);
        return ['wabas'=>$wabas, 'expires_at'=>$expiry];
    }

    public function discover(string $token, array $wabas): array
    {
        $options = [];
        foreach ($wabas as $waba) {
            $waba = self::id($waba);
            $account = $this->request($waba, ['fields'=>'id,name'], $token);
            if (($account['id'] ?? '') !== $waba) throw new MetaWhatsAppConnectException('Meta account verification failed.', 422);
            $after = null;
            $seen = [];
            for ($page = 0; $page < 5; $page++) {
                $query = ['fields'=>'id,display_phone_number,verified_name', 'limit'=>'100'];
                if ($after !== null) $query['after'] = $after;
                $phones = $this->request($waba.'/phone_numbers', $query, $token);
                if (!isset($phones['data']) || !is_array($phones['data'])) throw new MetaWhatsAppConnectException('Meta phone verification failed.', 422);
                foreach ($phones['data'] as $phone) {
                    $id = self::id($phone['id'] ?? null);
                    $options[$id] = ['waba_id'=>$waba, 'phone_number_id'=>$id, 'business_name'=>self::label($account['name'] ?? ''),
                        'display_phone_number'=>self::label($phone['display_phone_number'] ?? ''), 'verified_name'=>self::label($phone['verified_name'] ?? '')];
                    if (count($options) > 100) throw new MetaWhatsAppConnectException('Too many numbers were shared. Share fewer accounts and retry.', 422);
                }
                if (empty($phones['paging']['next'])) break;
                $after = $phones['paging']['cursors']['after'] ?? null;
                if (!is_string($after) || strlen($after) > 2048 || isset($seen[$after]) || $page === 4) {
                    throw new MetaWhatsAppConnectException('Meta account listing could not be completed.', 422);
                }
                $seen[$after] = true;
                // Never follow the provider-supplied next URL; only use the cursor on the fixed edge.
            }
        }
        if (!$options) throw new MetaWhatsAppConnectException('No authorized WhatsApp phone numbers were found.', 422);
        return array_values($options);
    }

    public function exchange(string $code, int $now): array
    {
        if ($code === '' || strlen($code) > 4096 || preg_match('/[\x00-\x20\x7F]/', $code)) {
            throw new MetaWhatsAppConnectException('Invalid Meta authorization response.', 422);
        }
        $result = $this->request('oauth/access_token', ['client_id'=>$this->config->appId,
            'client_secret'=>$this->config->appSecret, 'redirect_uri'=>$this->config->redirectUri, 'code'=>$code]);
        $token = $result['access_token'] ?? null;
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9._~-]{40,8192}$/D', $token)) throw new MetaWhatsAppConnectException('Meta did not return valid business credentials.', 422);
        $verified = $this->inspect($token, $now);
        return ['token'=>$token, 'expires_at'=>$verified['expires_at'], 'options'=>$this->discover($token, $verified['wabas'])];
    }
}
