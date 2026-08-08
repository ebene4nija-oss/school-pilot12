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
                $this->tenant->markUnscoped();
            } elseif ($profile->school_id) {
                $this->tenant->setSchoolId((int) $profile->school_id);
            }
        }

        return $next($request);
    }
}
