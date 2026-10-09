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
        $host = strtolower(explode(':', $request->getHost())[0]);
        $school = null;

        /*
         * The platform's own domain is not a school. `schoolpilot.org.ng`
         * used to be read as the school "schoolpilot" and refused with a 404
         * before the header below was looked at — which is every request the
         * mobile app makes, since it talks to the apex and names its school in
         * X-School-Subdomain. No school can sign up as "www", so www is the
         * apex too.
         */
        $base = strtolower((string) config('services.marketing.tenant_domain', ''));
        $isPlatformHost = $base !== '' && ($host === $base || $host === 'www.'.$base);

        if (! $isPlatformHost && str_contains($host, '.')) {
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
            $code = strtolower(trim((string) $request->header('X-School-Subdomain')));
            $school = $code === '' ? null : School::where('subdomain', $code)->first();

            // A named school that does not exist is an error, as an unknown
            // subdomain is: the app checks a typed school code this way, and
            // answering for "no school" would accept any typo.
            // The operator console's own name is not a school code: with no
            // platform row it simply runs unscoped, as it always has.
            if ($code !== '' && ! $school && $code !== strtolower((string) config('schoolpilot.platform_subdomain', 'admin'))) {
                return response()->json(['message' => 'School tenant subdomain not found.'], 404);
            }

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
