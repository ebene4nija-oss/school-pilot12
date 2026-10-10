<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * The school this request is acting on.
     *
     * The resolved tenant comes first, the acting user's own profile second.
     * That order is the whole point: a platform operator inspecting one school
     * through the console has a null `school_id` on their profile but a tenant
     * set by BindTenantFromUser, so reading the profile first answered null for
     * them everywhere.
     *
     * Null there was not a harmless zero. `SchoolInsightsService` type-hints
     * `int $schoolId`, so the principal dashboard died with a TypeError — a 500
     * on the first screen after sign-in — while the endpoints that merely
     * filtered on it reported an empty school instead, which is worse, because
     * an empty school looks like an answer.
     *
     * For every school-side role this returns exactly what it always did: their
     * tenant is set from their own profile at the start of the request.
     */
    protected function schoolId(): ?int
    {
        $tenant = app(TenantContext::class);

        /*
         * An explicitly unscoped request has no acting school, whatever else is
         * lying around on the context.
         *
         * This check is not redundant. TenantResolutionMiddleware runs before
         * authentication and resolves a school from the host, and a platform
         * operator signs in through one — the internal `admin` subdomain — so
         * by the time BindTenantFromUser marks them unscoped there is already a
         * school id set, and `markUnscoped()` does not clear it. Reading
         * `hasSchool()` alone therefore reported an operator as being *inside*
         * the school they merely signed in through: the portal greeted them
         * with that school's dashboard and named it in the chrome, instead of
         * their own console.
         */
        if ($tenant->isUnscoped()) {
            return null;
        }

        if ($tenant->hasSchool()) {
            return $tenant->schoolId();
        }

        $schoolId = Auth::user()?->userProfile?->school_id;

        if ($schoolId !== null) {
            return (int) $schoolId;
        }

        /*
         * Host-resolved tenant, last. This is the path for routes reached
         * without an authenticated profile — anything resolved by subdomain
         * before the user is known. Several controllers each carried their own copy of this
         * fallback; they now share this one.
         */
        $tenantSchool = request()?->attributes->get('tenant_school');

        return $tenantSchool ? (int) $tenantSchool->id : null;
    }
}
