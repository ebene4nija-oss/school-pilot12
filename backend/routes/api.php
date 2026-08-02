<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Middleware\TenantResolutionMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Version 1 (/api/v1/...)
|--------------------------------------------------------------------------
*/

Route::middleware([TenantResolutionMiddleware::class, 'throttle:60,1'])->group(function () {
    
    // Auth endpoints
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Authenticated Protected Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/invite', [AuthController::class, 'inviteUser'])->middleware('role:super_admin,school_admin');
        
        // Student Information System (SIS) Routes
        Route::get('/students', [\App\Http\Controllers\Api\V1\StudentController::class, 'index'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/students', [\App\Http\Controllers\Api\V1\StudentController::class, 'store'])->middleware('role:super_admin,school_admin');
        Route::post('/students/promote', [\App\Http\Controllers\Api\V1\StudentController::class, 'promote'])->middleware('role:super_admin,school_admin');
        Route::post('/students/import', [\App\Http\Controllers\Api\V1\StudentController::class, 'bulkImport'])->middleware('role:super_admin,school_admin');

        // Attendance & Hardware-Free Clock-In
        Route::post('/attendance/qr-token', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'generateQrToken'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/attendance/mark', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'markStudentAttendance'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/attendance/staff-gps', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'staffGpsClockIn'])->middleware('role:super_admin,school_admin,teacher');

        // Timetable Solver Engine
        Route::post('/timetable/generate', [\App\Http\Controllers\Api\V1\TimetableController::class, 'generate'])->middleware('role:super_admin,school_admin');

        // Assessment & Broadsheet Routes
        Route::post('/assessment/score', [\App\Http\Controllers\Api\V1\AssessmentController::class, 'enterScores'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/assessment/score/{id}/ai-comment', [\App\Http\Controllers\Api\V1\AssessmentController::class, 'generateAiComment'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/assessment/score/{id}/review-comment', [\App\Http\Controllers\Api\V1\AssessmentController::class, 'reviewAiComment'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/assessment/broadsheet', [\App\Http\Controllers\Api\V1\AssessmentController::class, 'getBroadsheet'])->middleware('role:super_admin,school_admin,teacher');

        // Fees & Finance Routes
        Route::post('/finance/fee-structure', [\App\Http\Controllers\Api\V1\FinanceController::class, 'storeFeeStructure'])->middleware('role:super_admin,school_admin');
        Route::post('/finance/payments', [\App\Http\Controllers\Api\V1\FinanceController::class, 'recordPayment'])->middleware('role:super_admin,school_admin,parent,student');
        Route::post('/finance/payments/reconcile-bank-transfer', [\App\Http\Controllers\Api\V1\FinanceController::class, 'reconcileBankTransfer'])->middleware('role:super_admin,school_admin');
        Route::get('/finance/invoices/{id}/pdf', [\App\Http\Controllers\Api\V1\FinanceController::class, 'downloadInvoicePdf']);
        Route::get('/finance/payments/{id}/receipt', [\App\Http\Controllers\Api\V1\FinanceController::class, 'downloadPaymentReceipt']);

        // Advanced School Accounting Routes (Beyond Fee Collection)
        Route::post('/accounting/payroll', [\App\Http\Controllers\Api\V1\AccountingController::class, 'processPayroll'])->middleware('role:super_admin,school_admin');
        Route::post('/accounting/vendors', [\App\Http\Controllers\Api\V1\AccountingController::class, 'storeVendor'])->middleware('role:super_admin,school_admin');
        Route::post('/accounting/expenses', [\App\Http\Controllers\Api\V1\AccountingController::class, 'recordExpense'])->middleware('role:super_admin,school_admin');
        Route::post('/accounting/petty-cash', [\App\Http\Controllers\Api\V1\AccountingController::class, 'logPettyCash'])->middleware('role:super_admin,school_admin');
        Route::post('/accounting/budget', [\App\Http\Controllers\Api\V1\AccountingController::class, 'storeBudget'])->middleware('role:super_admin,school_admin');
        Route::get('/accounting/income-report', [\App\Http\Controllers\Api\V1\AccountingController::class, 'getIncomeReport'])->middleware('role:super_admin,school_admin');

        // Advanced Parent Engagement Portal Routes
        Route::get('/parent/feed/{studentId}', [\App\Http\Controllers\Api\V1\ParentPortalController::class, 'getStudentFeed'])->middleware('role:super_admin,school_admin,parent');
        Route::post('/parent/pickup-authorization', [\App\Http\Controllers\Api\V1\ParentPortalController::class, 'storePickupAuthorization'])->middleware('role:super_admin,school_admin,parent');
        Route::post('/academics/homework', [\App\Http\Controllers\Api\V1\ParentPortalController::class, 'storeHomework'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/students/behavior-report', [\App\Http\Controllers\Api\V1\ParentPortalController::class, 'storeBehaviorReport'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/calendar/events', [\App\Http\Controllers\Api\V1\ParentPortalController::class, 'storeCalendarEvent'])->middleware('role:super_admin,school_admin');

        // Teacher Productivity Routes
        Route::post('/teacher/bulk-grading', [\App\Http\Controllers\Api\V1\TeacherProductivityController::class, 'bulkGrading'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/teacher/import-scores', [\App\Http\Controllers\Api\V1\TeacherProductivityController::class, 'importScoresCsv'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/teacher/export-scores', [\App\Http\Controllers\Api\V1\TeacherProductivityController::class, 'exportScoresCsv'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/teacher/copy-lesson-plans', [\App\Http\Controllers\Api\V1\TeacherProductivityController::class, 'copyPreviousTermLessonPlans'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/teacher/duplicate-exam', [\App\Http\Controllers\Api\V1\TeacherProductivityController::class, 'duplicateExam'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/teacher/comment-bank', [\App\Http\Controllers\Api\V1\TeacherProductivityController::class, 'getCommentBank'])->middleware('role:super_admin,school_admin,teacher');

        // Advanced Role-Based Analytics Dashboard Routes
        Route::get('/analytics/principal', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'getPrincipalDashboard'])->middleware('role:super_admin,school_admin');
        Route::get('/analytics/teacher', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'getTeacherDashboard'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/analytics/parent/{studentId}', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'getParentDashboard'])->middleware('role:super_admin,school_admin,parent');
        Route::get('/analytics/student', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'getStudentDashboard'])->middleware('role:super_admin,school_admin,student');

        // AI Studio & Optional Module Routes
        Route::post('/ai/settings', [\App\Http\Controllers\Api\V1\AiStudioController::class, 'updateAiSettings'])->middleware('role:super_admin,school_admin');
        Route::post('/ai/lesson-plan', [\App\Http\Controllers\Api\V1\AiStudioController::class, 'generateLessonPlan'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/ai/exam-generation', [\App\Http\Controllers\Api\V1\AiStudioController::class, 'generateExams'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/ai/homework-ideas', [\App\Http\Controllers\Api\V1\AiStudioController::class, 'generateHomework'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/ai/translate', [\App\Http\Controllers\Api\V1\AiStudioController::class, 'translate'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/ai/performance-summary/{studentId}', [\App\Http\Controllers\Api\V1\AiStudioController::class, 'summarizePerformance'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/ai/tutor-chat', [\App\Http\Controllers\Api\V1\AiStudioController::class, 'tutorChat']);

        // NDPA Parental Consent & Data Export Routes
        Route::post('/compliance/parental-consent', [\App\Http\Controllers\Api\V1\AddedFeaturesController::class, 'recordParentalConsent'])->middleware('role:super_admin,school_admin,parent');
        Route::post('/compliance/parental-consent/{id}/withdraw', [\App\Http\Controllers\Api\V1\AddedFeaturesController::class, 'withdrawParentalConsent'])->middleware('role:super_admin,school_admin,parent');
        Route::post('/admin/export-data', [\App\Http\Controllers\Api\V1\AddedFeaturesController::class, 'exportSchoolData'])->middleware('role:super_admin,school_admin');

        // Teacher-Parent In-App Messaging Routes
        Route::get('/messages/threads', [\App\Http\Controllers\Api\V1\AddedFeaturesController::class, 'getThreads']);
        Route::post('/messages/send', [\App\Http\Controllers\Api\V1\AddedFeaturesController::class, 'sendMessage']);

        // Phase 1 & Phase 2 Module Routes
        Route::get('/gamification/profile/{studentId?}', [\App\Http\Controllers\Api\V1\GamificationController::class, 'getProfile']);
        Route::post('/gamification/activity', [\App\Http\Controllers\Api\V1\GamificationController::class, 'recordActivity']);
        Route::get('/gamification/leaderboard', [\App\Http\Controllers\Api\V1\GamificationController::class, 'getLeaderboard']);

        Route::post('/cbt/offline-sync', [\App\Http\Controllers\Api\V1\CbtController::class, 'syncOfflineAnswers'])->middleware('role:super_admin,school_admin,teacher,student');
        Route::post('/finance/scholarships', [\App\Http\Controllers\Api\V1\Phase1And2Controller::class, 'storeScholarship'])->middleware('role:super_admin,school_admin');
        Route::post('/transport/bus/{busId}/gps-ping', [\App\Http\Controllers\Api\V1\Phase1And2Controller::class, 'updateBusLocation'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/library/scan-barcode', [\App\Http\Controllers\Api\V1\Phase1And2Controller::class, 'scanBookBarcode'])->middleware('role:super_admin,school_admin,teacher,student');
        Route::post('/hostel/assign-bed', [\App\Http\Controllers\Api\V1\Phase1And2Controller::class, 'assignBed'])->middleware('role:super_admin,school_admin');
        Route::post('/health/clinic-visit', [\App\Http\Controllers\Api\V1\Phase1And2Controller::class, 'recordClinicVisit'])->middleware('role:super_admin,school_admin,teacher');

        Route::get('/user', function (\Illuminate\Http\Request $request) {
            return response()->json($request->user()->load('userProfile'));
        });
    });

    // Public unauthenticated result verification route with anti-scraping rate limiting
    Route::middleware('throttle:10,1')->get('/verify-result/{token}', [\App\Http\Controllers\Api\V1\AssessmentController::class, 'verifyResult']);

    // OpenAPI / Swagger Documentation Spec
    Route::get('/docs/openapi.json', [\App\Http\Controllers\Api\V1\SwaggerDocController::class, 'getSpec']);

    // Health check
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'version' => '1.0.0',
            'timestamp' => now()->toIso8601String()
        ]);
    });

    // Unauthenticated Webhooks
    Route::post('/webhooks/{gateway}', [\App\Http\Controllers\Api\V1\FinanceController::class, 'handleWebhook']);
});
