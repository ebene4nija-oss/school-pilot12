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
        
        // Student Information System (SIS) Routes
        Route::get('/students', [\App\Http\Controllers\Api\V1\StudentController::class, 'index']);
        Route::post('/students', [\App\Http\Controllers\Api\V1\StudentController::class, 'store']);
        Route::post('/students/promote', [\App\Http\Controllers\Api\V1\StudentController::class, 'promote']);
        Route::post('/students/import', [\App\Http\Controllers\Api\V1\StudentController::class, 'bulkImport']);

        // Attendance & Hardware-Free Clock-In
        Route::post('/attendance/qr-token', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'generateQrToken']);
        Route::post('/attendance/mark', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'markStudentAttendance']);
        Route::post('/attendance/staff-gps', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'staffGpsClockIn']);

        // Timetable Solver Engine
        Route::post('/timetable/generate', [\App\Http\Controllers\Api\V1\TimetableController::class, 'generate']);

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
