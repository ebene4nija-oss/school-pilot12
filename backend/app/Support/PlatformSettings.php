<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The platform settings an operator can manage from the console, and the one
 * place they reach the rest of the application.
 *
 * Each key is the config path the service already reads. At boot, any value
 * saved in the console is written over that config path, so ClaudeService,
 * SmsService, WhatsAppService, FcmService and the AI spend ledger pick it up
 * with no change of their own. Anything not set in the console falls through
 * to backend.env exactly as before — so a deployment that never opens the
 * settings screen behaves identically, and the screen can be adopted one key
 * at a time.
 *
 * Deliberately absent:
 *
 *  - `services.anthropic.stub`. Stub mode serves canned report-card remarks
 *    with a real child's name in them, indistinguishable from genuine output.
 *    Being able to flip that from a browser is not a feature.
 *  - Payment gateway keys. Those are per school, already encrypted in
 *    `school_payment_gateways`, and belong to the school that owns the
 *    account the money lands in.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings.v1';

    /**
     * Container binding holding what each key was before the console overlaid
     * it — what backend.env says — and which keys are currently overlaid.
     *
     * Held on the container rather than in a static property: a static outlives
     * the application instance, so it carried one request's baseline into the
     * next in any long-lived process, and one test's into the next in the suite.
     * The container is per request, which is exactly the lifetime of config.
     */
    private const STATE = 'platform_settings.state';

    /** @return array{baseline: array<string, mixed>, overlaid: array<string, bool>} */
    private static function state(): array
    {
        return app()->bound(self::STATE)
            ? app(self::STATE)
            : ['baseline' => [], 'overlaid' => []];
    }

    private static function saveState(array $state): void
    {
        app()->instance(self::STATE, $state);
    }

    /**
     * Every setting the console can manage.
     *
     * `type`:
     *  - secret: write-only from the console; only whether it is set, and its
     *    last four characters, are ever read back
     *  - string, select, bool, number
     *  - naira: entered and shown in Naira, stored in kobo, because every money
     *    column in this product is minor units
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            // ------------------------------------------------------------ AI
            'services.anthropic.api_key' => [
                'group' => 'ai',
                'label' => 'Anthropic API key',
                'type' => 'secret',
                'help' => 'Used for report-card comments, lesson plans, worksheets and the student tutor. Without it every AI feature reports itself unavailable.',
            ],
            'services.anthropic.comment_model' => [
                'group' => 'ai',
                'label' => 'Model for short output',
                'type' => 'select',
                'options' => ['claude-haiku-4-5', 'claude-sonnet-5'],
                'help' => 'Report-card remarks and other high-volume, short responses.',
            ],
            'services.anthropic.document_model' => [
                'group' => 'ai',
                'label' => 'Model for documents',
                'type' => 'select',
                'options' => ['claude-sonnet-5', 'claude-opus-5-5', 'claude-haiku-4-5'],
                'help' => 'Lesson plans, worksheets and exam drafts, where structure matters more than volume.',
            ],
            'services.anthropic.daily_cap_kobo' => [
                'group' => 'ai',
                'label' => 'Daily AI spend cap per school',
                'type' => 'naira',
                'help' => 'Once a school reaches this in a day, AI requests are refused until midnight. Empty or 0 means no cap.',
            ],
            'services.anthropic.alert_threshold' => [
                'group' => 'ai',
                'label' => 'Alert at this share of the cap',
                'type' => 'number',
                'min' => 0.1,
                'max' => 1,
                'help' => 'A fraction between 0.1 and 1. At 0.8 the school is warned when it has used 80% of the day\'s cap.',
            ],
            'services.anthropic.usd_to_ngn' => [
                'group' => 'ai',
                'label' => 'US dollar to Naira rate',
                'type' => 'number',
                'min' => 1,
                'help' => 'Anthropic bills in dollars; spend is recorded in Naira at this rate. Each record keeps the rate it was converted at, so changing it does not rewrite history.',
            ],

            // ----------------------------------------------------------- SMS
            'services.sms.api_key' => [
                'group' => 'sms',
                'label' => 'SMS provider API key',
                'type' => 'secret',
                'help' => 'Termii. Without it, fee reminders and alerts sent by SMS report as failed rather than pretending to deliver.',
            ],
            'services.sms.sender_id' => [
                'group' => 'sms',
                'label' => 'SMS sender ID',
                'type' => 'string',
                'max' => 11,
                'help' => 'The name parents see the message come from. Must be registered with the provider; 11 characters at most.',
            ],
            'services.sms.password_links' => [
                'group' => 'sms',
                'label' => 'Send password links by SMS too',
                'type' => 'bool',
                'help' => 'Password-reset and account-setup links go by SMS as well as email. Billed per message — a link is usually two.',
            ],

            // ------------------------------------------------------ WhatsApp
            'services.whatsapp.api_key' => [
                'group' => 'whatsapp',
                'label' => 'WhatsApp provider API key',
                'type' => 'secret',
                'help' => 'Termii\'s WhatsApp channel.',
            ],
            'services.whatsapp.sender_id' => [
                'group' => 'whatsapp',
                'label' => 'WhatsApp sender ID',
                'type' => 'string',
                'max' => 32,
                'help' => 'The registered WhatsApp sender.',
            ],

            // --------------------------------------------------------- Email
            'mail.default' => [
                'group' => 'email',
                'label' => 'Mail delivery',
                'type' => 'select',
                'options' => ['smtp', 'log'],
                'help' => 'smtp sends real email through the server below. log writes messages to a file and delivers nothing; it is only for testing.',
            ],
            'mail.mailers.smtp.host' => [
                'group' => 'email',
                'label' => 'SMTP server',
                'type' => 'string',
                'help' => 'For example smtp.zoho.com, smtp.gmail.com, or the mail server cPanel gives you.',
            ],
            'mail.mailers.smtp.port' => [
                'group' => 'email',
                'label' => 'SMTP port',
                'type' => 'integer',
                'min' => 1,
                'max' => 65535,
                'help' => '587 for STARTTLS, 465 for SSL.',
            ],
            'mail.mailers.smtp.scheme' => [
                'group' => 'email',
                'label' => 'Connection security',
                'type' => 'select',
                'options' => ['smtp', 'smtps'],
                'help' => 'smtp (STARTTLS) for port 587; smtps (SSL) for port 465.',
            ],
            'mail.mailers.smtp.username' => [
                'group' => 'email',
                'label' => 'SMTP username',
                'type' => 'string',
                'help' => 'Usually the full sending address.',
            ],
            'mail.mailers.smtp.password' => [
                'group' => 'email',
                'label' => 'SMTP password',
                'type' => 'secret',
                'help' => 'The mailbox password, or an app password where the provider requires one.',
            ],
            'mail.from.address' => [
                'group' => 'email',
                'label' => 'Send from address',
                'type' => 'email',
                'help' => 'Parents see this as the sender. It should be an address the SMTP account is allowed to send as.',
            ],
            'mail.from.name' => [
                'group' => 'email',
                'label' => 'Send from name',
                'type' => 'string',
                'help' => 'For example: SchoolPilot.',
            ],
            'schoolpilot.email_daily_limit' => [
                'group' => 'email',
                'label' => 'Emails per school per day',
                'type' => 'integer',
                'min' => 0,
                'help' => 'Default limit for every school; the Schools table can set a different number for one school or switch its email off. Password-reset and account-setup emails are never held back. 0 means no limit.',
            ],

            // ---------------------------------------------------------- Push
            'services.fcm.credentials' => [
                'group' => 'push',
                'label' => 'Firebase service-account JSON',
                'type' => 'secret',
                'multiline' => true,
                'help' => 'Paste the whole JSON file from the Firebase console. Without it the mobile app receives no notifications.',
            ],
            'services.fcm.project_id' => [
                'group' => 'push',
                'label' => 'Firebase project ID',
                'type' => 'string',
                'help' => 'Optional — read from the service-account JSON when left empty.',
            ],
        ];
    }

    /** @return array<string, string> */
    public static function groups(): array
    {
        return [
            'ai' => 'AI service',
            'sms' => 'SMS',
            'whatsapp' => 'WhatsApp',
            'email' => 'Email',
            'push' => 'Push notifications',
        ];
    }

    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, static::definitions());
    }

    /**
     * Write every console-managed value over its config path.
     *
     * Runs at boot. It must never be the reason a request fails: before the
     * migration has run, with the database or cache unreachable, or with a
     * value encrypted under a previous APP_KEY, the affected keys are simply
     * left at their backend.env values.
     */
    public static function apply(): void
    {
        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, function () {
                // The raw column, so the cache holds ciphertext rather than
                // decrypted keys sitting in Redis.
                return DB::table('platform_settings')->pluck('value', 'key')->all();
            });
        } catch (\Throwable) {
            return;
        }

        $state = static::state();

        foreach (static::definitions() as $key => $definition) {
            // Record the server value only while config still holds it — once a
            // key is overlaid, config() is the console's value, not env's.
            if (empty($state['overlaid'][$key])) {
                $state['baseline'][$key] = config($key);
            }

            if (! isset($stored[$key])) {
                continue;
            }

            try {
                $value = Crypt::decryptString($stored[$key]);
            } catch (DecryptException) {
                // Encrypted under an APP_KEY this deployment no longer has.
                // Leave the env value in force; the console reports the row
                // as unreadable so it can be re-entered.
                continue;
            }

            config([$key => static::cast($definition['type'], $value)]);
            $state['overlaid'][$key] = true;
        }

        static::saveState($state);
    }

    /** Drop the cached map so the next request re-reads the table. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** What backend.env provides for this key, independent of the console. */
    public static function serverValue(string $key): mixed
    {
        $state = static::state();

        return array_key_exists($key, $state['baseline']) ? $state['baseline'][$key] : config($key);
    }

    /**
     * Store a value, or clear it with null, then make it live for the rest of
     * this request and every one after.
     */
    public static function set(string $key, ?string $value, ?int $userId): void
    {
        if ($value === null) {
            PlatformSetting::where('key', $key)->delete();
        } else {
            PlatformSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'updated_by' => $userId],
            );
        }

        static::forget();

        // Put this key back to its server value first, so clearing a setting
        // takes effect now rather than on the next request.
        $state = static::state();

        if (! empty($state['overlaid'][$key])) {
            config([$key => $state['baseline'][$key] ?? null]);
            unset($state['overlaid'][$key]);
            static::saveState($state);
        }

        static::apply();
    }

    private static function cast(string $type, string $value): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'naira' => (int) $value,
            'number' => (float) $value,
            'integer' => (int) $value,
            default => $value,
        };
    }
}
