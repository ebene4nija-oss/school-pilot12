<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Models\School;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantResolutionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $appDomain = config('app.app_domain', 'localhost');

        if ($host !== $appDomain && str_ends_with($host, '.' . $appDomain)) {
            $subdomain = explode('.', $host)[0];
            $school = School::where('subdomain', $subdomain)->first();

            if (!$school) {
                return response()->json(['message' => 'School tenant subdomain not found.'], 404);
            }

            // Bind current tenant school into request
            $request->attributes->set('tenant_school', $school);
        }

        return $next($request);
    }
}
