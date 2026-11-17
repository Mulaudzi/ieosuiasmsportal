<?php
declare(strict_types=1);
// Public OAuth return URL, separate from the signed incoming-message webhook.
ini_set('display_errors', '0');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/domain/MetaWhatsAppConnections.php';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET'); throw new MetaWhatsAppConnectException('Method not allowed.', 405);
    }
    if ($_GET === []) {
        // A harmless landing page lets Meta validate the exact installed URI
        // before its generated configuration ID is available. It never starts signup.
        http_response_code(200);
        $message = 'Start your WhatsApp Business connection from your portal workspace. No account has been linked by opening this page.';
    } else {
    $config = MetaWhatsAppConnectConfig::fromEnvironment();
    $service = new MetaWhatsAppConnections(db(), $config, new MetaWhatsAppOnboardingClient($config));
    $service->assertReady();
    $browser = is_string($_COOKIE['__Host-ieosuia_wa_connect'] ?? null) ? $_COOKIE['__Host-ieosuia_wa_connect'] : '';
    $returnPath = $service->callback($_GET, $browser);
    if (!in_array($returnPath, ['/guymhan/whatsapp', '/settings?tab=whatsapp'], true)) {
        throw new MetaWhatsAppConnectException('WhatsApp connection return is temporarily unavailable.', 503);
    }
    // No code/token/state is copied to the frontend URL, HTML, localStorage or analytics.
    header('Location: '.$config->origin.$returnPath, true, 303);
    exit;
    }
} catch (MetaWhatsAppConnectException $e) {
    http_response_code($e->status); $message = $e->getMessage();
} catch (Throwable) {
    http_response_code(503); $message = 'WhatsApp connection is temporarily unavailable. Start again from your portal workspace.';
}
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connect WhatsApp | IEOSUIA</title><body><main><h1>Connect WhatsApp</h1><p>'.htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p><p><a href="/settings?tab=whatsapp">Return to WhatsApp setup</a></p></main></body></html>';
