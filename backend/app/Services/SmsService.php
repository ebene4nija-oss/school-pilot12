<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SMS over Termii / KudiSMS.
 *
 * Two things this deliberately does not do, both of which it used to:
 *
 *  1. **It does not read env() at runtime.** `config:cache` is a standard deploy
 *     step and it stops .env from being read at all, so a configured school
 *     silently dropped back to the mock key.
 *  2. **It does not invent a success.** The old version returned
 *     `['status' => 'success', 'message_id' => 'mock_msg_…']` whenever the key
 *     was missing, and NotificationService recorded that as *sent*. A school
 *     whose SMS key was never filled in got a fee-reminder history full of
 *     delivered messages that no parent ever received — worse than an outage,
 *     because nothing looked wrong.
 *
 * Unconfigured now means an honest failure, the same way FcmService and the
 * Anthropic client behave.
 */
class SmsService
{
    private const ENDPOINT = 'https://api.ng.termii.com/api/sms/send';

    /**
     * Whether this deployment can send at all.
     *
     * Read at call time rather than cached in the constructor: the service is a
     * singleton in some request paths, and configuration read once at boot is
     * configuration that cannot be changed by a test or a runtime override.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * @return array{status:string, message_id?:string, message?:string}
     */
    public function sendSms(string $to, string $message): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            Log::warning('SMS not sent: no SMS_API_KEY is configured for this deployment.');

            return [
                'status' => 'error',
                'message' => 'SMS is not configured for this deployment.',
            ];
        }

        try {
            // Nigerian carrier links are slow and PHP-FPM workers are finite. A
            // send that has not completed in 15s will not complete.
            $response = Http::timeout(15)
                ->retry(2, 500, throw: false)
                ->post(self::ENDPOINT, [
                    'to' => $to,
                    'from' => $this->senderId(),
                    'sms' => $message,
                    'type' => 'plain',
                    'channel' => 'generic',
                    'api_key' => $apiKey,
                ]);

            if ($response->failed()) {
                Log::error('SMS gateway rejected the message', [
                    'status' => $response->status(),
                    'recipient' => $to,
                ]);

                return [
                    'status' => 'error',
                    'message' => 'SMS gateway returned ' . $response->status() . '.',
                ];
            }

            $payload = $response->json();

            return is_array($payload) ? $payload + ['status' => 'success'] : ['status' => 'success'];
        } catch (\Throwable $e) {
            Log::error('SMS gateway dispatch failure: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** Null rather than a mock sentinel, so "unset" has exactly one spelling. */
    private function apiKey(): ?string
    {
        $key = config('services.sms.api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function senderId(): string
    {
        return (string) config('services.sms.sender_id', 'SchoolPilot');
    }
}
