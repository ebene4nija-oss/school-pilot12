<?php

namespace App\Services;

use App\Mail\CredentialLinkMail;
use App\Models\DeviceToken;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
        private WhatsAppService $whatsapp,
        private FcmService $fcm
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
                'email' => $this->email($user, $body, $context),
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
     *
     * The transport is FCM HTTP v1 (see FcmService). Sending is delegated so
     * that "which devices does this user have, and which of them are dead" —
     * the part that touches our data — stays here, and the Google-shaped part
     * stays somewhere it can be faked in a test.
     */
    public function push(User $user, string $body, array $context = []): array
    {
        $tokens = DeviceToken::where('user_id', $user->id)->get();

        if ($tokens->isEmpty()) {
            return $this->record($user, 'push', $body, 'failed', $context, 'No registered devices.');
        }

        if (! $this->fcm->isConfigured()) {
            // Explicit, like the Anthropic stub: never pretend a message went
            // out when the integration is not configured.
            return $this->record($user, 'push', $body, 'failed', $context, 'Push is not configured for this deployment.');
        }

        $sent = 0;
        $expired = 0;
        $reason = null;

        foreach ($tokens as $device) {
            $result = $this->fcm->sendToToken(
                $device->token,
                $context['title'] ?? 'SchoolPilot',
                $body,
                $context['data'] ?? []
            );

            match ($result['status']) {
                FcmService::SENT => $this->markDelivered($device, $sent),
                FcmService::INVALID_TOKEN => $this->forget($device, $expired),
                default => $reason = $result['reason'],
            };
        }

        if ($sent > 0) {
            return $this->record($user, 'push', $body, 'sent', $context);
        }

        /*
         * "Every device had been uninstalled" and "Firebase refused our
         * credentials" both end in nothing delivered, but only one of them is
         * the school's problem to act on. Say which.
         */
        $reason ??= $expired > 0
            ? 'All registered devices have been uninstalled or signed out.'
            : 'No device accepted the message.';

        return $this->record($user, 'push', $body, 'failed', $context, $reason);
    }

    private function markDelivered(DeviceToken $device, int &$sent): void
    {
        $sent++;
        $device->update(['last_used_at' => now()]);
    }

    private function forget(DeviceToken $device, int &$expired): void
    {
        $expired++;
        $device->delete();
    }

    public function sms(User $user, string $body, array $context = []): array
    {
        $phone = $user->userProfile?->phone;

        if (! $phone) {
            return $this->record($user, 'sms', $body, 'failed', $context, 'No phone number on file.');
        }

        // The recipient's own school sends, from its own account.
        $schoolId = $user->userProfile?->school_id;

        if (! $this->sms->isConfigured($schoolId)) {
            // Checked before the loop fans out, like push: one honest reason per
            // recipient beats 900 attempts we already know cannot authenticate.
            return $this->record($user, 'sms', $body, 'failed', $context, 'SMS is not set up for this school.', $phone);
        }

        $result = $this->sms->sendSms($phone, $body, $schoolId);
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

    /**
     * Email. Added for the things that cannot go over SMS — a password-reset
     * link is far too long for a 160-character segment, and the school pays per
     * segment.
     *
     * The transport is whatever MAIL_MAILER is set to. `log` is a real Laravel
     * driver and it does not fail, so a deployment left on the default writes
     * links to a logfile and nobody receives them; that is a deployment
     * mistake, not something this method can detect, and .env.example says so.
     */
    public function email(User $user, string $body, array $context = []): array
    {
        $address = $user->email;

        if (! $address) {
            return $this->record($user, 'email', $body, 'failed', $context, 'No email address on file.');
        }

        if ($refusal = $this->emailRefusal($user, $context)) {
            return $this->record($user, 'email', $body, 'failed', $context, $refusal, $address);
        }

        $subject = (string) ($context['subject'] ?? config('app.name', 'SchoolPilot'));

        try {
            Mail::to($address, $user->name)->send(new CredentialLinkMail($subject, $body));
        } catch (\Throwable $e) {
            Log::error('Mail dispatch failure: ' . $e->getMessage());

            return $this->record($user, 'email', $body, 'failed', $context, 'Mail transport rejected the message.', $address);
        }

        return $this->record($user, 'email', $body, 'sent', $context, null, $address);
    }

    /**
     * Messages that must always go out, whatever the school's email limits.
     *
     * A password-reset or account-setup link is the way back into an account.
     * Counting it against a daily cap would lock out exactly the parent who
     * most needs to get in, on exactly the day a school has been busy.
     */
    public const TRANSACTIONAL_CATEGORIES = ['password_reset', 'account_setup'];

    /**
     * Why this email may not be sent, or null if it may.
     *
     * The platform — not the school — decides whether a school can email and
     * how much in a day. A school admin able to raise their own cap would not
     * be under one, and a runaway broadcast to three thousand parents is the
     * kind of thing that gets the sending domain blocklisted for every school
     * on the platform, not just the one that sent it.
     */
    private function emailRefusal(User $user, array $context): ?string
    {
        if (in_array($context['category'] ?? null, self::TRANSACTIONAL_CATEGORIES, true)) {
            return null;
        }

        $schoolId = $user->userProfile?->school_id;

        if (! $schoolId) {
            return null;
        }

        $school = \App\Models\School::find($schoolId);

        if (! $school) {
            return null;
        }

        if (! $school->email_enabled) {
            return 'Email is switched off for this school by SchoolPilot.';
        }

        $limit = static::emailDailyLimit($school);

        if ($limit !== null && static::emailsSentToday($schoolId) >= $limit) {
            return "Daily email limit of {$limit} reached for this school. Sending resumes tomorrow.";
        }

        return null;
    }

    /** The school's own cap if the platform set one, else the platform default. */
    public static function emailDailyLimit(\App\Models\School $school): ?int
    {
        if ($school->email_daily_limit !== null) {
            return (int) $school->email_daily_limit;
        }

        $default = config('schoolpilot.email_daily_limit');

        return is_numeric($default) && (int) $default > 0 ? (int) $default : null;
    }

    /** Emails this school has actually sent today. Transactional mail counts too. */
    public static function emailsSentToday(int $schoolId): int
    {
        return NotificationLog::withoutGlobalScope('school_id')
            ->where('school_id', $schoolId)
            ->where('channel', 'email')
            ->where('status', 'sent')
            ->where('sent_at', '>=', now()->startOfDay())
            ->count();
    }

    public function whatsapp(User $user, string $body, array $context = []): array
    {
        $phone = $user->userProfile?->phone;

        if (! $phone) {
            return $this->record($user, 'whatsapp', $body, 'failed', $context, 'No phone number on file.');
        }

        if (! $this->whatsapp->isConfigured()) {
            return $this->record($user, 'whatsapp', $body, 'failed', $context, 'WhatsApp is not configured for this deployment.', $phone);
        }

        $result = $this->whatsapp->sendMessage($phone, $body);

        // Was `!== 'error'`, which counted an explicit `['status' => 'failed']`
        // from the gateway as a delivery. Only success is success.
        $ok = ($result['status'] ?? null) === 'success' || isset($result['message_id']);

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
        $schoolId = $user->userProfile?->school_id;

        /*
         * notification_logs.school_id is not nullable, and a user without a
         * profile is reachable here — a super admin has no school. Skip the row
         * rather than let a logging concern throw out of a send.
         */
        if (! $schoolId) {
            return array_filter([
                'status' => $status,
                'reason' => $failure,
            ], fn ($v) => $v !== null);
        }

        NotificationLog::create([
            'school_id' => $schoolId,
            'user_id' => $user->id,
            'sent_by' => $context['sent_by'] ?? null,
            'channel' => $channel,
            'category' => $context['category'] ?? null,
            'recipient' => $recipient,
            /*
             * `log_body` is how a caller sends one thing and records another.
             * It exists for credentials: a school admin can read
             * GET /notifications/history, so a password-reset link stored here
             * verbatim would be a working account takeover for anyone with
             * admin access. The link goes out; a description is what is kept.
             */
            'body' => $context['log_body'] ?? $body,
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
