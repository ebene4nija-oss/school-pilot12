<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the LAUNCH.md §1 pre-flight item: mandatory TOTP for School Admin
 * and Super Admin accounts.
 *
 * Off by default (`AUTH_REQUIRE_ADMIN_2FA`), because switching it on before the
 * admins of a live school have enrolled locks all of them out at once. The
 * intended sequence is: deploy, admins enrol through /auth/2fa/setup, then turn
 * the flag on as the last step before go-live.
 *
 * The block is deliberately *not* a login failure. An admin who has not enrolled
 * can still authenticate and reach the enrolment routes — they simply cannot
 * reach anything else. Refusing the login instead would leave them with no path
 * to comply.
 */
class RequireTwoFactorEnrolment
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth.require_admin_two_factor', false)) {
            return $next($request);
        }

        // The way out. Enrolment itself, and signing back out, must stay
        // reachable or the block has no exit.
        if ($request->is('api/v1/auth/2fa', 'api/v1/auth/2fa/*', 'api/v1/auth/logout')) {
            return $next($request);
        }

        $profile = $request->user()?->userProfile;

        if (! $profile || ! in_array($profile->role, ['school_admin', 'super_admin'], true)) {
            return $next($request);
        }

        if ($profile->hasCompletedTwoFactor()) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Two-factor authentication is required for administrator accounts. Set it up to continue.',
            'two_factor_setup_required' => true,
            'setup_url' => '/api/v1/auth/2fa/setup',
        ], 403);
    }
}
