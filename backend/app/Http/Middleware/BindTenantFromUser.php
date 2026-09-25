<?php

namespace App\Http\Middleware;

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
        $profile = $request->user()?->userProfile;

        if ($profile) {
            if ($profile->role === 'super_admin') {
                $viewing = $this->requestedSchool($request);

                if ($viewing !== null) {
                    // Inspecting one tenant: scope exactly as a member of that
                    // school would be, so every school-side endpoint answers
                    // for that school and nothing leaks in from the others.
                    $this->tenant->setSchoolId($viewing);
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
                }
            } elseif ($profile->school_id) {
                $this->tenant->setSchoolId((int) $profile->school_id);
            }
        }

        return $next($request);
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
