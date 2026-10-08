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
        // Switch models from Super Admin > AI service rather than here.
        'comment_model' => env('ANTHROPIC_COMMENT_MODEL', 'claude-haiku-4-5'),
        'document_model' => env('ANTHROPIC_DOCUMENT_MODEL', 'claude-sonnet-5-5'),

        /*
         * Daily AI spend cap per school, in kobo (LAUNCH.md §1).
         *
         * Null/0 means uncapped, which is the right default for a deployment
         * that has not thought about it yet — AiSpendLedger meters either way,
         * so turning the cap on later is a config change, not a code change.
         *
         * 2500000 kobo = ₦25,000/day.
         */
        'daily_cap_kobo' => env('AI_DAILY_COST_CAP_KOBO'),

        // Fraction of the cap at which the alert fires (once per school/day).
        'alert_threshold' => (float) env('AI_ALERT_THRESHOLD', 0.8),

        /*
         * AI providers bill in USD; every money column in this product is Naira
         * minor units, and a proprietor's budget conversation is in Naira. The
         * rate each row was converted at is stamped on the row, so a historical
         * figure stays explainable after this moves.
         */
        'usd_to_ngn' => (float) env('AI_USD_TO_NGN', 1500),

        /*
         * Price overrides, USD per million tokens, keyed by model id — e.g.
         * ['gpt-6-luna' => ['input' => 0.10, 'output' => 0.50, 'cached' => 0.01]].
         * List prices for every offered model live in App\Support\AiModels;
         * a row here replaces that model's row, so a price change can be a
         * config deploy rather than a release.
         */
        'pricing' => [
        ],
    ],

    /*
     * What schools pay SchoolPilot, per active student per term, in kobo
     * (SchoolBilling). Editable from Super Admin > Platform settings; each
     * issued term bill keeps the rate it was issued at.
     */
    'billing' => [
        // Result Management: the whole platform plus result services.
        'results_rate_kobo' => (int) env('BILLING_RESULTS_RATE_KOBO', 50000),
        // School Management Subscription: the platform without result services.
        'subscription_rate_kobo' => (int) env('BILLING_SUBSCRIPTION_RATE_KOBO', 30000),
        // Boarders, on either plan, in place of the plan rate.
        'boarding_rate_kobo' => (int) env('BILLING_BOARDING_RATE_KOBO', 100000),
        'trial_days' => (int) env('BILLING_TRIAL_DAYS', 30),
    ],

    /*
     * Which AI provider writes for a school (AiGateway).
     *
     * `provider` is SchoolPilot's default. A school may choose another one on
     * its AI features page, but only among providers with a key set here or in
     * the console. The daily cap, the spend ledger, stub mode and the
     * pending-approval rule for report-card remarks apply whichever is used.
     */
    'ai' => [
        'provider' => env('AI_PROVIDER', 'anthropic'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'comment_model' => env('OPENAI_COMMENT_MODEL', 'gpt-5-mini'),
        'document_model' => env('OPENAI_DOCUMENT_MODEL', 'gpt-6.1-sol'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'comment_model' => env('GEMINI_COMMENT_MODEL', 'gemini-3.1-flash-lite'),
        'document_model' => env('GEMINI_DOCUMENT_MODEL', 'gemini-3.8-flash'),
    ],

    /*
     * Optional virus scanning of uploads with ClamAV (free software on the
     * server). Unset = off; content checks still run. Host is a hostname or
     * a clamd unix socket path. When set, uploads are refused if ClamAV is
     * unreachable unless fail_open is true.
     */
    'clamav' => [
        'host' => env('CLAMAV_HOST'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'fail_open' => (bool) env('CLAMAV_FAIL_OPEN', false),
    ],

    /*
     * The public marketing site (site/, schoolpilot.org.ng).
     *
     * Turnstile is Cloudflare's privacy-friendly bot check; with no secret set
     * the lead form skips it (local, tests). Stats thresholds hide each public
     * total until it is no longer an embarrassing number (Landing PRD §8.3).
     */
    'marketing' => [
        'tenant_domain' => env('TENANT_DOMAIN', 'schoolpilot.org.ng'),
        'turnstile_secret' => env('TURNSTILE_SECRET_KEY'),
        'stats_thresholds' => [
            'schools' => (int) env('MARKETING_STATS_MIN_SCHOOLS', 10),
            'students' => (int) env('MARKETING_STATS_MIN_STUDENTS', 1000),
            'teachers' => (int) env('MARKETING_STATS_MIN_TEACHERS', 100),
        ],

        // Managed from the console (Platform settings > Website); empty here.
        'site' => [
            'whatsapp_number' => null,
            'response_minutes' => 0,
            'demo_school_url' => null,
            'contact_email' => null,
            'contact_address' => null,
            'privacy_email' => null,
            'trust_show_ai' => false,
            'trust_show_backups' => false,
            'legal_reviewed' => false,
        ],
    ],

];
