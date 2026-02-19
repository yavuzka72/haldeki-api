<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
     'onesignal' => [
        'app_id'       => env('ONESIGNAL_APP_ID'),
        'rest_api_key' => env('ONESIGNAL_REST_API_KEY'),
        'api_url'      => env('ONESIGNAL_API_URL', 'https://onesignal.com/api/v1/notifications'),
    ],
    'esnafexpress' => [
  'base_url' => env('ESNAFEXPRESS_URL', 'http://10.187.186.24:8082/api/partner/'),
  'partner_key' => env('ESNAFEXPRESS_PARTNER_KEY'),
  'partner_secret' => env('ESNAFEXPRESS_PARTNER_SECRET'),
  'token' => env('ESNAFEXPRESS_TOKEN'),
],


];
