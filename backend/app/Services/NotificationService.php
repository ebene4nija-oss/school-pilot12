<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One way out of the building for every notification (§7.13).
 *
 * Before this, WhatsApp was called inline from two controllers, SmsService was
 * never called at all, and push did not exist. Everything now goes through
 * here so that the send is logged once, in one shape, and a school can answer
 * "what did we send, to whom, and did it arrive" — which matters because SMS
 * costs them money per message.
 *
 * Failures are recorded and swallowed, not thrown. A parent's dead phone token
 * must not roll back the fee payment that triggered the notification.
 */
class NotificationService
{
    public function __construct(
        private SmsService $sms,
        private WhatsAppService $whatsapp
    ) {
    }

    /**
     * Send on the channels a school has actually configured, preferring push
     * (free) over SMS (billed per message).
     *
     * @param array<int,string> $channels
     * @return array<string,mixed>
     */
    public function notify(User $user, string $body, array $channels = ['push'], array $context = []): array
    {
        $results = [];

        foreach ($channels as $channel) {
            $results[$channel] = match ($channel) {
                'push' => $this->push($user, $body, $context),
                'sms' => $this->sms($user, $body, $context),
                'whatsapp' => $this->whatsapp($user, $body, $context),
                default => ['status' => 'failed', 'reason' => "Unknown channel {$channel}"],
            };
        }

        return $results;
    }

    /**
     * Push to every device this user has registered.
     *
     * Tokens that FCM reports as dead are deleted rather than retried forever —
     * a school of 900 families accumulates thousands of stale tokens within a
     * year of handset churn.
     */
    public function push(User $user, string $body, array $context = []): array
    {
        $tokens = DeviceToken::where('user_id', $user->id)->get();

        if ($tokens->isEmpty()) {
            return $this->record($user, 'push', $body, 'failed', $context, 'No registered devices.');
        }

        $key = config('services.fcm.server_key');

        if (! $key) {
            // Explicit, like the Anthropic stub: never pretend a message went
            // out when the integration is not configured.
            return $this->record($user, 'push', $body, 'failed', $context, 'Push is not configured for this deployment.');
        }

        $sent = 0;

        foreach ($tokens as $device) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'key=' . $key,
                    'Content-Type' => 'application/json',
                ])
                    ->timeout(15)
                    ->retry(2, 300, throw: false)
                    ->post('https://fcm.googleapis.com/fcm/send', [
                        'to' => $device->token,
                        'notification' => [
                            'title' => $context['title'] ?? 'SchoolPilot',
                            'body' => $body,
                        ],
                        'data' => $context['data'] ?? [],
                    ]);

                if ($response->successful()) {
                    $sent++;
                    $device->update(['last_used_at' => now()]);
                    continue;
                }

                if (str_contains(strtolower($response->body()), 'notregistered')
                    || str_contains(strtolower($response->body()), 'invalidregistration')) {
                    $device->delete();
                }
            } catch (\Throwable $e) {
                Log::warning('Push send failed: ' . $e->getMessage());
            }
        }

        return $sent > 0
            ? $this->record($user, 'push', $body, 'sent', $context)
            : $this->record($user, 'push', $body, 'failed', $context, 'No device accepted the message.');
    }

    public function sms(User $user, string $body, array $context = []): array
    {
        $phone = $user->userProfile?->phone;

        if (! $phone) {
            return $this->record($user, 'sms', $body, 'failed', $context, 'No phone number on file.');
        }

        $result = $this->sms->sendSms($phone, $body);
        $ok = ($result['status'] ?? null) === 'success' || isset($result['message_id']);

        return $this->record(
            $user,
            'sms',
            $body,
            $ok ? 'sent' : 'failed',
            $context,
            $ok ? null : ($result['message'] ?? 'SMS gateway rejected the message.'),
            $phone
        );
    }

    public function whatsapp(User $user, string $body, array $context = []): array
    {
        $phone = $user->userProfile?->phone;

        if (! $phone) {
            return $this->record($user, 'whatsapp', $body, 'failed', $context, 'No phone number on file.');
        }

        $result = $this->whatsapp->sendMessage($phone, $body);
        $ok = ($result['status'] ?? null) !== 'error';

        return $this->record(
            $user,
            'whatsapp',
            $body,
            $ok ? 'sent' : 'failed',
            $context,
            $ok ? null : ($result['message'] ?? 'WhatsApp send failed.'),
            $phone
        );
    }

    private function record(
        User $user,
        string $channel,
        string $body,
        string $status,
        array $context,
        ?string $failure = null,
        ?string $recipient = null
    ): array {
        NotificationLog::create([
            'school_id' => $user->userProfile?->school_id,
            'user_id' => $user->id,
            'sent_by' => $context['sent_by'] ?? null,
            'channel' => $channel,
            'category' => $context['category'] ?? null,
            'recipient' => $recipient,
            'body' => $body,
            'status' => $status,
            'failure_reason' => $failure,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);

        return array_filter([
            'status' => $status,
            'reason' => $failure,
        ], fn ($v) => $v !== null);
    }
}
