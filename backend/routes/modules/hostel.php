<?php

/*
 * Hostel management (TASK 17). Required from routes/api.php inside the
 * authenticated /api/v1 group. All paths are covered by the school's hostel
 * module switch (SchoolFeatures::MODULE_PATHS). Hostel-level scope — a
 * warden's authority is their own hostel — is checked in the controllers.
 */

use App\Http\Controllers\Api\V1\HostelController;
use App\Http\Controllers\Api\V1\HostelOpsController;
use App\Http\Controllers\Api\V1\HostelPortalController;
use Illuminate\Support\Facades\Route;

// Owner-level configuration.
Route::middleware('role:school_admin')->group(function () {
    Route::put('/hostel/settings', [HostelController::class, 'updateSettings']);
    Route::post('/hostel/hostels', [HostelController::class, 'storeHostel']);
    Route::put('/hostel/hostels/{id}', [HostelController::class, 'updateHostel'])->whereNumber('id');
    Route::post('/hostel/staff', [HostelController::class, 'assignStaff']);
    Route::post('/hostel/staff/{id}/end', [HostelController::class, 'endStaff'])->whereNumber('id');
    Route::post('/hostel/fee-rules', [HostelController::class, 'storeFeeRule']);
    Route::post('/hostel/bill', [HostelController::class, 'bill']);
});

// Finance sees charges; payments themselves stay in Finance.
Route::middleware('role:school_admin,bursar')->group(function () {
    Route::get('/hostel/fee-rules', [HostelController::class, 'feeRules']);
    Route::get('/hostel/charges', [HostelController::class, 'charges']);
});

