<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Support\SchoolFeatures;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes a module's endpoints when SchoolPilot has switched it off for the
 * school. Keyed on path, so a module's routes need no per-route marking and a
 * new route under its prefix is covered automatically.
 */
class EnforceSchoolModules
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $path = preg_replace('#^api/v1/#', '', $request->path());
        $module = SchoolFeatures::moduleForPath($path);

        if ($module === null || $this->tenant->isUnscoped()) {
            return $next($request);
        }

        $schoolId = $this->tenant->hasSchool()
            ? $this->tenant->schoolId()
            : $request->user()?->userProfile?->school_id;

        if ($schoolId === null) {
            return $next($request);
        }

        $school = School::query()->select(['id', 'module_switches'])->find($schoolId);

        if (! SchoolFeatures::moduleEnabled($school, $module)) {
            return response()->json([
                'message' => SchoolFeatures::MODULES[$module].' is not switched on for this school.',
                'code' => 'module_disabled',
                'module' => $module,
            ], 403);
        }

        return $next($request);
    }
}
