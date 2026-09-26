<?php

namespace App\Services;

use App\Models\SchoolSmsGateway;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SMS, sent through the school's own account.
 *
 * Two providers:
 *
 *  - **phone_gateway** — an Android SMS-gateway app on the school's own phone
 *    and SIM, reached through the gateway's server. Parents see the school's
 *    number and the school pays its own airtime. This is how many schools here
 *    already text parents, which is why the account belongs to the school
 *    rather than to the platform.
 *  - **termii** — a bulk SMS account.
 *
 * A school without its own account falls back to the platform's Termii key if
 * one is set, and otherwise fails honestly.
 *
 * Two things this deliberately does not do, both of which it used to:
 *
 *  1. **It does not read env() at runtime.** `config:cache` stops .env from
 *     being read at all.
 *  2. **It does not invent a success.** The old version reported a mock message
 *     id whenever the key was missing, so an unconfigured school saw a history
 *     full of delivered fee reminders that no parent received.
 */
class SmsService
{
    private const TERMII_ENDPOINT = 'https://api.ng.termii.com/api/sms/send';

    /** Whether this school — or failing that, the platform — can send. */
    public function isConfigured(?int $schoolId = null): bool
    {
        return $this->resolve($schoolId) !== null;
    }

    /**
     * Describe who would send, for the readiness panel. Never includes a key.
     *
     * @return array{configured: bool, source: string, provider: ?string}
     */
    public function describe(?int $schoolId = null): array
    {
        $gateway = $this->resolve($schoolId);

        if ($gateway === null) {
            return ['configured' => false, 'source' => 'none', 'provider' => null];
        }

        return [
            'configured' => true,
            'source' => $gateway['source'],
            'provider' => $gateway['provider'],
        ];
    }

    /**
     * @return array{status:string, message_id?:string, message?:string}
     */
    public function sendSms(string $to, string $message, ?int $schoolId = null): array
    {
        $gateway = $this->resolve($schoolId);

        if ($gateway === null) {
            Log::warning('SMS not sent: neither this school nor the platform has an SMS account.', [
                'school_id' => $schoolId,
            ]);

            return [
                'status' => 'error',
                'message' => 'SMS is not set up for this school. Add an SMS account under Notifications → SMS settings.',
            ];
        }

        $to = static::normaliseNumber($to);

        return $gateway['provider'] === SchoolSmsGateway::PHONE_GATEWAY
            ? $this->sendViaPhoneGateway($gateway, $to, $message)
            : $this->sendViaTermii($gateway, $to, $message);
    }

    /**
     * Send one message through a specific account — the "send a test" button,
     * which must exercise the settings being saved rather than whatever is
     * currently live.
     *
     * @return array{status:string, message_id?:string, message?:string}
     */
    public function sendWith(SchoolSmsGateway $account, string $to, string $message): array
    {
        $gateway = $this->fromAccount($account);
        $to = static::normaliseNumber($to);

        return $gateway['provider'] === SchoolSmsGateway::PHONE_GATEWAY
            ? $this->sendViaPhoneGateway($gateway, $to, $message)
            : $this->sendViaTermii($gateway, $to, $message);
    }

