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

        if ($tenant->hasSchool()) {
            return $tenant->schoolId();
        }

        $schoolId = Auth::user()?->userProfile?->school_id;

        if ($schoolId !== null) {
            return (int) $schoolId;
        }

        /*
         * Host-resolved tenant, last. This is the path for routes reached
         * without an authenticated profile — the CBT offline-bundle endpoints a
         * lab machine calls, and anything resolved by subdomain before the user
         * is known. Several controllers each carried their own copy of this
         * fallback; they now share this one.
         */
        $tenantSchool = request()?->attributes->get('tenant_school');

        return $tenantSchool ? (int) $tenantSchool->id : null;
    }
}
