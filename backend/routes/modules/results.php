<?php

/*
 * Sections, scheme versions and the broadsheet.
 * Required from routes/api.php inside the authenticated /api/v1 group.
 */

use App\Http\Controllers\Api\V1\BroadsheetController;
use App\Http\Controllers\Api\V1\DevelopmentalController;
use App\Http\Controllers\Api\V1\SchemeVersionController;
use App\Http\Controllers\Api\V1\SectionsController;
use Illuminate\Support\Facades\Route;

// Sections: heads, chains, workflow switch (decisions D2, D3). Owner-level.
Route::middleware('role:school_admin')->group(function () {
    Route::put('/sections/settings', [SectionsController::class, 'update']);
    Route::post('/sections/heads', [SectionsController::class, 'assignHead']);
    Route::post('/sections/heads/{id}/end', [SectionsController::class, 'endHead'])->whereNumber('id');
    Route::put('/broadsheets/config', [BroadsheetController::class, 'updateConfig']);
    Route::put('/developmental/indicators', [DevelopmentalController::class, 'saveIndicators']);
});

Route::middleware('role:school_admin,teacher')->group(function () {
    Route::get('/sections/settings', [SectionsController::class, 'show']);
    Route::get('/sections/resolve', [SectionsController::class, 'resolve']);

    // Assessment scheme versions (decision D1). Who may propose or approve is
    // decided in the controller: heads propose, the owner approves.
    Route::get('/assessment/scheme-versions', [SchemeVersionController::class, 'index']);
    Route::get('/assessment/scheme-versions/for-term', [SchemeVersionController::class, 'forTerm']);
    Route::post('/assessment/scheme-versions', [SchemeVersionController::class, 'store']);
    Route::post('/assessment/scheme-versions/{id}/propose', [SchemeVersionController::class, 'propose'])->whereNumber('id');
    Route::post('/assessment/scheme-versions/{id}/approve', [SchemeVersionController::class, 'approve'])->whereNumber('id');
    Route::post('/assessment/scheme-versions/{id}/reject', [SchemeVersionController::class, 'reject'])->whereNumber('id');

    // The broadsheet. Per-class and per-subject access is checked inside.
    Route::get('/broadsheets/dashboard', [BroadsheetController::class, 'dashboard']);
    Route::get('/broadsheets/master', [BroadsheetController::class, 'master']);
    Route::get('/broadsheets/class', [BroadsheetController::class, 'showClass']);
    Route::get('/broadsheets/subject', [BroadsheetController::class, 'showSubject']);
    Route::get('/broadsheets/analytics', [BroadsheetController::class, 'analytics']);
    Route::get('/broadsheets/annual', [BroadsheetController::class, 'annual']);
    Route::get('/broadsheets/promotion', [BroadsheetController::class, 'promotion']);
    Route::get('/broadsheets/config', [BroadsheetController::class, 'config']);
    Route::get('/broadsheets/{id}/validation', [BroadsheetController::class, 'validation'])->whereNumber('id');
    Route::get('/broadsheets/{id}/history', [BroadsheetController::class, 'history'])->whereNumber('id');
    Route::get('/broadsheets/{id}/versions/{version}', [BroadsheetController::class, 'version'])->whereNumber(['id', 'version']);
    Route::get('/broadsheets/{id}/corrections', [BroadsheetController::class, 'corrections'])->whereNumber('id');
    // Exports are logged and rate-limited: a broadsheet is a whole class's results.
    Route::get('/broadsheets/{id}/export', [BroadsheetController::class, 'export'])->whereNumber('id')->middleware('throttle:20,1');

    Route::post('/broadsheets/{id}/subjects/{subjectId}/submit', [BroadsheetController::class, 'submitSubject'])->whereNumber(['id', 'subjectId']);
    Route::post('/broadsheets/{id}/approve', [BroadsheetController::class, 'approve'])->whereNumber('id');
    Route::post('/broadsheets/{id}/return', [BroadsheetController::class, 'returnItems'])->whereNumber('id');
    Route::post('/broadsheets/{id}/reject', [BroadsheetController::class, 'reject'])->whereNumber('id');
    Route::post('/broadsheets/{id}/overrides', [BroadsheetController::class, 'override'])->whereNumber('id');
    Route::post('/broadsheets/{id}/publish', [BroadsheetController::class, 'publish'])->whereNumber('id');
    Route::post('/broadsheets/{id}/corrections', [BroadsheetController::class, 'requestCorrection'])->whereNumber('id');
    Route::post('/broadsheet-corrections/{id}/decide', [BroadsheetController::class, 'decideCorrection'])->whereNumber('id');

    // Nursery developmental ratings.
    Route::get('/developmental/indicators', [DevelopmentalController::class, 'indicators']);
    Route::get('/developmental/sheet', [DevelopmentalController::class, 'sheet']);
    Route::put('/developmental/ratings', [DevelopmentalController::class, 'saveRatings']);
});

// A CA filled from homework and quizzes; the subject teacher adjusts and applies (TASK 08, 21).
Route::middleware('role:school_admin,teacher')->group(function () {
    Route::get('/assessment/coursework-ca/sources', [\App\Http\Controllers\Api\V1\CourseworkCaController::class, 'sources']);
    Route::post('/assessment/coursework-ca/suggest', [\App\Http\Controllers\Api\V1\CourseworkCaController::class, 'suggest']);
    Route::post('/assessment/coursework-ca/apply', [\App\Http\Controllers\Api\V1\CourseworkCaController::class, 'apply']);
});
