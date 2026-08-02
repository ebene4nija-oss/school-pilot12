<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Middleware\TenantResolutionMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Version 1 (/api/v1/...)
|--------------------------------------------------------------------------
*/

Route::middleware([TenantResolutionMiddleware::class, 'throttle:60,1'])->group(function () {
    
    // Auth endpoints
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Authenticated Protected Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/invite', [AuthController::class, 'inviteUser']);
        
        Route::get('/user', function (\Illuminate\Http\Request $request) {
            return response()->json($request->user()->load('userProfile'));
        });
    });

    // Health check
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'version' => '1.0.0',
            'timestamp' => now()->toIso8601String()
        ]);
    });
});
