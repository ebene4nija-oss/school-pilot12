<?php

namespace App\Services;

use App\Models\UserProfile;
use Illuminate\Support\Facades\Hash;

/**
 * Enrolment and recovery for admin two-factor authentication.
 *
 * TotpService does the arithmetic; this holds the account state around it —
 * issuing a secret, confirming the admin can actually generate codes from it,
 * and the recovery codes that stop a lost handset from costing a proprietor
 * their school.
 *
 * Recovery codes are stored the way passwords are: hashed, never recoverable,
 * shown exactly once at the moment they are generated. A school admin who
 * loses both their phone and the printout has to be reset by a super admin,
 * which is the correct trade — the alternative is a second copy of the
 * bypass credential sitting in the database.
 */
class TwoFactorService
{
    /** Enough that losing a couple to mistyping is not a crisis. */
    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private TotpService $totp)
    {
    }

    /**
     * Issue a secret and stage it against the account.
     *
     * Deliberately does not set `two_factor_enabled`. Between this call and
     * confirm() the admin has a secret they may never have scanned; enabling
     * here would lock them out of their own school at the next login.
     */
    public function beginEnrolment(UserProfile $profile): string
    {
        $secret = $this->totp->generateSecret();

        $profile->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_enabled' => false,
        ])->save();

        return $secret;
    }

    /**
     * Verify a code from the admin's authenticator and switch 2FA on.
     *
     * @return string[]|null the one-time recovery codes, or null if the code
     *                       did not verify
     */
    public function confirmEnrolment(UserProfile $profile, string $code): ?array
    {
        $secret = (string) $profile->two_factor_secret;

        if (trim($secret) === '' || ! $this->totp->verifyCode($secret, $code)) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $profile->forceFill([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(static fn (string $c) => Hash::make($c), $codes),
        ])->save();

        return $codes;
    }

    /**
     * Fresh recovery codes, invalidating the previous set.
     *
     * @return string[] plaintext, to be shown once
     */
    public function regenerateRecoveryCodes(UserProfile $profile): array
    {
        $codes = $this->generateRecoveryCodes();

        $profile->forceFill([
            'two_factor_recovery_codes' => array_map(static fn (string $c) => Hash::make($c), $codes),
        ])->save();

        return $codes;
    }

    /**
     * Spend a recovery code.
     *
     * Single use: a matching code is removed from the stored set before this
     * returns, so a code read off a shoulder-surfed printout works at most
     * once. Every stored hash is checked rather than breaking early, so the
     * time taken does not reveal the position of the match.
     */
    public function consumeRecoveryCode(UserProfile $profile, string $candidate): bool
    {
        $stored = $profile->two_factor_recovery_codes ?? [];

        if (! is_array($stored) || $stored === []) {
            return false;
        }

        $normalised = $this->normalise($candidate);
        $remaining = [];
        $matched = false;

        foreach ($stored as $hash) {
            if (! $matched && Hash::check($normalised, $hash)) {
                $matched = true;
                continue;
            }

            $remaining[] = $hash;
        }

        if ($matched) {
            $profile->forceFill(['two_factor_recovery_codes' => $remaining])->save();
        }

        return $matched;
    }

    /** Turn 2FA off and clear every credential associated with it. */
    public function disable(UserProfile $profile): void
    {
        $profile->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();
    }

    /**
     * @return string[] e.g. "7FQ2K-M4X9B"
     */
    private function generateRecoveryCodes(): array
    {
        // Crockford-ish: no O/0, I/1 or L, because these get written on paper
        // and read back by someone who did not write them.
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code = '';
            for ($c = 0; $c < 10; $c++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
        }

        return $codes;
    }

    /** Accept what the admin actually types: any case, hyphen optional. */
    private function normalise(string $code): string
    {
        $bare = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return strlen($bare) === 10
            ? substr($bare, 0, 5) . '-' . substr($bare, 5)
            : $bare;
    }
}
