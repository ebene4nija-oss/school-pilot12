<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TenantResolutionMiddleware
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $host = explode(':', $request->getHost())[0];
        $school = null;

        if (str_contains($host, '.')) {
            $subdomain = explode('.', $host)[0];

            if ($subdomain !== 'localhost' && $subdomain !== '127') {
                $school = School::where('subdomain', $subdomain)->first();

                if (! $school) {
                    return response()->json(['message' => 'School tenant subdomain not found.'], 404);
                }

                $request->attributes->set('tenant_school', $school);
            }
        }

        /*
         * The browser cannot set a `Host` header on `fetch`, so the web client
         * sends the tenant explicitly. Only consulted when the host did not
         * already identify a school — a header must never be able to override
         * a real subdomain.
         */
        if (! $school && $request->hasHeader('X-School-Subdomain')) {
            $school = School::where('subdomain', $request->header('X-School-Subdomain'))->first();

            if ($school) {
                $request->attributes->set('tenant_school', $school);
            }
        }

        if ($school) {
            $this->tenant->setSchoolId((int) $school->id);
        }

        $response = $next($request);

        // Queue workers and long-lived processes reuse the container, so the
        // tenant must not survive into whatever this process handles next.
        $this->tenant->reset();

        return $response;
    }
}
