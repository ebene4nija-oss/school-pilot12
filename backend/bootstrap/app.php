<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RequireRole::class,
            'require.2fa' => \App\Http\Middleware\RequireTwoFactorEnrolment::class,
        ]);

        // There is no web login page to redirect to; an unauthenticated API
        // call is a 401, not a redirect to a route that does not exist.
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : '/');

        // Every uploaded file is looked inside before a controller keeps it.
        $middleware->api(append: [\App\Http\Middleware\ScanUploads::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Installed mobile apps and the web proxy parse error bodies, so an API
        // path always answers in JSON, even when the client forgot the
        // `Accept: application/json` header (otherwise: an HTML error page).
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $e) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
