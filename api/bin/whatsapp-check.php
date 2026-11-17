<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../providers/MetaWhatsAppProvider.php';
$config = MetaWhatsAppProvider::configuration();
$status = MetaWhatsAppProvider::readiness($config);
echo json_encode(['provider' => 'Meta WhatsApp Cloud API', 'configuration' => $status,
    'webhook_enabled' => ($config['WHATSAPP_WEBHOOK_ENABLED'] ?? '') === 'true',
    'live_connection_verified' => false, 'campaign_integration_enabled' => false], JSON_PRETTY_PRINT).PHP_EOL;
exit($status['configuration_complete'] ? 0 : 1);
