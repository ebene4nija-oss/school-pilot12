<?php

/*
 * ID card production, issuance, renewal and digital cards (TASK 18).
 * Templates and print runs keep their routes in routes/api.php.
 */

use App\Http\Controllers\Api\V1\IdCardLifecycleController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:super_admin,school_admin')->group(function () {
    Route::get('/id-cards/settings', [IdCardLifecycleController::class, 'settings']);
    Route::put('/id-cards/settings', [IdCardLifecycleController::class, 'updateSettings']);
    Route::get('/id-cards/dashboard', [IdCardLifecycleController::class, 'dashboard']);
    Route::get('/id-cards/cards', [IdCardLifecycleController::class, 'cards']);
    Route::get('/id-cards/{id}/events', [IdCardLifecycleController::class, 'events'])->whereNumber('id');
    Route::post('/id-cards/print-runs/{id}/confirm-printed', [IdCardLifecycleController::class, 'confirmPrinted'])->whereNumber('id');
    Route::post('/id-cards/hand-over', [IdCardLifecycleController::class, 'handOver']);
    Route::get('/id-cards/renewals', [IdCardLifecycleController::class, 'renewals']);
    Route::post('/id-cards/renew', [IdCardLifecycleController::class, 'renew']);
    Route::get('/id-cards/verification-log', [IdCardLifecycleController::class, 'verificationLog']);
});

// Checking a card by its number: any staff member at a gate or desk.
Route::post('/id-cards/lookup', [IdCardLifecycleController::class, 'lookup'])->middleware(['role:super_admin,school_admin,teacher,nurse,bursar', 'throttle:30,1']);

Route::get('/me/id-card', [IdCardLifecycleController::class, 'mine'])->middleware('role:student,teacher,nurse,bursar,school_admin');
Route::get('/parent/id-cards', [IdCardLifecycleController::class, 'children'])->middleware('role:parent');
