<?php

/*
 * The student's own portal (TASK 02, 06, 10). Required from routes/api.php
 * inside the authenticated /api/v1 group. Students only, and only their own
 * record: every query starts from the signed-in user's student row.
 */

use App\Http\Controllers\Api\V1\StudentPortalController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:student')->group(function () {
    Route::get('/me/dashboard', [StudentPortalController::class, 'dashboard']);
    Route::get('/me/timetable', [StudentPortalController::class, 'timetableWeek']);
    Route::get('/me/subjects', [StudentPortalController::class, 'subjects']);
    Route::get('/me/subjects/{id}', [StudentPortalController::class, 'subject'])->whereNumber('id');
    Route::get('/me/results', [StudentPortalController::class, 'results']);
});
