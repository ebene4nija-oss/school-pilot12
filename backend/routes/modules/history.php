<?php

/*
 * Academic history and transfers.
 * Required from routes/api.php inside the authenticated /api/v1 group.
 */

use App\Http\Controllers\Api\V1\AcademicHistoryController;
use App\Http\Controllers\Api\V1\TransferController;
use Illuminate\Support\Facades\Route;

// Registrar work: the school admin.
Route::middleware('role:school_admin')->group(function () {
    Route::post('/academic-history/close-term', [AcademicHistoryController::class, 'closeTerm']);
    Route::get('/academic-history/closures', [AcademicHistoryController::class, 'closures']);
    Route::post('/academic-history/snapshots/{id}/verify', [AcademicHistoryController::class, 'verifySnapshot'])->whereNumber('id');
    Route::get('/academic-history/import/template', [AcademicHistoryController::class, 'importTemplate']);
    Route::post('/academic-history/import/preview', [AcademicHistoryController::class, 'importPreview']);
    Route::get('/academic-history/import/{id}/errors', [AcademicHistoryController::class, 'importErrors'])->whereNumber('id');
    Route::post('/academic-history/import/{id}/commit', [AcademicHistoryController::class, 'importCommit'])->whereNumber('id');
    Route::get('/academic-history/correction-requests', [AcademicHistoryController::class, 'correctionRequests']);
    Route::post('/academic-history/correction-requests/{id}/resolve', [AcademicHistoryController::class, 'resolveCorrectionRequest'])->whereNumber('id');
    Route::get('/academic-history/mappings', [AcademicHistoryController::class, 'mappings']);
    Route::put('/academic-history/mappings', [AcademicHistoryController::class, 'saveMapping']);
    Route::post('/students/{id}/documents', [AcademicHistoryController::class, 'issueDocument'])->whereNumber('id');
    Route::post('/academic-documents/{id}/revoke', [AcademicHistoryController::class, 'revokeDocument'])->whereNumber('id');

    Route::get('/transfers', [TransferController::class, 'index']);
    Route::get('/transfers/settings', [TransferController::class, 'settings']);
    Route::put('/transfers/settings', [TransferController::class, 'updateSettings']);
    Route::get('/transfers/{id}', [TransferController::class, 'show'])->whereNumber('id');
    Route::post('/transfers/outgoing', [TransferController::class, 'startOutgoing']);
    Route::post('/transfers/{id}/submit', [TransferController::class, 'submit'])->whereNumber('id');
    Route::post('/transfers/{id}/clearance', [TransferController::class, 'clearance'])->whereNumber('id');
    Route::post('/transfers/{id}/approve', [TransferController::class, 'approve'])->whereNumber('id');
    Route::post('/transfers/{id}/reject', [TransferController::class, 'reject'])->whereNumber('id');
    Route::post('/transfers/{id}/cancel', [TransferController::class, 'cancel'])->whereNumber('id');
    Route::post('/transfers/{id}/code', [TransferController::class, 'issueCode'])->whereNumber('id');
    Route::post('/transfers/{id}/push', [TransferController::class, 'push'])->whereNumber('id');
    Route::post('/transfers/{id}/secure-link', [TransferController::class, 'secureLink'])->whereNumber('id');
    Route::post('/transfers/{id}/package', [TransferController::class, 'preparePackage'])->whereNumber('id');
    Route::get('/transfers/{id}/package', [TransferController::class, 'downloadPackage'])->whereNumber('id');
    Route::post('/transfers/{id}/complete', [TransferController::class, 'complete'])->whereNumber('id');
    Route::post('/transfers/{id}/take-up', [TransferController::class, 'takeUp'])->whereNumber('id');

    Route::post('/transfers/incoming', [TransferController::class, 'startIncoming']);
    // Codes and record requests guess at another school's students if left
    // unthrottled; the neutral replies are only half the defence.
    Route::post('/transfers/incoming/redeem', [TransferController::class, 'redeem'])->middleware('throttle:10,1');
    Route::post('/transfers/incoming/request', [TransferController::class, 'requestRecords'])->middleware('throttle:10,1');
    Route::post('/transfers/{id}/accept', [TransferController::class, 'acceptPushed'])->whereNumber('id');
    Route::post('/transfers/{id}/records', [TransferController::class, 'addRecords'])->whereNumber('id');
    Route::get('/transfers/{id}/duplicates', [TransferController::class, 'duplicates'])->whereNumber('id');
    Route::post('/transfers/{id}/admit', [TransferController::class, 'admit'])->whereNumber('id');
    Route::post('/previous-records/{id}/verify', [TransferController::class, 'verifyRecord'])->whereNumber('id');
    Route::post('/previous-records/{id}/amend', [TransferController::class, 'amendRecord'])->whereNumber('id');
});

// Staff reading the record: admins see everyone, teachers only students they teach.
Route::middleware('role:school_admin,teacher')->group(function () {
    Route::get('/students/{id}/academic-history', [AcademicHistoryController::class, 'show'])->whereNumber('id');
    Route::get('/academic-history/snapshots/{id}/versions', [AcademicHistoryController::class, 'snapshotVersions'])->whereNumber('id');
    Route::get('/students/{id}/documents', [AcademicHistoryController::class, 'documents'])->whereNumber('id');
    Route::get('/academic-documents/{id}/pdf', [AcademicHistoryController::class, 'documentPdf'])->whereNumber('id');
});

Route::middleware('role:student')->group(function () {
    Route::get('/me/academic-history', [AcademicHistoryController::class, 'mine']);
});

Route::middleware('role:student,parent')->group(function () {
    Route::post('/students/{id}/record-errors', [AcademicHistoryController::class, 'reportError'])->whereNumber('id')->middleware('throttle:10,1');
});

Route::middleware('role:parent')->group(function () {
    Route::get('/parent/children/{id}/academic-history', [AcademicHistoryController::class, 'forChild'])->whereNumber('id');
    Route::get('/parent/academic-documents/{id}/pdf', [AcademicHistoryController::class, 'childDocumentPdf'])->whereNumber('id');
    Route::get('/parent/transfers', [TransferController::class, 'parentIndex']);
    Route::post('/parent/children/{id}/transfer-request', [TransferController::class, 'parentRequest'])->whereNumber('id');
    Route::post('/parent/transfers/{id}/consent', [TransferController::class, 'parentConsent'])->whereNumber('id');
});
