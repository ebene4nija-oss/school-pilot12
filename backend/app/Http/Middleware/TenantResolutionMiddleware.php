<?php

namespace App\Http\Middleware;

use App\Models\School;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantResolutionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = explode(':', $request->getHost())[0];
        
        if (str_contains($host, '.')) {
            $subdomain = explode('.', $host)[0];
            if ($subdomain !== 'localhost' && $subdomain !== '127') {
                $school = School::where('subdomain', $subdomain)->first();

                if (!$school) {
                    return response()->json(['message' => 'School tenant subdomain not found.'], 404);
                }

                $request->attributes->set('tenant_school', $school);
            }
        }

        return $next($request);
    }
}