    /**
     * Credits left on a phone-gateway account, where the gateway reports them.
     *
     * @return array{status:string, credits?:string, message?:string}
     */
    public function balance(SchoolSmsGateway $account): array
    {
        if ($account->provider !== SchoolSmsGateway::PHONE_GATEWAY) {
            return ['status' => 'error', 'message' => 'Balance is only available for phone gateways.'];
        }

        try {
            $data = $this->phoneGatewayRequest($this->fromAccount($account), []);

            return [
                'status' => 'success',
                // The gateway returns null for an unlimited plan.
                'credits' => isset($data['credits']) ? (string) $data['credits'] : 'Unlimited',
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Nigerian numbers as they are written on forms — 0803…, 803…, 234803… —
     * to the international form both providers accept.
     */
    public static function normaliseNumber(string $number): string
    {
        $digits = preg_replace('/[^\d+]/', '', $number) ?? '';

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        if (str_starts_with($digits, '234')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '+234'.substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '+234'.$digits;
        }

        return $digits;
    }

    /**
     * The account that would send for this school: its own if it has an active
     * one, otherwise the platform's Termii key.
     *
     * @return array{provider:string, source:string, api_key:string, server_url:?string, device:?string, sender_id:string}|null
     */
    private function resolve(?int $schoolId): ?array
    {
        $schoolId ??= app(TenantContext::class)->schoolId();

        if ($schoolId !== null) {
            $account = app(TenantContext::class)->withoutScoping(
                fn () => SchoolSmsGateway::query()
                    ->where('school_id', $schoolId)
                    ->where('is_active', true)
                    ->first()
            );

            if ($account) {
                return $this->fromAccount($account);
            }
        }

        $platformKey = config('services.sms.api_key');

        if (is_string($platformKey) && $platformKey !== '') {
            return [
                'provider' => SchoolSmsGateway::TERMII,
                'source' => 'platform',
                'api_key' => $platformKey,
                'server_url' => null,
                'device' => null,
                'sender_id' => (string) config('services.sms.sender_id', 'SchoolPilot'),
            ];
        }

        return null;
    }

    private function fromAccount(SchoolSmsGateway $account): array
    {
        return [
            'provider' => $account->provider,
            'source' => 'school',
            'api_key' => (string) $account->api_key,
            'server_url' => $account->server_url ? rtrim($account->server_url, '/') : null,
            'device' => $account->device ?: null,
            'sender_id' => $account->sender_id ?: (string) config('services.sms.sender_id', 'SchoolPilot'),
        ];
    }

    private function sendViaPhoneGateway(array $gateway, string $to, string $message): array
    {
        try {
            $data = $this->phoneGatewayRequest($gateway, [
                'number' => $to,
                'message' => $message,
                'devices' => $gateway['device'] ?? 0,
                'type' => 'sms',
                'prioritize' => 0,
            ]);

            $sent = $data['messages'][0] ?? null;

            return array_filter([
                'status' => 'success',
                'message_id' => isset($sent['ID']) ? (string) $sent['ID'] : null,
                // The gateway queues on the handset; "Pending" is normal here.
                'gateway_status' => $sent['status'] ?? null,
            ], fn ($v) => $v !== null);
        } catch (\Throwable $e) {
            Log::error('Phone SMS gateway send failed: '.$e->getMessage(), ['recipient' => $to]);

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * One call to the phone gateway's API.
     *
     * Its contract: form-encoded POST, answers 200 with
     * `{"success": bool, "data": {...}, "error": {"message": ...}}`.
     *
     * @return array<string, mixed> the `data` object
     */
    private function phoneGatewayRequest(array $gateway, array $fields): array
    {
        if (empty($gateway['server_url'])) {
            throw new \RuntimeException('The SMS gateway address is missing.');
        }

        $response = Http::asForm()
            // Carrier links are slow and PHP-FPM workers are finite.
            ->timeout(20)
            ->post($gateway['server_url'].'/services/send.php', $fields + ['key' => $gateway['api_key']]);

        if (! $response->successful()) {
            throw new \RuntimeException('SMS gateway returned HTTP '.$response->status().'.');
        }

        $json = $response->json();

        if (! is_array($json)) {
            $body = trim((string) $response->body());

            throw new \RuntimeException($body !== '' ? mb_substr($body, 0, 200) : 'The SMS gateway sent an empty reply.');
        }

        if (empty($json['success'])) {
            throw new \RuntimeException($json['error']['message'] ?? 'The SMS gateway refused the message.');
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    private function sendViaTermii(array $gateway, string $to, string $message): array
    {
        try {
            $response = Http::timeout(15)
                ->retry(2, 500, throw: false)
                ->post(self::TERMII_ENDPOINT, [
                    // Termii wants the number without the plus.
                    'to' => ltrim($to, '+'),
                    'from' => $gateway['sender_id'],
                    'sms' => $message,
                    'type' => 'plain',
                    'channel' => 'generic',
                    'api_key' => $gateway['api_key'],
                ]);

            if ($response->failed()) {
                Log::error('SMS gateway rejected the message', [
                    'status' => $response->status(),
                    'recipient' => $to,
                ]);

                return ['status' => 'error', 'message' => 'SMS gateway returned '.$response->status().'.'];
            }

            $payload = $response->json();

            return is_array($payload) ? $payload + ['status' => 'success'] : ['status' => 'success'];
        } catch (\Throwable $e) {
            Log::error('SMS gateway dispatch failure: '.$e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
