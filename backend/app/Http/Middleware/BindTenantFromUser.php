<?php

namespace App\Http\Middleware;

use App\Models\PlatformSchoolAccess;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establish the tenant from the authenticated user.
 *
 * Runs after `auth:sanctum`, because TenantResolutionMiddleware executes
 * before authentication and can only see the host. Two cases it covers:
 *
 * - Deployments and local development served from a bare host (`localhost`),
 *   where there is no subdomain to resolve.
 * - Super admins, who belong to no school and must not be silently scoped to
 *   one — they are marked unscoped explicitly, so "operates across tenants" is
 *   a stated property rather than the accident of a null `school_id`.
 *
 * The user's own school always wins over the host. A user authenticating
 * against another school's subdomain is already rejected at login; this makes
 * sure that even if it happened, their queries stay inside their own tenant.
 *
 * A super admin may additionally name one school with `X-Platform-School`,
 * which is how the operator console's school picker inspects a single tenant.
 * It is honoured only while that operator holds a live `PlatformSchoolAccess`
 * grant for that school — opened with a reason, audited, and time-limited.
 * Without one, an operator reaches the console's own routes and nothing else.
 * That header is read for super admins and for nobody else: for any other role
 * it is ignored outright rather than validated, so no school-side user can
 * widen or move their own tenant by setting a request header.
 */
class BindTenantFromUser
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->user()?->status, \App\Models\User::BLOCKED_STATUSES, true)) {
            $request->user()->currentAccessToken()?->delete();

            return response()->json(['message' => 'This account has been switched off.'], 401);
        }

        $profile = $request->user()?->userProfile;

        if ($profile) {
            if ($profile->role === 'super_admin') {
                $viewing = $this->requestedSchool($request);
                $access = $viewing !== null ? $this->liveAccess($request->user()->id, $viewing) : null;

                if ($access !== null) {
                    // Inspecting one tenant: scope exactly as a member of that
                    // school would be, so every school-side endpoint answers
                    // for that school and nothing leaks in from the others.
                    $this->tenant->setSchoolId($viewing);
                    $request->attributes->set('platform_access', $access);
                } else {
                    /*
                     * Clear the host-resolved school before marking unscoped.
                     * TenantResolutionMiddleware has already set one — an
                     * operator signs in through the internal `admin` subdomain,
                     * which is a real row in `schools` — and leaving it in place
                     * makes the context say two contradictory things at once:
                     * unscoped, yet inside a school.
                     */
                    $this->tenant->setSchoolId(null);
                    $this->tenant->markUnscoped();

                    /*
                     * Unscoped, an operator's query on a school-side route
                     * spans every tenant — `/students` returned every child on
                     * the platform. A school's records are reached only through
                     * a live access grant, which records who, which school and
                     * why. Without one, only the operator's own routes answer.
                     */
                    if (! $this->isOperatorRoute($request)) {
                        return response()->json([
                            'message' => $viewing !== null
                                ? 'Your access to this school has ended. Open it again from the operator console, with a reason, to continue.'
                                : 'Open a school from the operator console, with a reason, to see its records.',
                            'code' => 'school_access_required',
                        ], 403);
                    }
                }
            } elseif ($profile->role === 'partner') {
                /*
                 * A partner belongs to no school. The host may still have
                 * resolved one, so it is cleared, and the partner is held to
                 * their own portal: a route that is not the partner's, or the
                 * account routes, is refused rather than answered for whichever
                 * school the host happened to name.
                 */
                $this->tenant->setSchoolId(null);

                if (! $request->is('api/v1/partner', 'api/v1/partner/*', 'api/v1/user', 'api/v1/auth/*')) {
                    return response()->json(['message' => 'Forbidden: You do not have permission to access this resource.'], 403);
                }
            } elseif ($profile->school_id) {
                $this->tenant->setSchoolId((int) $profile->school_id);
            }
        }

        return $next($request);
    }

    /**
     * Routes an operator uses without being inside a school: the console's own
     * `/platform/*`, who-am-I, and account routes (2FA, sign-out).
     */
    private function isOperatorRoute(Request $request): bool
    {
        return $request->is('api/v1/platform', 'api/v1/platform/*', 'api/v1/user', 'api/v1/auth/*');
    }

    /** This operator's unexpired, unclosed grant for this school, if any. */
    private function liveAccess(int $userId, int $schoolId): ?PlatformSchoolAccess
    {
        return PlatformSchoolAccess::query()
            ->live()
            ->where('user_id', $userId)
            ->where('school_id', $schoolId)
            ->latest('id')
            ->first();
    }

    /**
     * The school a platform operator asked to look at, if it exists.
     *
     * Checked against `schools` rather than trusted, so a stale picker in the
     * console cannot scope a request to an id that was deleted — which would
     * otherwise read as "this school has no students" instead of "no such
     * school".
     */
    private function requestedSchool(Request $request): ?int
    {
        $header = $request->header('X-Platform-School');

        if ($header === null || ! ctype_digit((string) $header)) {
            return null;
        }

        $schoolId = (int) $header;

        $exists = $this->tenant->withoutScoping(
            fn () => \App\Models\School::whereKey($schoolId)->exists()
        );

        return $exists ? $schoolId : null;
    }
}
