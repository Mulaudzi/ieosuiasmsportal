<?php
/**
 * IEOSUIA SMS Portal - Raw PHP API
 * Single Entry Point Router
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/QueryBuilder.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/routes/SmsIntegrationRoutes.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Request.php';
require_once __DIR__ . '/core/JWT.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/RateLimiter.php';
require_once __DIR__ . '/core/EmailValidator.php';
require_once __DIR__ . '/core/Config.php';

$suppliedCorrelationId = trim((string)($_SERVER['HTTP_X_CORRELATION_ID'] ?? ''));
$correlationId = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$/', $suppliedCorrelationId)
    ? $suppliedCorrelationId
    : bin2hex(random_bytes(16));
$_SERVER['IEOSUIA_CORRELATION_ID'] = $correlationId;
header('X-Correlation-ID: ' . $correlationId);

// Load services (with graceful handling)
$emailServicePath = __DIR__ . '/services/EmailService.php';
if (file_exists($emailServicePath)) {
    require_once $emailServicePath;
}

// CORS Headers: fail closed to the configured frontend origin.
$allowedOrigin = rtrim((string) env('FRONTEND_URL', ''), '/');
$requestOrigin = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
if ($allowedOrigin !== '' && $requestOrigin === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Correlation-ID');
header('Access-Control-Expose-Headers: X-Correlation-ID');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Error handling
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e) use ($correlationId) {
    error_log('correlation_id=' . $correlationId . ' unhandled_exception=' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    Response::error('Internal server error', 500);
});

// Initialize router
$router = new Router();

// Public, account-scoped unsubscribe capabilities; GET never changes preferences.
$router->get('/email/preferences/{id}', 'EmailCampaignController@preferences');
$router->post('/email/preferences/{id}', 'EmailCampaignController@preferences');
$router->post('/email/provider-events', 'EmailCampaignController@feedback');

// Health check
$router->get('/up', function() {
    $database = false; $services = [];
    try { db()->query('SELECT 1'); $database = true; $services = table('service_heartbeats')->get(); } catch (Throwable) {}
    Response::success(['status' => $database ? 'ok' : 'degraded', 'database' => $database, 'services' => array_map(fn($row)=>['name'=>$row['service_name'],'last_seen_at'=>$row['last_seen_at']],$services), 'timestamp' => date('c')], $database ? 200 : 503);
});

// Deployment readiness includes the asynchronous processes required to move
// queued messages and reconcile delivery receipts. Keep /up as the lightweight
// web/database health probe and use /ready as the production readiness gate.
$router->get('/ready', function() {
    $required=[
        'sms-worker'=>max(30,(int)env('SMS_WORKER_HEALTH_MAX_AGE_SECONDS',120)),
        'sms-scheduler'=>max(60,(int)env('SMS_SCHEDULER_HEALTH_MAX_AGE_SECONDS',180)),
        'sms-dlr-poller'=>max(120,(int)env('SMS_DLR_POLLER_HEALTH_MAX_AGE_SECONDS',300)),
    ];
    try{
        db()->query('SELECT 1');
        require_once __DIR__.'/services/PublicServiceReadiness.php';
        $report=PublicServiceReadiness::report(table('service_heartbeats')->get(), $required);
        Response::success($report,$report['status']==='ready'?200:503);
    }catch(Throwable $e){
        error_log('Readiness check failed: '.$e->getMessage());
        Response::success(['status'=>'not_ready','database'=>false,'services'=>[],'timestamp'=>date('c')],503);
    }
});

// Auth routes (public)
$router->post('/auth/register', 'IeosuiaAuthController@disabled');
$router->get('/auth/ieosuia/start', 'IeosuiaAuthController@start');
$router->get('/auth/ieosuia/callback', 'IeosuiaAuthController@callback');
$router->post('/auth/login', 'IeosuiaAuthController@disabled');
$router->post('/auth/two-step/verify', 'SmsLoginSecurityController@login');
$router->post('/auth/security-events', 'CentralSecurityEventController@receive');
$router->post('/auth/forgot-password', 'IeosuiaAuthController@disabled');
$router->post('/auth/reset-password', 'IeosuiaAuthController@disabled');
$router->post('/auth/verify-email', 'IeosuiaAuthController@disabled');

// Admin user management (public but requires setup key)
$router->post('/admin-users/create', 'IeosuiaAuthController@disabled');

// Protected routes
$router->get('/public/banking-details', 'CreditPurchaseController@publicBanking');

// All customer orders and finance actions remain authenticated.
$router->group(['middleware' => 'auth'], function($router) {
    $router->get('/search', 'PortalSearchController@index');
    // Auth
    $router->post('/auth/logout', 'AuthController@logout');
    $router->get('/auth/user', 'AuthController@user');
    $router->put('/auth/user', 'AuthController@updateUser');
    $router->post('/auth/avatar', 'AuthController@uploadAvatar');
    $router->post('/auth/resend-verification', 'AuthController@resendVerification');
    $router->post('/auth/refresh', 'AuthController@refreshToken');
    $router->get('/settings/login-security', 'SmsLoginSecurityController@status');
    $router->post('/settings/login-security/request', 'SmsLoginSecurityController@request');
    $router->post('/settings/login-security/confirm', 'SmsLoginSecurityController@confirm');
    SmsIntegrationRoutes::portal($router);
    
    // Notifications
    // Private form drafts are deliberately separate from campaigns and purchase orders.
    $router->get('/drafts', 'WorkDraftController@index');
    $router->get('/drafts/{id}', 'WorkDraftController@show');
    $router->put('/drafts/{id}', 'WorkDraftController@save');
    $router->delete('/drafts/{id}', 'WorkDraftController@destroy');

    $router->get('/notifications', 'NotificationController@index');
    $router->post('/notifications/{id}/read', 'NotificationController@markAsRead');
    $router->post('/notifications/read-all', 'NotificationController@markAllAsRead');
    $router->delete('/notifications/{id}', 'NotificationController@destroy');
    
    // Admin routes
    $router->get('/admin/stats', 'AdminController@stats');
    $router->get('/admin/users', 'AdminController@users');
    $router->get('/admin/users/{id}', 'AdminController@showUser');
    $router->post('/admin/users/{id}/activate', 'AdminController@activateUser');
    $router->post('/admin/users/{id}/deactivate', 'AdminController@deactivateUser');
    $router->put('/admin/users/{id}/role', 'AdminController@changeRole');
    $router->get('/admin/audit-logs', 'AdminController@auditLogs');
    $router->get('/admin/audit-logs/export', 'AdminController@exportAuditLogs');
    $router->get('/admin/system-health', 'AdminController@systemHealth');
    $router->get('/admin/activity-heatmap', 'AdminController@activityHeatmap');
    $router->get('/admin/heatmap/export', 'AdminController@exportHeatmap');
    $router->get('/admin/operations/overview', 'AdminOperationsController@overview');
    $router->get('/admin/operations/services', 'AdminOperationsController@services');
    $router->get('/admin/operations/provider', 'AdminOperationsController@provider');
    $router->get('/admin/operations/logs', 'AdminOperationsController@logs');
    $router->get('/admin/operations/customers', 'AdminOperationsController@customers');
    $router->get('/admin/operations/customers/{id}', 'AdminOperationsController@customer');
    $router->get('/admin/operations/campaigns', 'AdminOperationsController@campaigns');
    $router->get('/admin/operations/campaigns/{id}', 'AdminOperationsController@campaign');
    $router->get('/admin/operations/finance', 'AdminOperationsController@finance');
    $router->get('/admin/operations/payments', 'AdminOperationsController@payments');
    $router->get('/admin/operations/credit-approval-security', 'CreditApprovalSecurityController@status');
    $router->put('/admin/operations/credit-approval-security', 'CreditApprovalSecurityController@update');
    $router->get('/admin/operations/credit-transfers', 'CreditTransferController@index');
    $router->get('/admin/operations/credit-transfers/accounts', 'CreditTransferController@accounts');
    $router->post('/admin/operations/credit-transfers/quote', 'CreditTransferController@quote');
    $router->post('/admin/operations/credit-transfers/commit', 'CreditTransferController@commit');
    $router->get('/admin/operations/credit-transfers/{id}', 'CreditTransferController@status');
    $router->post('/admin/operations/payments/{id}/retry-receipt', 'AdminOperationsController@retryPaymentReceipt');
    
    // Admin user management (authenticated)
    $router->get('/admin-users', 'AdminUserController@list');
    $router->put('/admin-users/update', 'AdminUserController@update');
    $router->post('/admin-users/toggle-status', 'AdminUserController@toggleStatus');
    $router->post('/admin-users/reset-password', 'AdminUserController@resetPasswordAdmin');
    $router->delete('/admin-users/delete', 'AdminUserController@delete');
    
    // Admin notification settings
    $router->get('/admin/notification-settings', 'AdminNotificationSettingsController@index');
    $router->put('/admin/notification-settings/{event_type}', 'AdminNotificationSettingsController@update');
    $router->post('/admin/notification-settings/bulk', 'AdminNotificationSettingsController@bulkUpdate');
    
    // SMTP Settings management (admin only)
    $router->get('/admin/smtp-settings', 'SmtpSettingsController@index');
    $router->get('/admin/smtp-settings/{type}', 'SmtpSettingsController@show');
    $router->put('/admin/smtp-settings/{type}', 'SmtpSettingsController@update');
    $router->post('/admin/smtp-settings/{type}/test', 'SmtpSettingsController@test');
    
    // Cron management (admin only)
    $router->get('/admin/cron/status', 'CronController@status');
    $router->post('/admin/cron/run-scheduled', 'CronController@runScheduledCampaigns');
    $router->get('/admin/cron/pending-campaigns', 'CronController@pendingCampaigns');
    
    // Contact form emails management (admin only)
    $router->get('/admin/contact-emails', 'ContactFormController@index');
    $router->get('/admin/contact-emails/stats', 'ContactFormController@stats');
    $router->get('/admin/contact-emails/trends', 'ContactFormController@trends');
    $router->get('/admin/contact-emails/export', 'ContactFormController@exportCsv');
    $router->get('/admin/contact-emails/report', 'ContactFormController@exportReport');
    $router->get('/admin/contact-emails/{id}', 'ContactFormController@show');
    $router->post('/admin/contact-emails/{id}/replied', 'ContactFormController@markReplied');
    $router->post('/admin/contact-emails/{id}/notes', 'ContactFormController@addNote');
    
    // Contact alert recipients management (admin only)
    $router->get('/admin/contact-alerts', 'ContactAlertController@index');
    $router->post('/admin/contact-alerts', 'ContactAlertController@store');
    $router->put('/admin/contact-alerts/{id}', 'ContactAlertController@update');
    $router->delete('/admin/contact-alerts/{id}', 'ContactAlertController@destroy');
    
    // Realtime notifications (admin only)
    $router->get('/admin/realtime/stream', 'RealtimeController@stream');
    $router->get('/admin/realtime/poll', 'RealtimeController@poll');
    $router->post('/admin/realtime/cleanup', 'RealtimeController@cleanup');
    
    // Dashboard
    $router->get('/dashboard/stats', 'DashboardController@stats');
    $router->get('/dashboard/chart', 'DashboardController@chart');
    $router->get('/dashboard/recent-campaigns', 'DashboardController@recentCampaigns');
    $router->get('/dashboard/schedule-recommendations', 'DashboardController@scheduleRecommendations');
    
    // Contacts - static paths must come before dynamic {id} paths
    $router->get('/contacts', 'ContactController@index');
    $router->post('/contacts', 'ContactController@store');
    $router->post('/contacts/bulk-delete', 'ContactController@bulkDelete');
    $router->post('/contacts/bulk-add-to-group', 'ContactController@bulkAddToGroup');
    $router->post('/contacts/transfer-group', 'ContactController@transferGroupContacts');
    $router->post('/contacts/import', 'ContactController@import');
    $router->get('/contacts/export', 'ContactController@export');
    $router->get('/contacts/{id}', 'ContactController@show');
    $router->put('/contacts/{id}', 'ContactController@update');
    $router->delete('/contacts/{id}', 'ContactController@destroy');
    
    // Contact Groups
    $router->get('/contact-groups', 'ContactController@groups');
    $router->get('/contact-tags', 'ContactTagController@index');
    $router->post('/contact-tags', 'ContactTagController@create');
    $router->post('/contact-tags/assign', 'ContactTagController@assign');
    $router->post('/contact-tags/sms-draft', 'ContactTagController@draft');
    $router->put('/contact-tags/{id}', 'ContactTagController@update');
    $router->delete('/contact-tags/{id}', 'ContactTagController@delete');
    $router->post('/contact-groups', 'ContactController@createGroup');
    $router->put('/contact-groups/{id}', 'ContactController@updateGroup');
    $router->delete('/contact-groups/{id}', 'ContactController@deleteGroup');
    
    // Templates
    $router->get('/templates', 'TemplateController@index');
    $router->post('/templates', 'TemplateController@store');
    $router->get('/templates/{id}', 'TemplateController@show');
    $router->put('/templates/{id}', 'TemplateController@update');
    $router->delete('/templates/{id}', 'TemplateController@destroy');
    
    // Canonical SMS MVP API
    $router->post('/sms/preview', 'SmsController@preview');
    $router->get('/sms/campaigns-v2', 'SmsController@index');
    $router->post('/sms/campaigns-v2', 'SmsController@store');
    $router->get('/sms/campaigns-v2/{id}', 'SmsController@show');
    $router->post('/sms/campaigns-v2/{id}/queue', 'SmsController@queue');
    $router->post('/sms/campaigns-v2/{id}/retry', 'SmsController@retry');
    $router->post('/sms/campaigns-v2/{id}/cancel', 'SmsController@cancel');
    $router->get('/sms/campaigns-v2/{id}/messages', 'SmsController@messages');
    $router->get('/sms/campaigns-v2/{id}/export', 'SmsController@export');

    // Email Campaigns
    $router->get('/email/campaigns', 'EmailCampaignController@index');
    $router->post('/email/campaigns', 'EmailCampaignController@create');
    $router->post('/email/quote', 'EmailCampaignController@quote');
    $router->get('/email/sender', 'EmailCampaignController@sender');
    $router->post('/email/sender', 'EmailCampaignController@saveSender');
    $router->get('/email/campaigns/{id}', 'EmailCampaignController@show');
    $router->post('/email/campaigns/{id}/send', 'EmailCampaignController@send');
    $router->post('/email/campaigns/{id}/cancel', 'EmailCampaignController@cancel');
    $router->get('/admin/email-campaigns', 'EmailCampaignController@adminIndex');
    $router->get('/admin/email-campaigns/{id}', 'EmailCampaignController@adminShow');
    $router->post('/admin/email-campaigns/{id}/review', 'EmailCampaignController@review');
    $router->post('/admin/email-senders/{id}/review', 'EmailCampaignController@reviewSender');
    $router->post('/email/campaigns/{id}/duplicate', 'CampaignController@emailComingSoon');
    $router->get('/email/campaigns/{id}/export', 'CampaignController@emailComingSoon');
    $router->delete('/email/campaigns/{id}', 'CampaignController@emailComingSoon');
    
    // Campaign utilities
    $router->post('/campaigns/check-credits', 'CampaignController@checkCredits');
    // Compatibility route retained as an explicit fail-closed response. Legacy
    // SMS retries bypassed wallet reservations and the canonical no-resend marker.
    $router->post('/campaigns/{id}/retry', 'CampaignController@legacyRetryDisabled');
    
    // Attachments
    $router->post('/attachments/upload', 'CampaignController@uploadAttachment');
    $router->delete('/attachments/{id}', 'CampaignController@deleteAttachment');
    
    // Wallet
    // Read-only portal presentation; existing sending and billing endpoints are unchanged.
    $router->get('/portal/overview', 'PortalPresentationController@overview');
    $router->get('/reports/confirmation-time', 'PortalPresentationController@confirmation');
    $router->get('/wallet/activity', 'PortalPresentationController@activity');
    $router->get('/sms/campaigns-v2/{id}/recipient-view', 'PortalPresentationController@recipients');
    $router->get('/sms/campaigns-v2/{id}/recipient-export', 'PortalPresentationController@export');
    $router->get('/wallet', 'WalletController@index');
    $router->get('/wallet/stats', 'WalletController@stats');
    $router->get('/wallet/credit-transfers', 'CreditTransferController@customerHistory');
    $router->post('/wallet/credit-transfers/quote', 'CreditTransferController@customerQuote');
    $router->post('/wallet/credit-transfers/challenge', 'CreditTransferController@customerChallenge');
    $router->post('/wallet/credit-transfers/commit', 'CreditTransferController@customerCommit');
    $router->get('/wallet/credit-transfers/{id}', 'CreditTransferController@customerStatus');
    $router->get('/wallet/transactions', 'WalletController@transactions');
    $router->get('/wallet/payments', 'WalletController@payments');
    $router->get('/wallet/payments/export', 'WalletController@exportPayments');
    $router->get('/wallet/payments/status', 'WalletController@paymentStatus');
    $router->get('/wallet/receipt', 'WalletController@receipt');
    $router->post('/wallet/buy', 'WalletController@buy');
    $router->get('/wallet/purchase-options', 'CreditPurchaseController@options');
    $router->post('/wallet/purchase-quote', 'CreditPurchaseController@quote');
    $router->post('/admin/operations/cash-balances/enable', 'CreditPurchaseController@enableCashBalances');
    $router->get('/admin/operations/cash-adjustments', 'CashAdjustmentController@index');
    $router->get('/admin/operations/cash-adjustments/accounts', 'CashAdjustmentController@accounts');
    $router->post('/admin/operations/cash-adjustments/quote', 'CashAdjustmentController@quote');
    $router->post('/admin/operations/cash-adjustments/commit', 'CashAdjustmentController@commit');
    $router->get('/admin/operations/cash-adjustments/{id}', 'CashAdjustmentController@status');
    $router->get('/wallet/purchase-orders', 'CreditPurchaseController@orders');
    $router->post('/wallet/purchase-orders/{id}/proof', 'CreditPurchaseController@uploadProof');
    $router->get('/wallet/payment-proofs/{id}', 'CreditPurchaseController@proof');
    $router->post('/admin/operations/purchase-orders/{id}/review', 'CreditPurchaseController@review');
    
    // Settings
    $router->get('/settings/profile', 'SettingsController@profile');
    $router->get('/settings/sms', 'SettingsController@smsPreferences');
    $router->put('/settings/sms', 'SettingsController@updateSmsPreferences');
    $router->put('/settings/profile', 'SettingsController@updateProfile');
    $router->post('/settings/branding', 'SettingsController@uploadBranding');
    $router->put('/settings/password', 'SettingsController@updatePassword');
    
    // Reports
    $router->get('/reports/stats', 'ReportController@stats');
    $router->get('/reports/chart', 'ReportController@chart');
    $router->get('/reports/delivery', 'ReportController@delivery');
    $router->get('/reports/campaigns', 'ReportController@campaigns');
    $router->get('/reports/messages', 'ReportController@messages');
    $router->get('/reports/export', 'ReportController@export');
    $router->post('/reports/email', 'ReportController@emailReport');
    $router->get('/reports/compare', 'ReportController@compare');
    $router->get('/reports/ab-test-results', 'ReportController@abTestResults');
    $router->post('/reports/ab-test-winner', 'ReportController@selectAbTestWinner');
    $router->get('/reports/best-performing-variant', 'ReportController@getBestPerformingVariant');
    
    // Opt-outs
    $router->get('/opt-outs', 'OptOutController@index');
    $router->post('/opt-outs', 'OptOutController@store');
    $router->delete('/opt-outs/{id}', 'OptOutController@destroy');
    
    // Email limits check
    $router->get('/email/limits', 'EmailCampaignController@limits');
    $router->get('/dlr/status/{messageId}', 'DlrController@status');
});

// Contact Form (public)
$router->post('/contact', 'ContactFormController@submit');

// Email Bounce Webhook (public with secret validation)
$router->post('/webhooks/email/bounce', 'ContactFormController@bounceWebhook');

// Both historical and current provider URLs feed the canonical SMS ledger.
$router->post('/dlr/webhook', 'LogicSmsWebhookController@delivery');
$router->get('/dlr/webhook', 'LogicSmsWebhookController@delivery');
$router->post('/webhooks/logicsms/dlr', 'LogicSmsWebhookController@delivery');
$router->get('/webhooks/logicsms/dlr', 'LogicSmsWebhookController@delivery');
$router->post('/webhooks/logicsms/dlr/{token}', 'LogicSmsWebhookController@delivery');
$router->get('/webhooks/logicsms/dlr/{token}', 'LogicSmsWebhookController@delivery');


// Payment Webhooks (public with signature validation)
$router->post('/payments/payos/callback', 'PaymentWebhookController@payosCallback');

// Run router
SmsIntegrationRoutes::external($router);
$router->dispatch();
