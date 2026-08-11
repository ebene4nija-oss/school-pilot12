<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\QrCodeService;
use App\Services\TotpService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Two-factor enrolment (§1 of LAUNCH.md).
 *
 * The login path has verified TOTP codes for some time, but there was no way to
 * enrol: no route anywhere set `two_factor_secret`, so the pre-flight item
 * "mandatory TOTP for all School Admin and Super Admin accounts" was not
 * achievable — the only accounts with 2FA were ones seeded by hand in a test.
 *
 * Every state-changing call here re-checks the account password. These
 * endpoints sit behind `auth:sanctum`, but a bearer token lifted from a shared
 * staffroom browser should not be enough to move someone's second factor onto
 * the attacker's phone.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private TwoFactorService $twoFactor,
        private TotpService $totp,
        private QrCodeService $qr,
    ) {
    }

    /** Where this account stands, for the settings screen. */
    public function status(Request $request)
    {
        $profile = $request->user()->userProfile;

        return response()->json([
            'enabled' => (bool) $profile?->two_factor_enabled,
            'confirmed' => (bool) $profile?->hasCompletedTwoFactor(),
            'required' => $this->isRequiredFor($profile?->role),
            'recovery_codes_remaining' => count($profile?->two_factor_recovery_codes ?? []),
        ]);
    }

    /**
     * Step one: issue a secret and render it as a scannable QR.
     *
     * The secret is returned in text as well as in the QR because a proprietor
     * setting this up on the same phone they are reading it on cannot scan
     * their own screen.
     */
    public function setup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        if (! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'That password is not correct.'], 422);
        }

        $profile = $user->userProfile;

        if (! $profile) {
            return response()->json(['message' => 'This account has no profile to secure.'], 422);
        }

        if ($profile->hasCompletedTwoFactor()) {
            // Re-issuing would silently invalidate the authenticator they are
            // still using. Disabling first is an explicit, audited step.
            return response()->json([
                'message' => 'Two-factor authentication is already active. Disable it first to enrol a new device.',
            ], 409);
        }

        $secret = $this->twoFactor->beginEnrolment($profile);

        $issuer = $profile->school?->name ?: config('app.name', 'SchoolPilot');
        $uri = $this->totp->provisioningUri($secret, $user->email, $issuer);

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_code' => $this->qr->dataUriFor($uri, 4),
            'message' => 'Scan this in your authenticator app, then confirm with the six-digit code it shows.',
        ]);
    }

    /**
     * Step two: prove the authenticator works, then switch 2FA on.
     *
     * Returns the recovery codes. This is the only time they are ever readable.
     */
    public function confirm(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $profile = $user->userProfile;

        if (! $profile || trim((string) $profile->two_factor_secret) === '') {
            return response()->json(['message' => 'Start setup before confirming.'], 422);
        }

        $codes = $this->twoFactor->confirmEnrolment($profile, (string) $request->code);

        if ($codes === null) {
            return response()->json(['message' => 'That code is not valid. Check your phone clock and try the current code.'], 422);
        }

        $this->audit($request, $user, 'user.two_factor_enabled');

        return response()->json([
            'message' => 'Two-factor authentication is on.',
            'recovery_codes' => $codes,
            'warning' => 'Save these now. Each works once, and they cannot be shown again.',
        ]);
    }

    /** Fresh codes, invalidating the old set. */
    public function regenerateRecoveryCodes(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        if (! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'That password is not correct.'], 422);
        }

        $profile = $user->userProfile;

        if (! $profile || ! $profile->hasCompletedTwoFactor()) {
            return response()->json(['message' => 'Two-factor authentication is not active on this account.'], 422);
        }

        $this->audit($request, $user, 'user.two_factor_recovery_codes_regenerated');

        return response()->json([
            'recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($profile),
            'warning' => 'The previous codes no longer work.',
        ]);
    }

    /**
     * Turn 2FA off.
     *
     * Password *and* a current code: whoever disables it must hold both
     * factors, or a stolen session could quietly strip the account back down to
     * one.
     */
    public function disable(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
            'code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $profile = $user->userProfile;

        if (! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'That password is not correct.'], 422);
        }

        if (! $profile || ! $profile->hasCompletedTwoFactor()) {
            return response()->json(['message' => 'Two-factor authentication is not active on this account.'], 422);
        }

        $code = (string) $request->code;
        $verified = $this->totp->verifyCode((string) $profile->two_factor_secret, $code)
            || $this->twoFactor->consumeRecoveryCode($profile, $code);

        if (! $verified) {
            return response()->json(['message' => 'That code is not valid.'], 422);
        }

        if ($this->isRequiredFor($profile->role)) {
            return response()->json([
                'message' => 'Two-factor authentication is mandatory for this role and cannot be turned off.',
            ], 403);
        }

        $this->twoFactor->disable($profile);
        $this->audit($request, $user, 'user.two_factor_disabled');

        return response()->json(['message' => 'Two-factor authentication is off.']);
    }

    private function isRequiredFor(?string $role): bool
    {
        return $role !== null
            && (bool) config('auth.require_admin_two_factor', false)
            && in_array($role, ['school_admin', 'super_admin'], true);
    }

    private function audit(Request $request, User $user, string $action): void
    {
        AuditLog::create([
            'school_id' => $user->userProfile?->school_id,
            'user_id' => $user->id,
            'action' => $action,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
