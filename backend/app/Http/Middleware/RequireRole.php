<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $userRole = $user->userProfile ? $user->userProfile->role : null;

        if (!$userRole || !in_array($userRole, $roles)) {
            return response()->json([
                'message' => 'Forbidden: You do not have permission to access this resource.'
            ], 403);
        }

        return $next($request);
    }
}
