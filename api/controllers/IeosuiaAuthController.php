<?php

final class IeosuiaAuthController
{
    private const FLOW_COOKIE = 'sms_ieosuia_oauth_flow';

    public function start(): void
    {
        $this->session();
        $type = ($_GET['account_type'] ?? 'customer') === 'admin' ? 'admin' : 'customer';
        $screenHint = (($_GET['screen_hint'] ?? '') === 'signup' && $type === 'customer') ? 'signup' : 'login';
        $verifier = $this->b64(random_bytes(48));
        $state = $this->b64(random_bytes(32));
        $pending = ['verifier' => $verifier, 'state' => $state, 'account_type' => $type, 'created_at' => time()];
        $_SESSION['ieosuia_oauth'] = $pending;
        $this->storeFlowCookie($pending);

        $query = http_build_query([
            'client_id' => env('AUTH_CLIENT_ID', 'sms-web'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'account_type' => $type,
            'screen_hint' => $screenHint,
            'state' => $state,
            'code_challenge' => $this->b64(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        header('Location: '.$this->issuer().'/oauth/authorize?'.$query, true, 302);
        exit;
    }

    public function callback(): void
    {
        $this->session();
        $pending = is_array($_SESSION['ieosuia_oauth'] ?? null) ? $_SESSION['ieosuia_oauth'] : $this->readFlowCookie();
        unset($_SESSION['ieosuia_oauth']);
        $this->clearFlowCookie();
        if (!is_array($pending) || time() - (int) ($pending['created_at'] ?? 0) > 600 || !isset($_GET['state'], $_GET['code']) || !hash_equals((string) ($pending['state'] ?? ''), (string) $_GET['state'])) {
            $this->fail('invalid_response');
        }

        $tokens = $this->request('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => env('AUTH_CLIENT_ID', 'sms-web'), 'redirect_uri' => $this->redirectUri(), 'code' => (string) $_GET['code'], 'code_verifier' => (string) $pending['verifier']]);
        $profile = $this->request('/oauth/userinfo', null, (string) ($tokens['access_token'] ?? ''));
        $type = (string) ($pending['account_type'] ?? 'customer');
        if (($profile['account_type'] ?? '') !== $type || empty($profile['sub']) || empty($profile['email']) || empty($profile['email_verified'])) $this->fail('identity_not_allowed');
        $uuid = (string) $profile['sub'];
        $email = strtolower((string) $profile['email']);

        if ($type === 'admin') {
            $admin = table('admin_users')->where('identity_uuid', $uuid)->first();
            if (!$admin) $admin = table('admin_users')->where('email', $email)->first();
            if (!$admin || !(bool) $admin['is_active'] || (!empty($admin['identity_uuid']) && !hash_equals((string) $admin['identity_uuid'], $uuid))) $this->fail('local_access_missing');
            $user = table('users')->where('email', $email)->first();
            if (!$user) $this->fail('admin_projection_missing');
            table('users')->where('id', $user['id'])->update(['identity_uuid' => $uuid, 'role' => 'admin', 'is_active' => 1, 'email_verified_at' => $user['email_verified_at'] ?? date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $user = table('users')->where('id', $user['id'])->first();
        } else {
            $user = $this->customerProjection($uuid, $email, (string) ($profile['name'] ?? $email));
        }

        $token = Auth::generateToken($user);
        $target = $type === 'admin' ? '/guymhan/auth/callback#ieosuia_admin_token=' : '/auth/callback#ieosuia_token=';
        header('Location: '.$this->frontend().$target.rawurlencode($token), true, 302);
        exit;
    }

    private function customerProjection(string $uuid, string $email, string $name): array
    {
        $user = table('users')->where('identity_uuid', $uuid)->first();
        if (!$user) $user = table('users')->where('email', $email)->first();
        if ($user) {
            if (!empty($user['identity_uuid']) && !hash_equals((string) $user['identity_uuid'], $uuid)) $this->fail('identity_conflict');
            if (($user['role'] ?? 'user') === 'admin') $this->fail('local_access_missing');
            table('users')->where('id', $user['id'])->update(['identity_uuid' => $uuid, 'name' => $name, 'is_active' => 1, 'email_verified_at' => $user['email_verified_at'] ?? date('Y-m-d H:i:s'), 'last_login_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            return table('users')->where('id', $user['id'])->first();
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $id = table('users')->insert(['identity_uuid' => $uuid, 'name' => $name, 'email' => $email, 'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'role' => 'user', 'account_type' => 'standard', 'email_verified_at' => date('Y-m-d H:i:s'), 'is_active' => 1, 'last_login_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            table('wallets')->insert(['user_id' => $id, 'balance' => 0, 'reserved' => 0, 'currency' => 'ZAR', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $pdo->commit();
            return table('users')->where('id', $id)->first();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('SMS central projection failed: '.$error->getMessage());
            $this->fail('projection_failed');
        }
    }

    public function disabled(): void { Response::error('Use central IEOSUIA authentication.', 410, ['sso_url' => '/api/auth/ieosuia/start']); }

    private function request(string $path, ?array $fields = null, string $bearer = ''): array
    {
        $curl = curl_init($this->issuer().$path);
        $headers = ['Accept: application/json'];
        if ($bearer !== '') $headers[] = 'Authorization: Bearer '.$bearer;
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        if ($fields !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986)]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $data = is_string($body) ? json_decode($body, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($data)) $this->fail('provider_unavailable');
        return $data;
    }

    private function storeFlowCookie(array $pending): void
    {
        $payload = $this->b64(json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = $this->b64(hash_hmac('sha256', $payload, $this->flowSecret(), true));
        setcookie(self::FLOW_COOKIE, $payload.'.'.$signature, ['expires' => time() + 600, 'path' => '/api/auth/ieosuia', 'secure' => $this->https(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    private function readFlowCookie(): ?array
    {
        $parts = explode('.', (string) ($_COOKIE[self::FLOW_COOKIE] ?? ''), 2);
        if (count($parts) !== 2 || !hash_equals($this->b64(hash_hmac('sha256', $parts[0], $this->flowSecret(), true)), $parts[1])) return null;
        $json = base64_decode(strtr($parts[0].str_repeat('=', (4 - strlen($parts[0]) % 4) % 4), '-_', '+/'), true);
        $value = $json === false ? null : json_decode($json, true);
        return is_array($value) ? $value : null;
    }

    private function clearFlowCookie(): void { setcookie(self::FLOW_COOKIE, '', ['expires' => 1, 'path' => '/api/auth/ieosuia', 'secure' => $this->https(), 'httponly' => true, 'samesite' => 'Lax']); }
    private function flowSecret(): string { $secret = (string) env('AUTH_FLOW_SECRET', env('JWT_SECRET', '')); if ($secret === '') throw new RuntimeException('AUTH flow secret is not configured.'); return $secret; }
    private function session(): void { if (session_status() !== PHP_SESSION_ACTIVE) { session_name('sms_ieosuia_sso'); session_set_cookie_params(['path' => '/api/auth/ieosuia', 'secure' => $this->https(), 'httponly' => true, 'samesite' => 'Lax']); session_start(); } }
    private function issuer(): string { return rtrim((string) env('AUTH_ISSUER', 'https://auth.ieosuia.com'), '/'); }
    private function redirectUri(): string { return (string) env('AUTH_REDIRECT_URI', 'https://sms.ieosuia.com/api/auth/ieosuia/callback'); }
    private function frontend(): string { return rtrim((string) env('FRONTEND_URL', 'https://sms.ieosuia.com'), '/'); }
    private function b64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function https(): bool { return ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'; }
    private function fail(string $reason): never { error_log('IEOSUIA SSO failed: '.$reason); header('Location: '.$this->frontend().'/?sso=failed&reason='.rawurlencode($reason), true, 302); exit; }
}
