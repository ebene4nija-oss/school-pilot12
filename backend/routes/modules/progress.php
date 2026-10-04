<?php

/*
 * Student progress and analysis. Required from routes/api.php inside the
 * authenticated /api/v1 group. Per-student and per-subject scope is checked
 * in ProgressController; parents have their own results views elsewhere.
 */

use App\Http\Controllers\Api\V1\ProgressController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:school_admin,teacher')->group(function () {
    Route::get('/students/{id}/progress', [ProgressController::class, 'show'])->whereNumber('id');
    Route::get('/students/{id}/progress/subjects/{subjectId}', [ProgressController::class, 'subject'])->whereNumber(['id', 'subjectId']);
    Route::get('/students/{id}/progress/topics', [ProgressController::class, 'topics'])->whereNumber('id');
    Route::get('/students/{id}/progress/assessments', [ProgressController::class, 'assessments'])->whereNumber('id');
    Route::get('/students/{id}/progress/attendance', [ProgressController::class, 'attendance'])->whereNumber('id');
    Route::get('/students/{id}/progress/report', [ProgressController::class, 'report'])->whereNumber('id')->middleware('throttle:20,1');

    Route::post('/students/{id}/remarks', [ProgressController::class, 'storeRemark'])->whereNumber('id');
    Route::put('/student-remarks/{id}', [ProgressController::class, 'updateRemark'])->whereNumber('id');
    Route::delete('/student-remarks/{id}', [ProgressController::class, 'deleteRemark'])->whereNumber('id');

    Route::post('/students/{id}/interventions', [ProgressController::class, 'storeIntervention'])->whereNumber('id');
    Route::patch('/interventions/{id}', [ProgressController::class, 'updateIntervention'])->whereNumber('id');
    Route::post('/interventions/{id}/reviews', [ProgressController::class, 'reviewIntervention'])->whereNumber('id');
    Route::get('/interventions', [ProgressController::class, 'interventions']);

    Route::get('/classes/{id}/analysis', [ProgressController::class, 'classAnalysis'])->whereNumber('id');
    Route::get('/progress/settings', [ProgressController::class, 'settings']);
});

Route::middleware('role:school_admin')->group(function () {
    Route::get('/analytics/school-progress', [ProgressController::class, 'schoolAnalytics']);
    Route::put('/progress/settings', [ProgressController::class, 'updateSettings']);
});

Route::middleware('role:student')->group(function () {
    Route::get('/me/progress', [ProgressController::class, 'mine']);
});
