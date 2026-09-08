<?php
/**
 * IEOSUIA SMS Portal - Raw PHP API
 * Single Entry Point Router
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/QueryBuilder.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Request.php';
require_once __DIR__ . '/core/JWT.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/RateLimiter.php';
require_once __DIR__ . '/core/EmailValidator.php';
require_once __DIR__ . '/core/Config.php';

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
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
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

set_exception_handler(function($e) {
    error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    Response::error('Internal server error', 500);
});

// Initialize router
$router = new Router();

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
        $rows=table('service_heartbeats')->get();$byName=[];
        foreach($rows as $row)$byName[(string)$row['service_name']]=$row;
        $services=[];$ready=true;$now=time();
        foreach($required as $name=>$maxAge){
            $last=$byName[$name]['last_seen_at']??null;$timestamp=$last?strtotime((string)$last):false;
            $age=$timestamp===false?null:max(0,$now-$timestamp);$healthy=$age!==null&&$age<=$maxAge;
            if(!$healthy)$ready=false;
            $metadata=json_decode((string)($byName[$name]['metadata_json']??''),true);if(!is_array($metadata))$metadata=[];
            $services[$name]=['healthy'=>$healthy,'last_seen_at'=>$last,'age_seconds'=>$age,'max_age_seconds'=>$maxAge,'metrics'=>$metadata];
        }
        Response::success(['status'=>$ready?'ready':'not_ready','database'=>true,'services'=>$services,'timestamp'=>date('c')],$ready?200:503);
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
$router->post('/auth/forgot-password', 'IeosuiaAuthController@disabled');
$router->post('/auth/reset-password', 'IeosuiaAuthController@disabled');
$router->post('/auth/verify-email', 'IeosuiaAuthController@disabled');

// Admin user management (public but requires setup key)
$router->post('/admin-users/create', 'IeosuiaAuthController@disabled');

// Protected routes
$router->group(['middleware' => 'auth'], function($router) {
    // Auth
    $router->post('/auth/logout', 'AuthController@logout');
    $router->get('/auth/user', 'AuthController@user');
    $router->put('/auth/user', 'AuthController@updateUser');
    $router->post('/auth/avatar', 'AuthController@uploadAvatar');
    $router->post('/auth/resend-verification', 'AuthController@resendVerification');
    $router->post('/auth/refresh', 'AuthController@refreshToken');
    
    // Notifications
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
    $router->post('/contacts/import', 'ContactController@import');
    $router->get('/contacts/export', 'ContactController@export');
    $router->get('/contacts/{id}', 'ContactController@show');
    $router->put('/contacts/{id}', 'ContactController@update');
    $router->delete('/contacts/{id}', 'ContactController@destroy');
    
    // Contact Groups
    $router->get('/contact-groups', 'ContactController@groups');
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
    $router->get('/email/campaigns', 'CampaignController@emailComingSoon');
    $router->post('/email/campaigns', 'CampaignController@emailComingSoon');
    $router->get('/email/campaigns/{id}', 'CampaignController@emailComingSoon');
    $router->post('/email/campaigns/{id}/send', 'CampaignController@emailComingSoon');
    $router->post('/email/campaigns/{id}/duplicate', 'CampaignController@emailComingSoon');
    $router->get('/email/campaigns/{id}/export', 'CampaignController@emailComingSoon');
    $router->delete('/email/campaigns/{id}', 'CampaignController@emailComingSoon');
    
    // Campaign utilities
    $router->post('/campaigns/check-credits', 'CampaignController@checkCredits');
    $router->post('/campaigns/{id}/retry', 'CampaignController@retryFailed');
    
    // Attachments
    $router->post('/attachments/upload', 'CampaignController@uploadAttachment');
    $router->delete('/attachments/{id}', 'CampaignController@deleteAttachment');
    
    // Wallet
    $router->get('/wallet', 'WalletController@index');
    $router->get('/wallet/stats', 'WalletController@stats');
    $router->get('/wallet/transactions', 'WalletController@transactions');
    $router->get('/wallet/payments', 'WalletController@payments');
    $router->get('/wallet/payments/export', 'WalletController@exportPayments');
    $router->get('/wallet/payments/status', 'WalletController@paymentStatus');
    $router->get('/wallet/receipt', 'WalletController@receipt');
    $router->post('/wallet/buy', 'WalletController@buy');
    
    // Settings
    $router->get('/settings/profile', 'SettingsController@profile');
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
    $router->get('/email/limits', 'CampaignController@emailComingSoon');
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
$router->dispatch();
