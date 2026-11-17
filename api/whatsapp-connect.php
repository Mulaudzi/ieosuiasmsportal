<?php
declare(strict_types=1);
// Standalone authenticated onboarding API; no changes to the SMS router/dispatcher.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/core/QueryBuilder.php';
require_once __DIR__.'/core/Request.php';
require_once __DIR__.'/core/Response.php';
require_once __DIR__.'/core/JWT.php';
require_once __DIR__.'/core/Auth.php';
require_once __DIR__.'/domain/MetaWhatsAppConnections.php';

try {
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
    $action = $_GET['action'] ?? 'status';
    if (!is_string($action) || !in_array($action, ['status','start','confirm','cancel','disconnect'], true)) Response::error('Unknown WhatsApp connection operation.', 404);
    if ($method !== ($action === 'status' ? 'GET' : 'POST')) {
        header('Allow: '.($action === 'status' ? 'GET' : 'POST')); Response::error('Method not allowed.', 405);
    }
    Auth::check();
    try {
        $config = MetaWhatsAppConnectConfig::fromEnvironment();
        $config->assertPilotAccess(Auth::isAdmin(), (string)(Auth::user()['email'] ?? ''));
        $service = new MetaWhatsAppConnections(db(), $config, new MetaWhatsAppOnboardingClient($config));
        $service->assertReady();
    } catch (Throwable $e) {
        if ($action === 'status') Response::success(['available'=>false, 'sending_enabled'=>false, 'connections'=>[], 'pending'=>null]);
        throw $e;
    }
    $owner = (int)Auth::id();
    $sessionHash = hash('sha256', (string)Request::bearerToken());
    $browser = is_string($_COOKIE['__Host-ieosuia_wa_connect'] ?? null) ? $_COOKIE['__Host-ieosuia_wa_connect'] : '';
    if ($action === 'status') Response::success($service->status($owner,$sessionHash,$browser));
    // Bearer auth plus exact-origin JSON requests. Cookies alone never authorize account changes.
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $config->origin
        || !str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) Response::error('Start this action from portal Settings.', 403);
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if (!is_string($raw) || strlen($raw) > 8192) Response::error('Connection request is too large.', 413);
    try { $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException) { Response::error('Invalid connection request.', 422); }
    if (!is_array($body) || array_is_list($body) && $body !== []) Response::error('Invalid connection request.', 422);
    if ($action === 'start') {
        if (!preg_match('/^[a-f0-9]{64}$/D', $browser)) $browser = bin2hex(random_bytes(32));
        $claims = JWT::decode((string)Request::bearerToken());
        $result = $service->start($owner,(int)(Auth::user()['auth_version'] ?? 1),$sessionHash,$browser,(int)($claims['exp'] ?? 0));
        setcookie('__Host-ieosuia_wa_connect', $browser, ['expires'=>time()+600, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
        Response::success($result);
    }
    if ($action === 'confirm') {
        if (!is_string($body['attempt'] ?? null) || !is_string($body['phone_number_id'] ?? null)) Response::error('Choose an authorized phone number.', 422);
        $service->confirm($owner,$sessionHash,$browser,$body['attempt'],$body['phone_number_id']);
    } elseif ($action === 'cancel') {
        $service->cancel($owner,$sessionHash,$browser);
    } elseif ($action === 'disconnect') {
        if (!is_string($body['phone_number_id'] ?? null)) Response::error('Choose a WhatsApp connection.', 422);
        $service->disconnect($owner,$body['phone_number_id']);
    }
    Response::success(['updated'=>true, 'sending_enabled'=>false]);
} catch (MetaWhatsAppConnectException $e) {
    Response::error($e->getMessage(), $e->status);
} catch (Throwable) {
    // No provider error bodies, credentials, SQL or exception messages are logged.
    Response::error('WhatsApp connection is temporarily unavailable. Please retry from Settings.', 503);
}
