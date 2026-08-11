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
     * Firebase Cloud Messaging for push (§7.13), HTTP v1.
     *
     * The old `FCM_SERVER_KEY` is gone on purpose. It addressed the legacy
     * `/fcm/send` endpoint, which Google decommissioned in June 2024 — a
     * deployment holding one had push that could not deliver anything. v1
     * authenticates with a service account instead.
     *
     * `credentials` takes either a path to the service-account JSON (relative
     * paths resolve from the project root) or the JSON document itself, for
     * hosts that only expose environment variables. Absent by default: with
     * nothing configured NotificationService records the attempt as failed
     * rather than reporting a delivery that never happened.
     */
    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS'),

        // Optional — defaults to the project_id inside the credentials.
        'project_id' => env('FCM_PROJECT_ID'),

        /*
         * Must match the channel the Flutter app creates. Android 8+ drops a
         * notification naming an unknown channel without reporting an error,
         * so the two sides agree on this string in docs/mobile-app.md §B10.
         */
        'android_channel_id' => env('FCM_ANDROID_CHANNEL_ID', 'schoolpilot_default'),
    ],

    /*
     * SMS (Termii / KudiSMS).
     *
     * SmsService reached for these through env() with a config() fallback, so a
     * deployment running `php artisan config:cache` — which is every production
     * deployment — silently fell back to the mock key and logged messages
     * instead of sending them. Declared here so config() has something to find.
     */
    'sms' => [
        'provider' => env('SMS_PROVIDER', 'termii'),

        /*
         * No fallback. `mock_sms_key` used to sit here as the default, and
         * SmsService treated that exact string as "pretend the send worked" —
         * so an unconfigured production deployment logged every fee reminder as
         * delivered. Unset now means unset, and the send reports failure.
         */
        'api_key' => env('SMS_API_KEY'),

        'sender_id' => env('SMS_SENDER_ID', 'SchoolPilot'),

        /*
         * Send password-reset and account-setup links over SMS as well as
         * email. Off by default because it is billed per segment and a reset
         * link costs two, but it is the channel parents in this market
         * actually read — a school with the budget should turn it on.
         */
        'password_links' => (bool) env('SMS_PASSWORD_LINKS', false),
    ],

    /*
     * WhatsApp (Termii's WhatsApp channel).
     *
     * This block did not exist. WhatsAppService looked up
     * `services.whatsapp.api_key` against it anyway, so the lookup always missed
     * and fell through to an env() default of `mock_key` — or, once
     * `config:cache` had run, to null, which fatalled on assignment to a typed
     * string property. Declared here so the lookup resolves, with no fallback
     * so that unset stays visibly unset.
     */
    'whatsapp' => [
        'provider' => env('WHATSAPP_PROVIDER', 'termii'),
        'api_key' => env('WHATSAPP_API_KEY'),
        'sender_id' => env('WHATSAPP_SENDER_ID', 'SchoolPilot'),
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
