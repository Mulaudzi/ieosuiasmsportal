<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SMS Gateway Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your SMS gateway credentials and settings here.
    |
    */

    'api_url' => env('LOGICSMS_API_URL', 'https://www.aborinteractive.co.za/AboriGateway/rest/sms'),
    
    'username' => env('LOGICSMS_USERNAME', ''),
    
    'password' => env('LOGICSMS_PASSWORD', ''),
    
    'default_sender' => env('LOGICSMS_DEFAULT_SENDER', 'IEOSUIA'),

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */
    
    'price_per_credit' => env('SMS_PRICE_PER_CREDIT', 0.38),
    
    'credits_per_message' => env('SMS_CREDITS_PER_MESSAGE', 1),

    /*
    |--------------------------------------------------------------------------
    | Backup Gateway (BulkSMS)
    |--------------------------------------------------------------------------
    */
    
    'backup' => [
        'api_url' => env('BULKSMS_API_URL', 'https://api.bulksms.com/v1/messages'),
        'token_id' => env('BULKSMS_TOKEN_ID', ''),
        'token_secret' => env('BULKSMS_TOKEN_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | DLR Settings
    |--------------------------------------------------------------------------
    */
    
    'dlr' => [
        'webhook_secret' => env('DLR_WEBHOOK_SECRET', ''),
        'timeout_hours' => 48, // Mark as failed if no DLR after this time
    ],
];