// Hostel staff (teachers or nurses with a hostel assignment) and admins.
Route::middleware('role:school_admin,teacher,nurse')->group(function () {
    Route::get('/hostel/dashboard', [HostelController::class, 'dashboard']);
    Route::get('/hostel/settings', [HostelController::class, 'settings']);
    Route::get('/hostel/hostels', [HostelController::class, 'hostelsList']);
    Route::get('/hostel/structure', [HostelController::class, 'structure']);
    Route::post('/hostel/hostels/{id}/rooms', [HostelController::class, 'storeRoom'])->whereNumber('id');
    Route::put('/hostel/rooms/{id}', [HostelController::class, 'updateRoom'])->whereNumber('id');
    Route::post('/hostel/beds/{id}/status', [HostelController::class, 'bedStatus'])->whereNumber('id');
    Route::get('/hostel/staff', [HostelController::class, 'staff']);

    Route::get('/hostel/enrollments', [HostelController::class, 'enrollments']);
    Route::post('/hostel/enrollments', [HostelController::class, 'storeEnrollment']);
    Route::post('/hostel/enrollments/{id}/decision', [HostelController::class, 'decideEnrollment'])->whereNumber('id');
    Route::get('/hostel/residents', [HostelController::class, 'residents']);
    Route::post('/hostel/allocations', [HostelController::class, 'allocate']);
    Route::post('/hostel/allocations/{id}/move', [HostelController::class, 'move'])->whereNumber('id');
    Route::post('/hostel/allocations/{id}/end', [HostelController::class, 'endAllocation'])->whereNumber('id');
    Route::get('/hostel/students/{id}', [HostelController::class, 'studentProfile'])->whereNumber('id');
    Route::get('/hostel/arrivals', [HostelController::class, 'arrivals']);
    Route::post('/hostel/check-in', [HostelController::class, 'checkIn']);
    Route::post('/hostel/check-out', [HostelController::class, 'checkOut']);
    Route::post('/hostel/announcements', [HostelController::class, 'announce'])->middleware('throttle:20,1');
    Route::get('/hostel/reports/{kind}', [HostelController::class, 'report'])->middleware('throttle:20,1');

    Route::get('/hostel/rollcall-sessions', [HostelOpsController::class, 'sessions']);
    Route::post('/hostel/rollcall-sessions', [HostelOpsController::class, 'storeSession']);
    Route::post('/hostel/rollcalls/open', [HostelOpsController::class, 'open']);
    Route::get('/hostel/rollcalls/{id}', [HostelOpsController::class, 'show'])->whereNumber('id');
    Route::put('/hostel/rollcalls/{id}/marks', [HostelOpsController::class, 'mark'])->whereNumber('id');
    Route::post('/hostel/rollcalls/{id}/submit', [HostelOpsController::class, 'submit'])->whereNumber('id');

    Route::get('/hostel/leave', [HostelOpsController::class, 'leave']);
    Route::post('/hostel/leave', [HostelOpsController::class, 'storeLeave']);
    Route::post('/hostel/leave/{id}/consent', [HostelOpsController::class, 'recordConsent'])->whereNumber('id');
    Route::post('/hostel/leave/{id}/decision', [HostelOpsController::class, 'decideLeave'])->whereNumber('id');
    Route::post('/hostel/leave/{id}/depart', [HostelOpsController::class, 'depart'])->whereNumber('id');
    Route::post('/hostel/leave/{id}/return', [HostelOpsController::class, 'returned'])->whereNumber('id');
    Route::get('/hostel/movements', [HostelOpsController::class, 'movements']);
    Route::post('/hostel/movements/{id}/correct', [HostelOpsController::class, 'correctMovement'])->whereNumber('id');

    Route::get('/hostel/welfare', [HostelOpsController::class, 'welfare']);
    Route::post('/hostel/welfare', [HostelOpsController::class, 'storeWelfare']);
    Route::put('/hostel/welfare/{id}', [HostelOpsController::class, 'updateWelfare'])->whereNumber('id');
    Route::get('/hostel/incidents', [HostelOpsController::class, 'incidents']);
    Route::post('/hostel/incidents', [HostelOpsController::class, 'storeIncident']);
    Route::get('/hostel/incidents/{id}', [HostelOpsController::class, 'showIncident'])->whereNumber('id');
    Route::put('/hostel/incidents/{id}', [HostelOpsController::class, 'updateIncident'])->whereNumber('id');

    Route::get('/hostel/roster', [HostelOpsController::class, 'roster']);
    Route::post('/hostel/roster', [HostelOpsController::class, 'storeShift']);
    Route::post('/hostel/roster/publish', [HostelOpsController::class, 'publishRoster']);

    Route::get('/hostel/maintenance', [HostelOpsController::class, 'maintenance']);
    Route::post('/hostel/maintenance', [HostelOpsController::class, 'storeMaintenance']);
    Route::put('/hostel/maintenance/{id}', [HostelOpsController::class, 'updateMaintenance'])->whereNumber('id');
    Route::post('/hostel/maintenance/{id}/verify', [HostelOpsController::class, 'verifyMaintenance'])->whereNumber('id');
    Route::post('/hostel/rooms/{id}/inspections', [HostelOpsController::class, 'inspect'])->whereNumber('id');
    Route::get('/hostel/inspections', [HostelOpsController::class, 'inspections']);

    Route::get('/hostel/assets', [HostelOpsController::class, 'assets']);
    Route::post('/hostel/assets', [HostelOpsController::class, 'storeAsset']);
    Route::post('/hostel/assets/{id}/issue', [HostelOpsController::class, 'issue'])->whereNumber('id');
    Route::post('/hostel/asset-issues/{id}/return', [HostelOpsController::class, 'returnIssue'])->whereNumber('id');
});

Route::middleware('role:parent')->group(function () {
    Route::get('/parent/hostel', [HostelPortalController::class, 'parent']);
    Route::post('/parent/hostel/leave', [HostelPortalController::class, 'parentLeave']);
    Route::post('/parent/hostel/leave/{id}/consent', [HostelPortalController::class, 'parentConsent'])->whereNumber('id');
});

Route::middleware('role:student')->group(function () {
    Route::get('/me/hostel', [HostelPortalController::class, 'student']);
    Route::post('/me/hostel/leave', [HostelPortalController::class, 'studentLeave']);
    Route::post('/me/hostel/maintenance', [HostelPortalController::class, 'studentMaintenance']);
});
