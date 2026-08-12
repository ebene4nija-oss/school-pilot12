<?php

namespace App\Services;

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Password;

/**
 * Password-reset and account-setup links (doc §2, gap G2).
 *
 * Before this there was no way for a user to recover an account: the only path
 * was an admin generating a temporary password and reading it down the phone,
 * which put a working credential in an API response body, in request logs, and
 * in whoever's notebook wrote it down. A parent who forgot their password had
 * to ring the school office.
 *
 * Token storage, hashing and expiry are Laravel's `password_reset_tokens`
 * broker — already configured in config/auth.php and, until now, never used by
 * anything. The broker is used through its repository rather than
 * `Password::sendResetLink()` because delivery here is ours: it has to reach a
 * parent in Nigeria, which may mean SMS rather than email, and it has to be
 * recorded without writing the token itself into notification history.
 */
class PasswordResetService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /**
     * Issue a single-use token, replacing any outstanding one for this user.
     */
    public function issueToken(User $user): string
    {
        return Password::getRepository()->create($user);
    }

    public function tokenIsValid(User $user, string $token): bool
    {
        return Password::getRepository()->exists($user, $token);
    }

    public function consumeToken(User $user): void
    {
        Password::getRepository()->delete($user);
    }

    /**
     * The broker's own throttle window (config/auth.php `throttle`, 60s).
     *
     * Checked explicitly because that guard lives in `sendResetLink()`, which
     * we do not call — without this, a script could make the school pay for an
     * SMS on every request.
     */
    public function recentlyRequested(User $user): bool
    {
        return Password::getRepository()->recentlyCreatedToken($user);
    }

    public function expiryMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }

    /**
     * A link a human clicks, pointed at the web portal rather than the API.
     *
     * The email is carried in the query string because the token alone does not
     * identify a user — `password_reset_tokens` is keyed by email — and the
     * reset endpoint needs both to verify the pair.
     */
    public function linkFor(User $user, string $token, ?School $school = null): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        // Multi-tenant deployments serve each school from its own host; a
        // single-host one simply has no placeholder to replace.
        $base = str_replace('{subdomain}', (string) ($school?->subdomain ?? ''), $base);

        return $base . '/reset-password?' . http_build_query([
            'token' => $token,
            'email' => $user->email,
        ]);
    }

    /**
     * "I forgot my password" — the user asked for this themselves.
     *
     * @return array<string,mixed>
     */
    public function sendResetLink(User $user, ?School $school = null): array
    {
        $link = $this->linkFor($user, $this->issueToken($user), $school);
        $minutes = $this->expiryMinutes();
        $schoolName = $school?->name ?? config('app.name', 'SchoolPilot');

        return $this->deliver(
            $user,
            subject: 'Reset your ' . $schoolName . ' password',
            body: "Hello {$user->name},\n\n"
                . "We received a request to reset your {$schoolName} password. "
                . "Open the link below to choose a new one:\n\n{$link}\n\n"
                . "The link expires in {$minutes} minutes. If it expires, request another one from the sign-in screen.\n\n"
                . "If you did not ask for this, you can ignore this message — your password has not changed.",
            smsBody: "{$schoolName}: reset your password here (expires in {$minutes} min): {$link}",
            category: 'password_reset',
            logBody: 'Password reset link sent. The link itself is not stored.'
        );
    }

    /**
     * A link for an account that has never been used — a fresh invite, or an
     * admin resetting someone who is locked out.
     *
     * Deliberately the same mechanism as a reset. An invite link expiring in an
     * hour used to be a problem worth building a longer-lived token for; it is
     * not one any more, because a user whose link has gone stale can now get a
     * new one from the sign-in screen without involving the office.
     *
     * @return array<string,mixed>
     */
    public function sendSetupLink(User $user, ?School $school = null, bool $isNewAccount = true): array
    {
        $link = $this->linkFor($user, $this->issueToken($user), $school);
        $minutes = $this->expiryMinutes();
        $schoolName = $school?->name ?? config('app.name', 'SchoolPilot');

        $opening = $isNewAccount
            ? "An account has been created for you on {$schoolName}'s SchoolPilot portal."
            : "Your {$schoolName} password has been reset by an administrator.";

        return $this->deliver(
            $user,
            subject: $isNewAccount
                ? 'Set up your ' . $schoolName . ' account'
                : 'Your ' . $schoolName . ' password was reset',
            body: "Hello {$user->name},\n\n{$opening}\n\n"
                . "Set your password here:\n\n{$link}\n\n"
                . "Sign in afterwards with this email address: {$user->email}\n\n"
                . "The link expires in {$minutes} minutes. If it expires, use \"Forgot password\" on the sign-in screen to get a new one.",
            smsBody: "{$schoolName}: set your SchoolPilot password here (expires in {$minutes} min): {$link}",
            category: $isNewAccount ? 'account_setup' : 'password_reset',
            logBody: $isNewAccount
                ? 'Account setup link sent. The link itself is not stored.'
                : 'Admin-initiated password reset link sent. The link itself is not stored.'
        );
    }

    /**
     * Email always; SMS only where a school has opted in.
     *
     * Every account has an email address — it is unique and required on
     * `users` — so email alone reaches everyone. SMS is the channel parents
     * actually read, but it is billed per segment and a reset link is long
     * enough to cost two, so a school turns it on knowingly.
     *
     * @return array<string,mixed>
     */
    private function deliver(
        User $user,
        string $subject,
        string $body,
        string $smsBody,
        string $category,
        string $logBody
    ): array {
        $context = [
            'subject' => $subject,
            'category' => $category,
            'log_body' => $logBody,
        ];

        $results = ['email' => $this->notifications->email($user, $body, $context)];

        if (config('services.sms.password_links', false) && $user->userProfile?->phone) {
            $results['sms'] = $this->notifications->sms($user, $smsBody, $context);
        }

        return $results;
    }
}
