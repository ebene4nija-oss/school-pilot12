<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\PasswordResetService;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
            'subdomain' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $profile = $user->userProfile;

        /*
         * Tenant binding.
         *
         * The tenant is taken from the resolved host first (set by
         * TenantResolutionMiddleware) and only then from the request body.
         * Previously the body was the sole source and was optional, so a user
         * from one school could authenticate against another school's
         * subdomain — the middleware resolved a tenant that nothing enforced.
         */
        $tenant = $request->attributes->get('tenant_school');

        if (!$tenant && $request->subdomain) {
            $tenant = School::where('subdomain', $request->subdomain)->first();

            if (!$tenant) {
                return response()->json(['message' => 'School tenant subdomain not found.'], 404);
            }
        }

        if ($tenant && $profile && $profile->role !== 'super_admin' && $profile->school_id !== $tenant->id) {
            AuditLog::create([
                'school_id' => $tenant->id,
                'user_id' => $user->id,
                'action' => 'user.login_wrong_tenant',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json(['message' => 'User does not belong to this school tenant.'], 403);
        }

        // Two-factor for privileged roles.
        if ($profile && in_array($profile->role, ['school_admin', 'super_admin']) && $profile->two_factor_enabled) {
            if (!$request->two_factor_code) {
                return response()->json([
                    'message' => 'Two-factor authentication code required.',
                    'requires_2fa' => true
                ], 422);
            }

            $secret = (string) ($profile->two_factor_secret ?? '');

            // Fail closed. An account flagged for 2FA without a stored secret is
            // misconfigured, not exempt — there is no fallback secret.
            if (trim($secret) === '') {
                Log::warning('2FA is enabled without a stored secret.', [
                    'user_id' => $user->id,
                    'school_id' => $profile->school_id,
                ]);

                return response()->json([
                    'message' => 'Two-factor authentication is not fully set up on this account. Contact your administrator.',
                ], 422);
            }

            /*
             * A recovery code is accepted in place of the six digits. A
             * proprietor whose phone is lost, stolen or simply dead needs a way
             * back into their own school that does not depend on us; each code
             * is single-use and is spent by this check.
             */
            $code = (string) $request->two_factor_code;

            $verified = app(TotpService::class)->verifyCode($secret, $code)
                || app(\App\Services\TwoFactorService::class)->consumeRecoveryCode($profile, $code);

            if (!$verified) {
                return response()->json(['message' => 'Invalid 2FA code.'], 422);
            }
        }

        $token = $user->createToken('schoolpilot-auth-token')->plainTextToken;

        AuditLog::create([
            'school_id' => $profile ? $profile->school_id : null,
            'user_id' => $user->id,
            'action' => 'user.login',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $profile ? $profile->role : 'student',
                'school_id' => $profile ? $profile->school_id : null,
            ]
        ]);
    }

    /**
     * End this session (gap G1).
     *
     * Only the token that made the request is deleted, not every token the user
     * holds: signing out of a phone must not sign the same teacher out of the
     * browser they left open in the staff room.
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        AuditLog::create([
            'school_id' => $user->userProfile?->school_id,
            'user_id' => $user->id,
            'action' => 'user.logout',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * Ask for a reset link (gap G2).
     *
     * The response is the same whether or not the address exists, whether or
     * not it belongs to this school, and whether or not a link was actually
     * sent. Anything else turns this endpoint into a membership oracle: "is
     * this parent's email registered at this school" is exactly the kind of
     * question a stranger should not be able to ask, and under NDPA (doc §12)
     * the answer is personal data in itself.
     */
    public function forgotPassword(Request $request, PasswordResetService $resets)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $generic = response()->json([
            'message' => 'If that email address has an account, a reset link is on its way to it.',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return $generic;
        }

        $profile = $user->userProfile;
        $tenant = $this->resolveTenant($request);

        // Wrong tenant: same silence as an unknown address. A reset link sent
        // from one school's host to another school's user would also be a
        // cross-tenant leak of which school a person belongs to.
        if ($tenant && $profile && $profile->role !== 'super_admin' && $profile->school_id !== $tenant->id) {
            return $generic;
        }

        if ($resets->recentlyRequested($user)) {
            return $generic;
        }

        $school = $tenant ?? ($profile?->school_id ? School::find($profile->school_id) : null);

        $resets->sendResetLink($user, $school);

        AuditLog::create([
            'school_id' => $profile?->school_id,
            'user_id' => $user->id,
            'action' => 'user.password_reset_requested',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $generic;
    }

    /**
     * Redeem a reset or account-setup link (gap G2).
     *
     * Succeeding here revokes every existing token. If the reason for the reset
     * was that somebody else had the old password, leaving their sessions alive
     * would make the reset pointless.
     */
    public function resetPassword(Request $request, PasswordResetService $resets)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        // One message for "no such user", "wrong token" and "expired token" —
        // the caller cannot tell them apart, so the link cannot be used to
        // probe for accounts either.
        $invalid = response()->json([
            'message' => 'That reset link is invalid or has expired. Request a new one.',
        ], 422);

        if (! $user || ! $resets->tokenIsValid($user, $request->token)) {
            return $invalid;
        }

        $user->update([
            'password' => $request->password,
            'must_change_password' => false,
            'password_changed_at' => now(),

            // Someone who has proved control of the mailbox should not still be
            // shut out by a lockout the forgotten password caused.
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        $resets->consumeToken($user);
        $user->tokens()->delete();

        AuditLog::create([
            'school_id' => $user->userProfile?->school_id,
            'user_id' => $user->id,
            'action' => 'user.password_reset_completed',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Password updated. Sign in with your new password.',
        ]);
    }

    /**
     * Host first, request body second — the same precedence login uses.
     */
    private function resolveTenant(Request $request): ?School
    {
        $tenant = $request->attributes->get('tenant_school');

        if (! $tenant && $request->subdomain) {
            $tenant = School::where('subdomain', $request->subdomain)->first();
        }

        return $tenant;
    }

    public function inviteUser(Request $request, PasswordResetService $resets)
    {
        $admin = $request->user();
        $profile = $admin->userProfile;

        if (!$profile || !in_array($profile->role, ['super_admin', 'school_admin'])) {
            return response()->json(['message' => 'Unauthorized to invite users.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|in:school_admin,teacher,student,parent',
            'phone' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tempPassword = bin2hex(random_bytes(16));

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($tempPassword),
        ]);

        UserProfile::create([
            'school_id' => $profile->school_id,
            'user_id' => $user->id,
            'role' => $request->role,
            'phone' => $request->phone,
        ]);

        AuditLog::create([
            'school_id' => $profile->school_id,
            'user_id' => $admin->id,
            'action' => 'user.invited',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'new_values' => ['email' => $user->email, 'role' => $request->role],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        /*
         * The temporary password is neither returned nor kept: it exists only
         * so the row is never briefly created with an empty or guessable
         * password. What the invitee actually receives is a single-use,
         * expiring setup link, sent directly to them.
         *
         * This is what the response body used to do instead, which put a
         * working credential into request logs, browser history and any
         * client-side error reporting.
         */
        $delivery = $resets->sendSetupLink($user, School::find($profile->school_id));

        return response()->json([
            'message' => ($delivery['email']['status'] ?? null) === 'sent'
                ? 'User invited. A setup link has been sent to their email address.'
                : 'User invited, but the setup link could not be delivered. Ask them to use "Forgot password" on the sign-in screen.',
            'user_id' => $user->id,
            'setup_link_sent' => ($delivery['email']['status'] ?? null) === 'sent',
        ], 201);
    }
}
