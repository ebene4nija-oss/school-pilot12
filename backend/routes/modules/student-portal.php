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

/*
 * Timetable cover for absent teachers (TASK 07). Admins arrange cover;
 * a teacher sees the cover they have been asked to take.
 */
Route::middleware('role:school_admin')->group(function () {
    Route::get('/timetable/cover', [\App\Http\Controllers\Api\V1\CoverController::class, 'sheet']);
    Route::post('/timetable/absences', [\App\Http\Controllers\Api\V1\CoverController::class, 'recordAbsence']);
    Route::delete('/timetable/absences/{id}', [\App\Http\Controllers\Api\V1\CoverController::class, 'removeAbsence'])->whereNumber('id');
    Route::post('/timetable/substitutions', [\App\Http\Controllers\Api\V1\CoverController::class, 'assign']);
    Route::post('/timetable/substitutions/{id}/cancel', [\App\Http\Controllers\Api\V1\CoverController::class, 'cancel'])->whereNumber('id');
});
Route::get('/timetable/my-cover', [\App\Http\Controllers\Api\V1\CoverController::class, 'mine'])->middleware('role:teacher,school_admin');
