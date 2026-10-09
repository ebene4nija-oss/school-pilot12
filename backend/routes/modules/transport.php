<?php

/*
 * School bus management: fleet, riders and the bus fee, maintenance, papers.
 * Every staff path starts with transport/ so the school's Transport module
 * switch closes it; the parent view checks the switch in the controller.
 * (The original bus list, add-bus and GPS ping stay in routes/api.php.)
 */

use App\Http\Controllers\Api\V1\TransportController;
use Illuminate\Support\Facades\Route;

// The transport office: admins run it, the bursar bills and reads it.
Route::middleware('role:super_admin,school_admin,bursar')->group(function () {
    Route::get('/transport/settings', [TransportController::class, 'settings']);
    Route::get('/transport/fleet', [TransportController::class, 'fleet']);
    Route::get('/transport/riders', [TransportController::class, 'riders']);
    Route::get('/transport/charges', [TransportController::class, 'charges']);
    Route::post('/transport/bill', [TransportController::class, 'bill']);
    Route::get('/transport/documents', [TransportController::class, 'documents']);
    Route::get('/transport/buses/{id}/logs', [TransportController::class, 'logs'])->whereNumber('id');
});

Route::middleware('role:super_admin,school_admin')->group(function () {
    Route::put('/transport/settings', [TransportController::class, 'updateSettings']);
    Route::put('/transport/buses/{id}', [TransportController::class, 'updateBus'])->whereNumber('id');

    Route::post('/transport/riders', [TransportController::class, 'addRider']);
    Route::put('/transport/riders/{id}', [TransportController::class, 'updateRider'])->whereNumber('id');
    Route::post('/transport/riders/{id}/end', [TransportController::class, 'endRider'])->whereNumber('id');

    Route::post('/transport/buses/{id}/logs', [TransportController::class, 'addLog'])->whereNumber('id');
    Route::post('/transport/buses/{id}/back-in-service', [TransportController::class, 'backInService'])->whereNumber('id');
    Route::delete('/transport/logs/{logId}', [TransportController::class, 'deleteLog'])->whereNumber('logId');

    Route::post('/transport/buses/{id}/documents', [TransportController::class, 'addDocument'])->whereNumber('id');
    Route::put('/transport/documents/{docId}', [TransportController::class, 'updateDocument'])->whereNumber('docId');
    Route::delete('/transport/documents/{docId}', [TransportController::class, 'deleteDocument'])->whereNumber('docId');
});

Route::get('/parent/children/{studentId}/transport', [TransportController::class, 'child'])->whereNumber('studentId')->middleware('role:parent');
