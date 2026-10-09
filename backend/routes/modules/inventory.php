<?php

/*
 * School store / inventory: uniforms, books and consumables handed to
 * students, each hand-over confirmed received by a parent. Every path starts
 * with inventory/ or parent/inventory so the operator's module switch covers
 * it; the school's own on/off is checked in InventoryController.
 */

use App\Http\Controllers\Api\V1\InventoryController;
use Illuminate\Support\Facades\Route;

Route::put('/inventory/settings', [InventoryController::class, 'updateSettings'])->middleware('role:super_admin,school_admin');

// The store desk: admins and the bursar.
Route::middleware('role:super_admin,school_admin,bursar')->group(function () {
    Route::get('/inventory/settings', [InventoryController::class, 'settings']);
    Route::get('/inventory/summary', [InventoryController::class, 'summary']);

    Route::get('/inventory/items', [InventoryController::class, 'items']);
    Route::post('/inventory/items', [InventoryController::class, 'storeItem']);
    Route::put('/inventory/items/{id}', [InventoryController::class, 'updateItem'])->whereNumber('id');
    Route::post('/inventory/items/{id}/stock', [InventoryController::class, 'adjust'])->whereNumber('id');
    Route::get('/inventory/items/{id}/movements', [InventoryController::class, 'movements'])->whereNumber('id');

    Route::get('/inventory/packs', [InventoryController::class, 'packs']);
    Route::post('/inventory/packs', [InventoryController::class, 'storePack']);
    Route::put('/inventory/packs/{id}', [InventoryController::class, 'updatePack'])->whereNumber('id');

    Route::get('/inventory/issues', [InventoryController::class, 'issues']);
    Route::post('/inventory/issues', [InventoryController::class, 'issue']);
    Route::post('/inventory/issues/{id}/resolve', [InventoryController::class, 'resolve'])->whereNumber('id');
    Route::post('/inventory/charges/post', [InventoryController::class, 'postCharges']);
});

// Parents confirm what their child received.
Route::middleware('role:parent')->group(function () {
    Route::get('/parent/inventory', [InventoryController::class, 'parent']);
    Route::post('/parent/inventory/issues/{id}/confirm', [InventoryController::class, 'confirm'])->whereNumber('id');
});
