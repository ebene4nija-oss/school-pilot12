<?php

/*
 * The student's own portal (TASK 02, 06, 10). Required from routes/api.php
 * inside the authenticated /api/v1 group. Students only, and only their own
 * record: every query starts from the signed-in user's student row.
 */

use App\Http\Controllers\Api\V1\StudentPortalController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\CoverController;
use App\Http\Controllers\Api\V1\TutorAdminController;

Route::middleware('role:student')->group(function () {
    Route::get('/me/dashboard', [StudentPortalController::class, 'dashboard']);
    Route::get('/me/timetable', [StudentPortalController::class, 'timetableWeek']);
    Route::get('/me/subjects', [StudentPortalController::class, 'subjects']);
    Route::get('/me/subjects/{id}', [StudentPortalController::class, 'subject'])->whereNumber('id');
    Route::get('/me/results', [StudentPortalController::class, 'results']);
    Route::get('/me/profile', [StudentPortalController::class, 'profile']);
    Route::get('/me/tests', [StudentPortalController::class, 'tests']);
});

/*
 * Timetable cover for absent teachers (TASK 07). Admins arrange cover;
 * a teacher sees the cover they have been asked to take.
 */
Route::middleware('role:school_admin')->group(function () {
    Route::get('/timetable/cover', [CoverController::class, 'sheet']);
    Route::post('/timetable/absences', [CoverController::class, 'recordAbsence']);
    Route::delete('/timetable/absences/{id}', [CoverController::class, 'removeAbsence'])->whereNumber('id');
    Route::post('/timetable/substitutions', [CoverController::class, 'assign']);
    Route::post('/timetable/substitutions/{id}/cancel', [CoverController::class, 'cancel'])->whereNumber('id');
});
Route::get('/timetable/my-cover', [CoverController::class, 'mine'])->middleware('role:teacher,school_admin');

// The school's control over the student AI tutor (TASK 15).
Route::middleware('role:school_admin')->group(function () {
    Route::get('/ai/tutor/settings', [TutorAdminController::class, 'settings']);
    Route::put('/ai/tutor/settings', [TutorAdminController::class, 'update']);
    Route::get('/ai/tutor/usage', [TutorAdminController::class, 'usage']);
});
