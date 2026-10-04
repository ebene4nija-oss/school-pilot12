<?php

/*
 * Venues on the timetable (TASK 07): admins keep the list of rooms, labs and
 * halls; a teacher sets where their own lesson is held.
 */

use App\Http\Controllers\Api\V1\VenueController;
use Illuminate\Support\Facades\Route;

Route::get('/venues', [VenueController::class, 'index'])->middleware('role:super_admin,school_admin,teacher');
Route::middleware('role:super_admin,school_admin')->group(function () {
    Route::post('/venues', [VenueController::class, 'store']);
    Route::put('/venues/{id}', [VenueController::class, 'update'])->whereNumber('id');
});

Route::middleware('role:super_admin,school_admin,teacher')->group(function () {
    Route::get('/timetable/entries/{entryId}/free-venues', [VenueController::class, 'free'])->whereNumber('entryId');
    Route::put('/timetable/entries/{entryId}/venue', [VenueController::class, 'setForEntry'])->whereNumber('entryId');
    Route::post('/timetable/entries/{entryId}/venue-changes', [VenueController::class, 'moveForDay'])->whereNumber('entryId');
});
