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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | No fallback values. A missing secret must make webhook verification fail
    | closed rather than silently verify against a value committed to the repo.
    | These are read via config() so `php artisan config:cache` keeps working —
    | env() outside a config file returns null once the config is cached.
    |
    */

    'paystack' => [
        'secret' => env('PAYSTACK_SECRET_KEY'),
        'public' => env('PAYSTACK_PUBLIC_KEY'),
    ],

    'flutterwave' => [
        'secret' => env('FLUTTERWAVE_SECRET_KEY'),
        'secret_hash' => env('FLUTTERWAVE_SECRET_HASH'),
    ],

    /*
     * Firebase Cloud Messaging for push (§7.13). Absent by default: with no
     * key configured NotificationService records the attempt as failed rather
     * than reporting a delivery that never happened.
     */
    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),

        /*
         * Canned AI responses, for tests and local work without a key.
         *
         * This replaces the old "if the key equals sk-ant-mock-key" check. That
         * check meant a production box with a missing or misspelled key served
         * fabricated report-card remarks — with the real student's name and
         * score interpolated — that a teacher could not tell from genuine
         * output. Stub mode is now explicit and off unless asked for.
         */
        'stub' => (bool) env('ANTHROPIC_STUB_RESPONSES', env('APP_ENV') === 'testing'),

        // Haiku for high-volume short output; Sonnet for structured documents.
        'comment_model' => env('ANTHROPIC_COMMENT_MODEL', 'claude-haiku-4-5'),
        'document_model' => env('ANTHROPIC_DOCUMENT_MODEL', 'claude-sonnet-5'),
    ],

];
