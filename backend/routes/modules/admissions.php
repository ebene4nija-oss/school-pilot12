<?php

/*
 * Admissions. Required from routes/api.php inside the authenticated /api/v1
 * group. Section heads (teachers holding a head position) reach the same
 * routes; AdmissionController limits them to their own sections.
 */

use App\Http\Controllers\Api\V1\AdmissionController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:school_admin,teacher')->group(function () {
    Route::get('/admissions', [AdmissionController::class, 'index']);
    Route::post('/admissions', [AdmissionController::class, 'store']);
    Route::get('/admissions/{id}', [AdmissionController::class, 'show'])->whereNumber('id');
    Route::put('/admissions/{id}', [AdmissionController::class, 'update'])->whereNumber('id');
    Route::post('/admissions/{id}/review', [AdmissionController::class, 'review'])->whereNumber('id');
    Route::post('/admissions/{id}/documents', [AdmissionController::class, 'document'])->whereNumber('id');
    Route::post('/admissions/{id}/assessments', [AdmissionController::class, 'assessment'])->whereNumber('id');
    Route::put('/admission-assessments/{id}', [AdmissionController::class, 'recordAssessment'])->whereNumber('id');
    Route::post('/admissions/{id}/decision', [AdmissionController::class, 'decide'])->whereNumber('id');
    Route::post('/admissions/{id}/response', [AdmissionController::class, 'respond'])->whereNumber('id');
    Route::post('/admissions/{id}/enrol', [AdmissionController::class, 'enrol'])->whereNumber('id');
    Route::post('/admissions/{id}/withdraw', [AdmissionController::class, 'withdraw'])->whereNumber('id');
    Route::get('/admissions/{id}/offer-letter', [AdmissionController::class, 'offerLetter'])->whereNumber('id');
});

Route::middleware('role:school_admin')->group(function () {
    Route::get('/admissions/settings', [AdmissionController::class, 'settings']);
    Route::put('/admissions/settings', [AdmissionController::class, 'updateSettings']);
});
