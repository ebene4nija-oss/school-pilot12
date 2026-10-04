<?php

/*
 * Virtual classes and learning materials as one LMS (TASK 14). Required from
 * routes/api.php inside the authenticated /api/v1 group. Virtual-class paths
 * sit under /live-classes and so follow the school's live_classes module
 * switch; materials, lesson plans and assignments are core.
 */

use App\Http\Controllers\Api\V1\HomeworkSubmissionController;
use App\Http\Controllers\Api\V1\LearningMaterialController;
use App\Http\Controllers\Api\V1\LessonPlanController;
use App\Http\Controllers\Api\V1\LmsDashboardController;
use App\Http\Controllers\Api\V1\ParentPortalController;
use App\Http\Controllers\Api\V1\VirtualClassroomController;
use Illuminate\Support\Facades\Route;

Route::get('/lms/settings', [LearningMaterialController::class, 'settings'])->middleware('role:school_admin,teacher');
Route::put('/lms/settings', [LearningMaterialController::class, 'updateSettings'])->middleware('role:school_admin');

// The library: everyone reads what is shared with them; staff publish.
Route::get('/learning-materials', [LearningMaterialController::class, 'index'])->middleware('role:school_admin,teacher,student,parent');
Route::get('/learning-materials/{id}/download', [LearningMaterialController::class, 'download'])->whereNumber('id')->middleware(['role:school_admin,teacher,student,parent', 'throttle:120,1']);
Route::middleware('role:school_admin,teacher')->group(function () {
    Route::post('/learning-materials', [LearningMaterialController::class, 'store'])->middleware('throttle:30,1');
    Route::put('/learning-materials/{id}', [LearningMaterialController::class, 'update'])->whereNumber('id');
    Route::post('/learning-materials/{id}/review', [LearningMaterialController::class, 'review'])->whereNumber('id');
    Route::post('/learning-materials/{id}/archive', [LearningMaterialController::class, 'archive'])->whereNumber('id');
    Route::get('/learning-materials/{id}/reach', [LearningMaterialController::class, 'reach'])->whereNumber('id');

    Route::get('/lesson-plans', [LessonPlanController::class, 'index']);
    Route::post('/lesson-plans', [LessonPlanController::class, 'store']);
    Route::get('/lesson-plans/{id}', [LessonPlanController::class, 'show'])->whereNumber('id');
    Route::put('/lesson-plans/{id}', [LessonPlanController::class, 'update'])->whereNumber('id');
    Route::post('/lesson-plans/{id}/submit', [LessonPlanController::class, 'submit'])->whereNumber('id');
    Route::post('/lesson-plans/{id}/review', [LessonPlanController::class, 'review'])->whereNumber('id');
    Route::post('/lesson-plans/{id}/copy', [LessonPlanController::class, 'copy'])->whereNumber('id');

    Route::post('/academics/homework/{homeworkId}/publish', [ParentPortalController::class, 'publishHomework'])->whereNumber('homeworkId');
    Route::post('/academics/submissions/{submissionId}/return', [HomeworkSubmissionController::class, 'returnForCorrection'])->whereNumber('submissionId');

    Route::get('/live-classes/{id}/attendance', [VirtualClassroomController::class, 'attendance'])->whereNumber('id');
    Route::put('/live-classes/{id}/attendance/{studentId}', [VirtualClassroomController::class, 'correct'])->whereNumber('id')->whereNumber('studentId');
    Route::post('/live-classes/{id}/cancel', [VirtualClassroomController::class, 'cancel'])->whereNumber('id');

    Route::get('/lms/overview', [LmsDashboardController::class, 'overview']);
});

Route::get('/academics/submission-files/{fileId}', [HomeworkSubmissionController::class, 'file'])->whereNumber('fileId')->middleware('role:school_admin,teacher,student,parent');

Route::middleware('role:student')->group(function () {
    Route::get('/me/learning', [LmsDashboardController::class, 'student']);
    Route::post('/live-classes/{id}/join', [VirtualClassroomController::class, 'join'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/live-classes/{id}/heartbeat', [VirtualClassroomController::class, 'heartbeat'])->whereNumber('id')->middleware('throttle:120,1');
});

Route::middleware('role:parent')->group(function () {
    Route::get('/parent/learning', [LmsDashboardController::class, 'parent']);
    Route::post('/parent/homework/{homeworkId}/submit', [HomeworkSubmissionController::class, 'storeForChild'])->whereNumber('homeworkId')->middleware('throttle:20,1');
});
