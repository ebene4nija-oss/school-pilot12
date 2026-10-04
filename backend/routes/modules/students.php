<?php

/*
 * The View Students page. Required from routes/api.php inside the
 * authenticated /api/v1 group. Scope and field permissions are enforced in
 * StudentDirectoryService for every route here.
 */

use App\Http\Controllers\Api\V1\StudentDirectoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:school_admin,teacher,bursar')->group(function () {
    Route::get('/student-directory', [StudentDirectoryController::class, 'index']);
    Route::get('/student-directory/sessions', [StudentDirectoryController::class, 'sessions']);
    Route::get('/student-directory/{id}/quick-view', [StudentDirectoryController::class, 'quickView'])->whereNumber('id');
    // Exports are a whole class's data: separate permission, logged, rate-limited.
    Route::get('/student-directory/export', [StudentDirectoryController::class, 'export'])->middleware('throttle:10,1');
    Route::get('/student-directory/print', [StudentDirectoryController::class, 'printList'])->middleware('throttle:20,1');
    Route::get('/student-directory/views', [StudentDirectoryController::class, 'savedViews']);
    Route::post('/student-directory/views', [StudentDirectoryController::class, 'saveView']);
    Route::delete('/student-directory/views/{id}', [StudentDirectoryController::class, 'deleteView'])->whereNumber('id');
});

Route::middleware('role:school_admin')->group(function () {
    Route::get('/student-directory/{id}/changes', [StudentDirectoryController::class, 'changes'])->whereNumber('id');
    Route::post('/student-directory/{id}/status', [StudentDirectoryController::class, 'changeStatus'])->whereNumber('id');
    Route::post('/student-directory/{id}/move', [StudentDirectoryController::class, 'moveClass'])->whereNumber('id');
    Route::post('/student-directory/bulk/preview', [StudentDirectoryController::class, 'bulkPreview']);
    Route::post('/student-directory/bulk', [StudentDirectoryController::class, 'bulk']);
    Route::post('/student-directory/duplicate-check', [StudentDirectoryController::class, 'duplicateCheck']);
    Route::get('/student-directory/settings', [StudentDirectoryController::class, 'settings']);
    Route::put('/student-directory/settings', [StudentDirectoryController::class, 'updateSettings']);
});
