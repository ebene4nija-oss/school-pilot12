<?php

/*
 * CBT oversight: approval, scheduling checks, live monitor, interventions,
 * analysis, question versions and regrades. Under the `cbt` prefix, so the
 * school's CBT module switch closes these too. Required from routes/api.php
 * inside the authenticated /api/v1 group.
 */

use App\Http\Controllers\Api\V1\CbtOversightController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:school_admin,teacher')->group(function () {
    Route::post('/cbt/exams/{id}/submit-for-approval', [CbtOversightController::class, 'submitForApproval'])->whereNumber('id');
    Route::post('/cbt/exams/{id}/approval', [CbtOversightController::class, 'decide'])->whereNumber('id');
    Route::get('/cbt/exams/{id}/checks', [CbtOversightController::class, 'checks'])->whereNumber('id');

    // Polled by the live monitor (real-time push is deferred, §16).
    Route::get('/cbt/exams/{id}/monitor', [CbtOversightController::class, 'monitor'])->whereNumber('id');
    Route::post('/cbt/attempts/{id}/extend', [CbtOversightController::class, 'extendAttempt'])->whereNumber('id');
    Route::post('/cbt/attempts/{id}/force-submit', [CbtOversightController::class, 'forceSubmit'])->whereNumber('id');

    Route::get('/cbt/exams/{id}/analysis', [CbtOversightController::class, 'analysis'])->whereNumber('id');
    Route::get('/cbt/questions/{id}/versions', [CbtOversightController::class, 'versions'])->whereNumber('id');
    Route::get('/cbt/attempts/{id}/revisions', [CbtOversightController::class, 'revisions'])->whereNumber('id');

    Route::post('/cbt/exams/{id}/regrade/preview', [CbtOversightController::class, 'regradePreview'])->whereNumber('id');
    Route::post('/cbt/exams/{id}/regrade', [CbtOversightController::class, 'regrade'])->whereNumber('id');
    Route::get('/cbt/exams/{id}/regrades', [CbtOversightController::class, 'regradeJobs'])->whereNumber('id');
    Route::post('/cbt/regrades/{id}/rollback', [CbtOversightController::class, 'rollback'])->whereNumber('id');
});

Route::middleware('role:school_admin')->group(function () {
    Route::get('/cbt/settings', [CbtOversightController::class, 'settings']);
    Route::put('/cbt/settings', [CbtOversightController::class, 'updateSettings']);
    Route::post('/cbt/exams/{id}/extend', [CbtOversightController::class, 'extendExam'])->whereNumber('id');
    Route::post('/cbt/attempts/{id}/reset', [CbtOversightController::class, 'reset'])->whereNumber('id');
});
