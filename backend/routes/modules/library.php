<?php

/*
 * Library circulation (doc §9.2): lending, returns, renewals, reservations.
 * The desk is school admins and teachers, as for the catalogue. Every path
 * starts with library/ so the school's Library module switch covers it.
 */

use App\Http\Controllers\Api\V1\LibraryController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:super_admin,school_admin,teacher')->group(function () {
    Route::get('/library/loans', [LibraryController::class, 'loans']);
    Route::post('/library/loans', [LibraryController::class, 'lend']);
    Route::post('/library/loans/{id}/return', [LibraryController::class, 'giveBack'])->whereNumber('id');
    Route::post('/library/returns', [LibraryController::class, 'giveBackByBarcode']);
    Route::get('/library/reservations', [LibraryController::class, 'reservations']);
    Route::get('/library/borrowers', [LibraryController::class, 'borrowers']);
    Route::put('/library/books/{id}', [LibraryController::class, 'updateBook'])->whereNumber('id');
    Route::get('/library/settings', [LibraryController::class, 'settings']);
});

Route::put('/library/settings', [LibraryController::class, 'updateSettings'])->middleware('role:super_admin,school_admin');

// Readers: students and staff.
Route::middleware('role:super_admin,school_admin,teacher,student,nurse,bursar')->group(function () {
    Route::get('/library/me', [LibraryController::class, 'mine']);
    Route::post('/library/loans/{id}/renew', [LibraryController::class, 'renew'])->whereNumber('id');
    Route::post('/library/reservations', [LibraryController::class, 'reserve']);
    Route::delete('/library/reservations/{id}', [LibraryController::class, 'cancelReservation'])->whereNumber('id');
});

Route::get('/library/children/{studentId}', [LibraryController::class, 'child'])->whereNumber('studentId')->middleware('role:parent');
