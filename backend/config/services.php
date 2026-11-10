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

    'prediction' => [
        'base_url' => env('PREDICTION_SERVICE_URL', 'http://127.0.0.1:8100'),
        'timeout' => (int) env('PREDICTION_SERVICE_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Sign-In (auth-hardening batch, 2026-09-29)
    |--------------------------------------------------------------------------
    |
    | Google Identity Services hands the frontend a signed ID token directly;
    | the backend only ever checks its signature and this Client ID as the
    | expected audience (see App\Support\Auth\JwksGoogleIdTokenVerifier).
    | There is no client secret anywhere in this flow.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

];
