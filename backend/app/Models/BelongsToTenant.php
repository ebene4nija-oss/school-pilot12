<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant()
    {
        static::addGlobalScope('school_id', function (Builder $builder) {
            $tenant = app(TenantContext::class);

            /*
             * Platform operators and the deliberately cross-tenant lookups
             * (the payment webhook resolving a gateway reference before it
             * knows the school) opt out explicitly.
             *
             * This used to be implicit: the scope simply did not apply when the
             * role happened to be super_admin, so "no scoping" and "we forgot
             * to scope" looked identical in the code.
             */
            if ($tenant->isUnscoped()) {
                return;
            }

            $schoolId = $tenant->schoolId();

            if ($schoolId === null) {
                /*
                 * No tenant established by middleware or by a job. Fall back to
                 * the acting user, which covers code running outside the HTTP
                 * cycle — console commands, tinker, and tests that query models
                 * directly rather than through a request.
                 *
                 * This is the path that used to run on *every* query, resolving
                 * the user's profile inside every model boot. It is now the
                 * exception rather than the rule: in a request the context is
                 * already populated, so the lookup never happens.
                 */
                $profile = Auth::check() ? Auth::user()->userProfile : null;

                if (! $profile || $profile->role === 'super_admin') {
                    /*
                     * Genuinely no tenant: the unauthenticated webhook, or a
                     * platform operator.
                     *
                     * Left unscoped, matching prior behaviour. Failing closed
                     * here is the correct end state but is a separate piece of
                     * work — it changes the result of every query made outside
                     * a request, and each of those paths has to be audited and
                     * given an explicit tenant first.
                     */
                    return;
                }

                $schoolId = $profile->school_id;
            }

            /*
             * Table-qualified. A bare `where('school_id', ...)` is ambiguous
             * the moment the query joins another tenant-scoped table —
             * `students`, `classes` and `homework` all carry the column — and
             * SQL rejects it outright. That made any joined aggregate a 500.
             */
            $builder->where(
                $builder->getModel()->qualifyColumn('school_id'),
                $schoolId
            );
        });

        static::creating(function (Model $model) {
            if (! empty($model->school_id)) {
                return;
            }

            $tenant = app(TenantContext::class);

            if ($tenant->hasSchool()) {
                $model->school_id = $tenant->schoolId();

                return;
            }

            // Fallback for paths that authenticate without going through the
            // tenant middleware.
            if (Auth::check() && Auth::user()->userProfile) {
                $model->school_id = Auth::user()->userProfile->school_id;
            }
        });
    }

    /**
     * Explicitly query across every tenant.
     *
     * Deliberately verbose at the call site: crossing the tenant boundary
     * should be visible when reading the query, not a property of who happens
     * to be logged in.
     */
    public function scopeAllTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope('school_id');
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
