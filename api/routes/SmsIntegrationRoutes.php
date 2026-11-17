<?php
declare(strict_types=1);
final class SmsIntegrationRoutes
{
    /** Must be called within the portal-authenticated route group. Controller rechecks Auth. */
    public static function portal(Router $router): void {
        $router->get('/settings/api-integrations', 'SmsIntegrationController@connections');
        $router->post('/settings/api-integrations', 'SmsIntegrationController@create');
        $router->post('/settings/api-integrations/{id}/keys', 'SmsIntegrationController@rotate');
        $router->delete('/settings/api-integrations/{id}', 'SmsIntegrationController@revoke');
        $router->delete('/settings/api-integrations/{id}/keys/{key}', 'SmsIntegrationController@revokeKey');
    }
    /** Each action authenticates a server-side API credential, never a portal/admin JWT. */
    public static function external(Router $router): void {
        $router->post('/v1/sms/quotes', 'SmsIntegrationController@quote');
        $router->post('/v1/sms/messages', 'SmsIntegrationController@send');
        $router->get('/v1/sms/messages/{id}', 'SmsIntegrationController@status');
        $router->post('/v1/sms/messages/{id}/cancel', 'SmsIntegrationController@cancel');
        $router->get('/v1/balance', 'SmsIntegrationController@balance');
        $router->get('/v1/usage', 'SmsIntegrationController@usage');
    }
}
