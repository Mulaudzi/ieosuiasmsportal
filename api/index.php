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
require_once __DIR__ . '/core/RecaptchaValidator.php';

// Load services (with graceful handling)
$emailServicePath = __DIR__ . '/services/EmailService.php';
if (file_exists($emailServicePath)) {
    require_once $emailServicePath;
}

// CORS Headers
header('Access-Control-Allow-Origin: *');
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
    Response::success(['status' => 'ok', 'timestamp' => date('c')]);
});

// Auth routes (public)
$router->post('/auth/register', 'AuthController@register');
$router->post('/auth/login', 'AuthController@login');
$router->post('/auth/forgot-password', 'AuthController@forgotPassword');
$router->post('/auth/reset-password', 'AuthController@resetPassword');
$router->post('/auth/verify-email', 'AuthController@verifyEmail');

// Admin email check (public - for login form detection)
$router->post('/admin/check-email', 'AdminUserController@checkEmail');

// Admin user management (public but requires setup key)
$router->post('/admin-users/create', 'AdminUserController@create');
$router->post('/admin-users/update-password', 'AdminUserController@updatePassword');

// Google OAuth routes (public)
$router->get('/auth/google/status', 'GoogleAuthController@status');
$router->get('/auth/google/url', 'GoogleAuthController@getAuthUrl');
$router->post('/auth/google/callback', 'GoogleAuthController@callback');
$router->post('/auth/google/credential', 'GoogleAuthController@signInWithCredential');

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
    $router->get('/admin/sender-ids', 'AdminController@senderIds');
    $router->get('/admin/audit-logs', 'AdminController@auditLogs');
    $router->get('/admin/audit-logs/export', 'AdminController@exportAuditLogs');
    $router->get('/admin/system-health', 'AdminController@systemHealth');
    $router->get('/admin/activity-heatmap', 'AdminController@activityHeatmap');
    $router->get('/admin/heatmap/export', 'AdminController@exportHeatmap');
    
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
    
    // QA Console (all authenticated users)
    $router->post('/qa/run', 'QaController@runTests');
    $router->get('/qa/health', 'QaController@healthOverview');
    $router->post('/qa/seed', 'QaController@seedTestData');
    $router->post('/qa/cleanup', 'QaController@cleanupTestData');
    
    // Dashboard
    $router->get('/dashboard/stats', 'DashboardController@stats');
    $router->get('/dashboard/chart', 'DashboardController@chart');
    $router->get('/dashboard/recent-campaigns', 'DashboardController@recentCampaigns');
    $router->get('/dashboard/schedule-recommendations', 'DashboardController@scheduleRecommendations');
    
    // Contacts
    $router->get('/contacts', 'ContactController@index');
    $router->post('/contacts', 'ContactController@store');
    $router->get('/contacts/{id}', 'ContactController@show');
    $router->put('/contacts/{id}', 'ContactController@update');
    $router->delete('/contacts/{id}', 'ContactController@destroy');
    $router->post('/contacts/import', 'ContactController@import');
    $router->get('/contacts/export', 'ContactController@export');
    
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
    
    // SMS Campaigns
    $router->get('/sms/campaigns', 'CampaignController@smsIndex');
    $router->post('/sms/campaigns', 'CampaignController@smsStore');
    $router->get('/sms/campaigns/{id}', 'CampaignController@smsShow');
    $router->post('/sms/campaigns/{id}/send', 'CampaignController@smsSend');
    $router->post('/sms/campaigns/{id}/cancel', 'CampaignController@cancel');
    $router->post('/sms/campaigns/{id}/duplicate', 'CampaignController@duplicate');
    $router->get('/sms/campaigns/{id}/export', 'CampaignController@exportMessages');
    $router->delete('/sms/campaigns/{id}', 'CampaignController@destroy');
    
    // Email Campaigns
    $router->get('/email/campaigns', 'CampaignController@emailIndex');
    $router->post('/email/campaigns', 'CampaignController@emailStore');
    $router->get('/email/campaigns/{id}', 'CampaignController@emailShow');
    $router->post('/email/campaigns/{id}/send', 'CampaignController@emailSend');
    $router->post('/email/campaigns/{id}/duplicate', 'CampaignController@duplicate');
    $router->get('/email/campaigns/{id}/export', 'CampaignController@exportMessages');
    $router->delete('/email/campaigns/{id}', 'CampaignController@destroy');
    
    // Campaign utilities
    $router->post('/campaigns/check-credits', 'CampaignController@checkCredits');
    $router->post('/campaigns/{id}/retry', 'CampaignController@retryFailed');
    
    // Attachments
    $router->post('/attachments/upload', 'CampaignController@uploadAttachment');
    $router->delete('/attachments/{id}', 'CampaignController@deleteAttachment');
    
    // Sender IDs - REMOVED
    
    // Wallet
    $router->get('/wallet', 'WalletController@index');
    $router->get('/wallet/stats', 'WalletController@stats');
    $router->get('/wallet/transactions', 'WalletController@transactions');
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
    $router->get('/reports/compare', 'ReportController@compare');
    $router->get('/reports/ab-test-results', 'ReportController@abTestResults');
    $router->post('/reports/ab-test-winner', 'ReportController@selectAbTestWinner');
    $router->get('/reports/best-performing-variant', 'ReportController@getBestPerformingVariant');
    
    // Opt-outs
    $router->get('/opt-outs', 'OptOutController@index');
    $router->post('/opt-outs', 'OptOutController@store');
    $router->delete('/opt-outs/{id}', 'OptOutController@destroy');
    
    // Email limits check
    $router->get('/email/limits', 'CampaignController@emailLimits');
});

// Contact Form (public)
$router->post('/contact', 'ContactFormController@submit');

// Email Bounce Webhook (public with secret validation)
$router->post('/webhooks/email/bounce', 'ContactFormController@bounceWebhook');

// DLR Webhook (public with secret validation) - Legacy
$router->post('/dlr/webhook', 'DlrController@webhook');
$router->get('/dlr/status/{messageId}', 'DlrController@status');

// Telnyx Webhooks (public with signature validation)
$router->post('/webhooks/telnyx/dlr', 'TelnyxWebhookController@dlr');
$router->post('/webhooks/telnyx/dlr-failover', 'TelnyxWebhookController@dlrFailover');
$router->post('/webhooks/telnyx/inbound', 'TelnyxWebhookController@inbound');

// Payment Webhooks (public with signature validation)
$router->post('/payments/payfast/itn', 'PaymentWebhookController@payfastItn');
$router->post('/payments/paystack/webhook', 'PaymentWebhookController@paystackWebhook');
$router->post('/payments/ozow/notify', 'PaymentWebhookController@ozowNotify');

// Run router
$router->dispatch();
