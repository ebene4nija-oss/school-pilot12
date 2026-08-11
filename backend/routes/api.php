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

    // Auth endpoints — login carries its own stricter limiter (5/min per
    // email+IP, see AppServiceProvider) so the shared 60/min bucket cannot be
    // used to brute-force credentials.
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    /*
     * Self-service password recovery (gap G2).
     *
     * Unauthenticated by necessity — the whole point is that the caller cannot
     * sign in. Both carry their own limiter: `forgot-password` because each
     * accepted call can cost the school an SMS, and `reset-password` because
     * without one the token is brute-forceable at 60 guesses a minute.
     */
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:password-reset');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset');

    // Authenticated Protected Routes
    //
    // BindTenantFromUser runs after authentication because the tenant
    // middleware executes before it and can only see the host — which is a
    // bare `localhost` on any deployment not using subdomains.
    Route::middleware(['auth:sanctum', \App\Http\Middleware\BindTenantFromUser::class, 'require.2fa'])->group(function () {
        Route::post('/auth/invite', [AuthController::class, 'inviteUser'])->middleware('role:super_admin,school_admin');
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        /*
         * Two-factor enrolment.
         *
         * Login has verified TOTP codes for a while, but nothing could set a
         * secret — there was no enrolment route in the whole application, so an
         * admin could not turn 2FA on even if they wanted to.
         *
         * These sit outside `require.2fa` on purpose: when 2FA is mandatory,
         * an admin who has not yet enrolled is blocked from everything else,
         * and has to be able to reach exactly these routes to comply.
         */
        Route::get('/auth/2fa', [\App\Http\Controllers\Api\V1\TwoFactorController::class, 'status']);
        Route::post('/auth/2fa/setup', [\App\Http\Controllers\Api\V1\TwoFactorController::class, 'setup']);
        Route::post('/auth/2fa/confirm', [\App\Http\Controllers\Api\V1\TwoFactorController::class, 'confirm']);
        Route::post('/auth/2fa/recovery-codes', [\App\Http\Controllers\Api\V1\TwoFactorController::class, 'regenerateRecoveryCodes']);
        Route::delete('/auth/2fa', [\App\Http\Controllers\Api\V1\TwoFactorController::class, 'disable']);
        
        // Student Information System (SIS) Routes
        Route::get('/students', [\App\Http\Controllers\Api\V1\StudentController::class, 'index'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/students', [\App\Http\Controllers\Api\V1\StudentController::class, 'store'])->middleware('role:super_admin,school_admin');
        Route::post('/students/promote', [\App\Http\Controllers\Api\V1\StudentController::class, 'promote'])->middleware('role:super_admin,school_admin');
        Route::get('/students/{student}/class-history', [\App\Http\Controllers\Api\V1\StudentController::class, 'classHistory'])->middleware('role:super_admin,school_admin,teacher');

        /*
         * Health data is a separate, narrower route from the student record.
         * It used to ride along on every roster listing, which is open to
         * every teacher in the school. Admins and guardians only, policy-checked
         * per child, and every read writes an audit row (NDPA, doc §12).
         */
        Route::get('/students/{id}/medical', [\App\Http\Controllers\Api\V1\StudentController::class, 'medical'])
            ->middleware('role:super_admin,school_admin,parent');

        // `show()` existed on the controller but had no route — a single
        // student's record was not fetchable at all.
        Route::get('/students/{id}', [\App\Http\Controllers\Api\V1\StudentController::class, 'show'])
            ->middleware('role:super_admin,school_admin,teacher,parent,student');
        Route::post('/students/import', [\App\Http\Controllers\Api\V1\StudentController::class, 'bulkImport'])->middleware('role:super_admin,school_admin');

        // Attendance & Hardware-Free Clock-In
        Route::post('/attendance/qr-token', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'generateQrToken'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/attendance/mark', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'markStudentAttendance'])->middleware('role:super_admin,school_admin,teacher');
        // Roll call: one request for the whole register instead of one per
        // child. Idempotent, so a retry on a dropped connection is safe.
        Route::post('/attendance/bulk', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'markBulkAttendance'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/attendance/register', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'classRegister'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/attendance/staff-gps', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'staffGpsClockIn'])->middleware('role:super_admin,school_admin,teacher');

        // Timetable Solver Engine & Management
        Route::post('/timetable/generate', [\App\Http\Controllers\Api\V1\TimetableController::class, 'generate'])->middleware('role:super_admin,school_admin');
        Route::get('/timetable/versions', [\App\Http\Controllers\Api\V1\TimetableController::class, 'versions'])->middleware('role:super_admin,school_admin');
        Route::post('/timetable/versions/{id}/publish', [\App\Http\Controllers\Api\V1\TimetableController::class, 'publish'])->middleware('role:super_admin,school_admin');
        Route::get('/timetable/view', [\App\Http\Controllers\Api\V1\TimetableController::class, 'view']);

        // Assessment & Broadsheet Routes
        Route::post('/assessment/score', [\App\Http\Controllers\Api\V1\AssessmentController::class, 'enterScores'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/assessment/score/{id}/ai-comment', [\App\Http\Controllers\Api\V1\AssessmentController::class, 'generateAiComment'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/assessment/score/{id}/review-comment', [\App\Http\Controllers\Api\V1\AssessmentController::class, 'reviewAiComment'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/assessment/broadsheet', [\App\Http\Controllers\Api\V1\AssessmentController::class, 'getBroadsheet'])->middleware('role:super_admin,school_admin,teacher');

        // Configurable CA weighting (§7.7) — schools each split CA vs exam
        // differently, so this cannot be hardcoded.
        Route::get('/assessment/ca-schemes', [\App\Http\Controllers\Api\V1\AssessmentController::class, 'listCaSchemes'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/assessment/ca-schemes', [\App\Http\Controllers\Api\V1\AssessmentController::class, 'storeCaScheme'])->middleware('role:super_admin,school_admin');
        Route::post('/assessment/ca-schemes/{id}/activate', [\App\Http\Controllers\Api\V1\AssessmentController::class, 'activateCaScheme'])->middleware('role:super_admin,school_admin');

        /*
         * The fee-structure builder (§7.12).
         *
         * The POST path is unchanged from when this lived on FinanceController —
         * it is published under /api/v1 and mobile clients may already call it.
         * Only the handler moved.
         */
        Route::middleware('role:super_admin,school_admin')->group(function () {
            Route::get('/finance/fee-structure', [\App\Http\Controllers\Api\V1\FeeStructureController::class, 'index']);
            Route::post('/finance/fee-structure', [\App\Http\Controllers\Api\V1\FeeStructureController::class, 'store']);
            Route::put('/finance/fee-structure/{id}', [\App\Http\Controllers\Api\V1\FeeStructureController::class, 'update']);
            Route::delete('/finance/fee-structure/{id}', [\App\Http\Controllers\Api\V1\FeeStructureController::class, 'destroy']);

            // Fee structures -> actual bills. Nothing created an invoice before
            // this: fees were defined and no parent was ever charged.
            Route::post('/finance/invoices/generate', [\App\Http\Controllers\Api\V1\FeeStructureController::class, 'generateInvoices']);
        });

        /*
         * A school's own merchant account (gap G8).
         *
         * school_admin only, with no super_admin fallback, for the same reason
         * result release is: a SchoolPilot operator sells the school software.
         * They do not get to decide which bank account another organisation's
         * fee income lands in. Platform staff who need this changed ask the
         * school to change it.
         */
        Route::middleware('role:school_admin')->group(function () {
            Route::get('/finance/gateways', [\App\Http\Controllers\Api\V1\PaymentGatewayController::class, 'index']);
            Route::put('/finance/gateways/{gateway}', [\App\Http\Controllers\Api\V1\PaymentGatewayController::class, 'update']);
            Route::delete('/finance/gateways/{gateway}', [\App\Http\Controllers\Api\V1\PaymentGatewayController::class, 'destroy']);
        });

        // Fees & Finance Routes
        //
        // `initialize` opens checkout on the school's own gateway and returns a
        // hosted URL; `payments` records a manual or already-taken payment. The
        // client never holds a key and never mints a reference (G8, G9).
        Route::post('/finance/payments/initialize', [\App\Http\Controllers\Api\V1\FinanceController::class, 'initializePayment'])->middleware('role:super_admin,school_admin,parent,student');
        Route::post('/finance/payments', [\App\Http\Controllers\Api\V1\FinanceController::class, 'recordPayment'])->middleware('role:super_admin,school_admin,parent,student');
        Route::post('/finance/payments/reconcile-bank-transfer', [\App\Http\Controllers\Api\V1\FinanceController::class, 'reconcileBankTransfer'])->middleware('role:super_admin,school_admin');
        Route::get('/finance/invoices/{id}/pdf', [\App\Http\Controllers\Api\V1\FinanceController::class, 'downloadInvoicePdf']);
        Route::get('/finance/payments/{id}/receipt', [\App\Http\Controllers\Api\V1\FinanceController::class, 'downloadPaymentReceipt']);

        /*
         * Fee collection (§7.12): the defaulter dashboard, instalment plans and
         * discounts. The module could take money but could not report who had
         * not paid — the question a bursar asks weekly.
         */
        Route::middleware('role:super_admin,school_admin')->group(function () {
            Route::get('/finance/defaulters', [\App\Http\Controllers\Api\V1\FeeCollectionController::class, 'defaulters']);
            // Chasing them. The finance page's reminder button was wired to
            // nothing until this existed.
            Route::post('/finance/defaulters/remind', [\App\Http\Controllers\Api\V1\FeeCollectionController::class, 'remindDefaulters']);
            Route::post('/finance/invoices/{invoiceId}/installment-plan', [\App\Http\Controllers\Api\V1\FeeCollectionController::class, 'createInstallmentPlan']);
            Route::get('/finance/scholarships', [\App\Http\Controllers\Api\V1\FeeCollectionController::class, 'listScholarships']);
            Route::post('/finance/invoices/{invoiceId}/apply-discount', [\App\Http\Controllers\Api\V1\FeeCollectionController::class, 'applyDiscount']);
        });

        Route::get('/finance/invoices/{invoiceId}/installment-plan', [\App\Http\Controllers\Api\V1\FeeCollectionController::class, 'installmentPlan']);
        // Guardians read their own child's statement; StudentPolicy enforces which child.
        Route::get('/finance/students/{studentId}/statement', [\App\Http\Controllers\Api\V1\FeeCollectionController::class, 'studentStatement'])
            ->middleware('role:super_admin,school_admin,parent,student');

        /*
         * Staff / HR (§7.3) — the people-management half. Payroll already
         * existed; leave and employment history were JSON columns nothing read.
         */
        Route::middleware('role:super_admin,school_admin,teacher')->group(function () {
            Route::post('/hr/leave', [\App\Http\Controllers\Api\V1\StaffHrController::class, 'requestLeave']);
            Route::get('/hr/leave', [\App\Http\Controllers\Api\V1\StaffHrController::class, 'listLeave']);
            Route::post('/hr/leave/{id}/cancel', [\App\Http\Controllers\Api\V1\StaffHrController::class, 'cancelLeave']);
            Route::get('/hr/leave-calendar', [\App\Http\Controllers\Api\V1\StaffHrController::class, 'leaveCalendar']);
            Route::get('/hr/staff/{staffId}/employment-record', [\App\Http\Controllers\Api\V1\StaffHrController::class, 'employmentRecord']);
        });

        Route::middleware('role:super_admin,school_admin')->group(function () {
            Route::post('/hr/leave/{id}/decision', [\App\Http\Controllers\Api\V1\StaffHrController::class, 'decideLeave']);
            Route::put('/hr/staff/{staffId}/leave-allocation', [\App\Http\Controllers\Api\V1\StaffHrController::class, 'setLeaveAllocation']);
            Route::post('/hr/staff/{staffId}/employment-history', [\App\Http\Controllers\Api\V1\StaffHrController::class, 'addEmploymentHistory']);
        });

        // Advanced School Accounting Routes (Beyond Fee Collection)
        Route::post('/accounting/payroll', [\App\Http\Controllers\Api\V1\AccountingController::class, 'processPayroll'])->middleware('role:super_admin,school_admin');
        Route::post('/accounting/vendors', [\App\Http\Controllers\Api\V1\AccountingController::class, 'storeVendor'])->middleware('role:super_admin,school_admin');
        Route::post('/accounting/expenses', [\App\Http\Controllers\Api\V1\AccountingController::class, 'recordExpense'])->middleware('role:super_admin,school_admin');
        Route::post('/accounting/petty-cash', [\App\Http\Controllers\Api\V1\AccountingController::class, 'logPettyCash'])->middleware('role:super_admin,school_admin');
        Route::post('/accounting/budget', [\App\Http\Controllers\Api\V1\AccountingController::class, 'storeBudget'])->middleware('role:super_admin,school_admin');
        Route::get('/accounting/income-report', [\App\Http\Controllers\Api\V1\AccountingController::class, 'getIncomeReport'])->middleware('role:super_admin,school_admin');

        // Advanced Parent Engagement Portal Routes
        /*
         * The guardian's own children (gap G11) — and the first request a
         * signed-in parent can make. Every other route in this block takes a
         * `{studentId}` that had no discoverable source: `GET /students` is
         * staff-only and nothing read `student_guardian` from the guardian's
         * side. Scoped to the caller's own pivot rows inside the controller,
         * so the role list here is the outer fence, not the check.
         */
        Route::get('/parent/children', [\App\Http\Controllers\Api\V1\ParentPortalController::class, 'children'])
            ->middleware('role:super_admin,school_admin,parent');
        Route::get('/parent/feed/{studentId}', [\App\Http\Controllers\Api\V1\ParentPortalController::class, 'getStudentFeed'])->middleware('role:super_admin,school_admin,parent');
        Route::post('/parent/pickup-authorization', [\App\Http\Controllers\Api\V1\ParentPortalController::class, 'storePickupAuthorization'])->middleware('role:super_admin,school_admin,parent');
        // Full behaviour history, paginated. The feed carries only the ten most
        // recent so the parent home screen is not re-downloading nine years of
        // rows on every open.
        Route::get('/students/{studentId}/behavior-reports', [\App\Http\Controllers\Api\V1\ParentPortalController::class, 'behaviorHistory'])
            ->middleware('role:super_admin,school_admin,teacher,parent');
        Route::post('/academics/homework', [\App\Http\Controllers\Api\V1\ParentPortalController::class, 'storeHomework'])->middleware('role:super_admin,school_admin,teacher');

        /*
         * What homework exists (gap G5).
         *
         * The set half had a route and the discover half did not: a student
         * could submit to an assignment id but nothing told them one had been
         * set, while the parent feed showed a guardian work their own child
         * could not see. Scoped in the controller — a student to their class, a
         * guardian to a child the pivot says is theirs, a teacher to their own
         * assignments — so all three ask the same question and get the same
         * answer about what is overdue.
         */
        Route::get('/academics/homework', [\App\Http\Controllers\Api\V1\HomeworkSubmissionController::class, 'feed']);

        /*
         * Homework submissions — the return half, which did not exist. A
         * teacher could set work and never receive it back.
         */
        Route::middleware('role:student')->group(function () {
            Route::post('/academics/homework/{homeworkId}/submit', [\App\Http\Controllers\Api\V1\HomeworkSubmissionController::class, 'store']);
            Route::get('/academics/homework/{homeworkId}/my-submission', [\App\Http\Controllers\Api\V1\HomeworkSubmissionController::class, 'mine']);
        });

        Route::middleware('role:super_admin,school_admin,teacher')->group(function () {
            Route::get('/academics/homework/{homeworkId}/submissions', [\App\Http\Controllers\Api\V1\HomeworkSubmissionController::class, 'index']);
            Route::post('/academics/homework/{homeworkId}/bulk-grade', [\App\Http\Controllers\Api\V1\HomeworkSubmissionController::class, 'bulkGrade']);
            Route::post('/academics/submissions/{submissionId}/grade', [\App\Http\Controllers\Api\V1\HomeworkSubmissionController::class, 'grade']);
        });
        Route::post('/students/behavior-report', [\App\Http\Controllers\Api\V1\ParentPortalController::class, 'storeBehaviorReport'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/calendar/events', [\App\Http\Controllers\Api\V1\ParentPortalController::class, 'storeCalendarEvent'])->middleware('role:super_admin,school_admin');

        // User Management & Administration Routes (bulk/static BEFORE parameterized)
        Route::post('/users/bulk-status', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'bulkUpdateStatus'])->middleware('role:super_admin,school_admin');
        Route::post('/users/bulk-import', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'bulkImportUsers'])->middleware('role:super_admin,school_admin');
        Route::get('/users/export', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'exportUsers'])->middleware('role:super_admin,school_admin');
        Route::get('/users', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'listUsers'])->middleware('role:super_admin,school_admin');
        Route::get('/users/{id}', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'showUser'])->middleware('role:super_admin,school_admin');
        Route::post('/users/{id}/status', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'updateUserStatus'])->middleware('role:super_admin,school_admin');
        Route::put('/users/{id}/profile', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'updateProfile'])->middleware('role:super_admin,school_admin');
        Route::get('/users/{id}/profile-completion', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'getProfileCompletion'])->middleware('role:super_admin,school_admin');
        Route::get('/users/{id}/timeline', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'getUserTimeline'])->middleware('role:super_admin,school_admin');
        Route::get('/users/{id}/login-history', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'getLoginHistory'])->middleware('role:super_admin,school_admin');
        Route::post('/users/{id}/reset-password', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'resetPassword'])->middleware('role:super_admin,school_admin');
        Route::post('/users/{id}/unlock', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'unlockAccount'])->middleware('role:super_admin,school_admin');
        Route::post('/users/{id}/revoke-sessions', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'revokeAllSessions'])->middleware('role:super_admin,school_admin');
        Route::get('/users/{id}/security', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'getSecurityOverview'])->middleware('role:super_admin,school_admin');

        // Custom Roles Management
        Route::get('/roles', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'listRoles'])->middleware('role:super_admin,school_admin');
        Route::post('/roles', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'createRole'])->middleware('role:super_admin,school_admin');
        Route::put('/roles/{id}', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'updateRole'])->middleware('role:super_admin,school_admin');
        Route::delete('/roles/{id}', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'deleteRole'])->middleware('role:super_admin,school_admin');
        Route::post('/users/{userId}/roles', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'assignRole'])->middleware('role:super_admin,school_admin');
        Route::delete('/users/{userId}/roles/{roleId}', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'revokeRole'])->middleware('role:super_admin,school_admin');

        // Extended Profile Routes (Teacher, Parent, Student Portfolio)
        Route::get('/teachers/{userId}/profile', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'getTeacherProfile'])->middleware('role:super_admin,school_admin,teacher');
        Route::put('/teachers/{userId}/profile', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'updateTeacherProfile'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/parents/{userId}/profile', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'getParentProfile'])->middleware('role:super_admin,school_admin,parent');
        Route::put('/parents/{userId}/profile', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'updateParentProfile'])->middleware('role:super_admin,school_admin,parent');
        Route::get('/students/{studentId}/portfolio', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'getPortfolio'])->middleware('role:super_admin,school_admin,teacher,student,parent');
        Route::post('/students/{studentId}/portfolio', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'addPortfolioEntry'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/portfolio/{id}/verify', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'verifyPortfolioEntry'])->middleware('role:super_admin,school_admin');

        /*
         * Academic structure — the two pickers everything else depends on
         * (gap G12). Only `/classes/{id}/subjects` existed, which is useful
         * only once you already hold a class id, so a register, a score sheet,
         * a broadsheet or a result release had nowhere to learn "which class,
         * which term?".
         *
         * Open to every authenticated role. Both return reference data about
         * the school — names, ordering, dates, a head-count — and nothing that
         * touches a named child, so there is no NDPA §12 surface to gate. A
         * student needs the term list to read their own result just as much as
         * a teacher needs it to enter scores.
         */
        Route::get('/classes', [\App\Http\Controllers\Api\V1\AcademicStructureController::class, 'classes']);
        Route::get('/terms', [\App\Http\Controllers\Api\V1\AcademicStructureController::class, 'terms']);

        // Subject Management & Enrollment Routes
        Route::get('/subjects', [\App\Http\Controllers\Api\V1\SubjectManagementController::class, 'listSubjects']);
        Route::post('/subjects', [\App\Http\Controllers\Api\V1\SubjectManagementController::class, 'storeSubject'])->middleware('role:super_admin,school_admin');
        Route::post('/subjects/assign-teacher', [\App\Http\Controllers\Api\V1\SubjectManagementController::class, 'assignTeacher'])->middleware('role:super_admin,school_admin');
        Route::post('/subjects/remove-teacher', [\App\Http\Controllers\Api\V1\SubjectManagementController::class, 'removeTeacher'])->middleware('role:super_admin,school_admin');
        Route::get('/teachers/{teacherId}/subjects', [\App\Http\Controllers\Api\V1\SubjectManagementController::class, 'getTeacherSubjects'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/classes/subjects', [\App\Http\Controllers\Api\V1\SubjectManagementController::class, 'setClassSubjects'])->middleware('role:super_admin,school_admin');
        Route::get('/classes/{classId}/subjects', [\App\Http\Controllers\Api\V1\SubjectManagementController::class, 'getClassSubjects']);
        Route::post('/students/{studentId}/subjects', [\App\Http\Controllers\Api\V1\SubjectManagementController::class, 'enrollStudentSubjects'])->middleware('role:super_admin,school_admin,student');
        Route::get('/students/{studentId}/subjects', [\App\Http\Controllers\Api\V1\SubjectManagementController::class, 'getStudentSubjects']);
        Route::delete('/students/{studentId}/subjects/{subjectId}', [\App\Http\Controllers\Api\V1\SubjectManagementController::class, 'dropSubject'])->middleware('role:super_admin,school_admin,student');
        Route::post('/subjects/bulk-auto-enroll', [\App\Http\Controllers\Api\V1\SubjectManagementController::class, 'bulkAutoEnroll'])->middleware('role:super_admin,school_admin');

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
        // AI Learning Hub — student tutor (§7.11). Student-only: this used to
        // carry no role middleware at all, unlike every sibling AI route.
        Route::middleware('role:student')->group(function () {
            Route::post('/ai/tutor-chat', [\App\Http\Controllers\Api\V1\AiStudioController::class, 'tutorChat']);
            Route::get('/ai/tutor/conversations', [\App\Http\Controllers\Api\V1\AiStudioController::class, 'tutorConversations']);
            Route::get('/ai/tutor/conversations/{id}', [\App\Http\Controllers\Api\V1\AiStudioController::class, 'tutorConversation']);
            Route::get('/ai/tutor/mastery', [\App\Http\Controllers\Api\V1\AiStudioController::class, 'tutorMastery']);
        });

        // NDPA Parental Consent & Data Export Routes
        Route::post('/compliance/parental-consent', [\App\Http\Controllers\Api\V1\AddedFeaturesController::class, 'recordParentalConsent'])->middleware('role:super_admin,school_admin,parent');
        Route::post('/compliance/parental-consent/{id}/withdraw', [\App\Http\Controllers\Api\V1\AddedFeaturesController::class, 'withdrawParentalConsent'])->middleware('role:super_admin,school_admin,parent');
        Route::post('/admin/export-data', [\App\Http\Controllers\Api\V1\AddedFeaturesController::class, 'exportSchoolData'])->middleware('role:super_admin,school_admin');

        /*
         * Notifications (§7.13). NotificationController was the only controller
         * in the codebase with no routes at all, so push/SMS/WhatsApp were
         * unreachable except as inline calls buried in two other controllers.
         */
        Route::post('/notifications/devices', [\App\Http\Controllers\Api\V1\NotificationController::class, 'registerDevice']);
        Route::delete('/notifications/devices', [\App\Http\Controllers\Api\V1\NotificationController::class, 'unregisterDevice']);

        /*
         * Self-diagnostic: push to your own handset and get back a per-device
         * verdict. Open to every role because it can only ever reach the
         * caller's own devices. Throttled anyway — it is the one push route
         * that sends immediately rather than queueing.
         */
        Route::post('/notifications/devices/test', [\App\Http\Controllers\Api\V1\NotificationController::class, 'testDevice'])
            ->middleware('throttle:10,1');

        Route::middleware('role:super_admin,school_admin,teacher')->group(function () {
            Route::post('/notifications/broadcast', [\App\Http\Controllers\Api\V1\NotificationController::class, 'broadcast']);
            Route::post('/notifications/whatsapp', [\App\Http\Controllers\Api\V1\NotificationController::class, 'sendWhatsAppNotification']);
            Route::post('/notifications/whatsapp/structured', [\App\Http\Controllers\Api\V1\NotificationController::class, 'sendStructuredWhatsApp']);
        });

        Route::get('/notifications/history', [\App\Http\Controllers\Api\V1\NotificationController::class, 'history'])
            ->middleware('role:super_admin,school_admin');

        /*
         * The caller's own inbox (gap G6).
         *
         * `/notifications/history` above is the school's delivery ledger — every
         * message to every family, with the per-channel totals a bursar checks
         * an SMS bill against — and stays admin-only. This is the other half: a
         * parent who receives a push about a fee deadline and taps it away had
         * no way to find out what it said. Scoped by `user_id`, not by school,
         * so an admin calling it gets their own messages like anyone else.
         */
        Route::get('/me/notifications', [\App\Http\Controllers\Api\V1\NotificationController::class, 'inbox']);
        Route::post('/me/notifications/read', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markInboxRead']);

        /*
         * Queued work and its status. Report-card runs and broadcasts return a
         * batch id immediately; clients poll here rather than holding a request
         * open past a shared-hosting PHP timeout.
         */
        Route::get('/jobs/{batchId}', [\App\Http\Controllers\Api\V1\JobStatusController::class, 'show']);
        Route::middleware('role:super_admin,school_admin')->group(function () {
            Route::post('/jobs/{batchId}/cancel', [\App\Http\Controllers\Api\V1\JobStatusController::class, 'cancel']);
            Route::post('/report-cards/generate', [\App\Http\Controllers\Api\V1\JobStatusController::class, 'generateReportCards']);
        });

        // Teacher-Parent In-App Messaging Routes
        Route::get('/messages/threads', [\App\Http\Controllers\Api\V1\AddedFeaturesController::class, 'getThreads']);
        // Reading a thread marks the other side's messages seen. Participation
        // is checked in the controller — a thread id is guessable.
        Route::get('/messages/threads/{id}', [\App\Http\Controllers\Api\V1\AddedFeaturesController::class, 'showThread']);
        Route::post('/messages/send', [\App\Http\Controllers\Api\V1\AddedFeaturesController::class, 'sendMessage']);

        // Phase 1 & Phase 2 Module Routes
        Route::get('/gamification/profile/{studentId?}', [\App\Http\Controllers\Api\V1\GamificationController::class, 'getProfile']);
        // Point values are server-side (see ACTIVITY_POINTS); a student may only
        // record activity against themselves.
        Route::post('/gamification/activity', [\App\Http\Controllers\Api\V1\GamificationController::class, 'recordActivity'])
            ->middleware('role:super_admin,school_admin,teacher,student');
        Route::get('/gamification/leaderboard', [\App\Http\Controllers\Api\V1\GamificationController::class, 'getLeaderboard']);

        /*
        |------------------------------------------------------------------
        | CBT / Testing Engine (§7.8) and Question Bank (§7.9)
        |------------------------------------------------------------------
        | Split three ways: staff author papers, candidates sit them, staff
        | mark and analyse them. Object-level checks live in CbtExamPolicy and
        | CbtAttemptPolicy — the role middleware below only answers "is this a
        | teacher?", never "is this *that* candidate's paper?".
        */

        // Question bank — staff only.
        Route::middleware('role:super_admin,school_admin,teacher')->group(function () {
            // Image library. Questions reference assets by id, so an image is
            // uploaded once and reused across a whole bank.
            Route::get('/cbt/media', [\App\Http\Controllers\Api\V1\CbtController::class, 'listMedia']);
            Route::post('/cbt/media', [\App\Http\Controllers\Api\V1\CbtController::class, 'uploadMedia']);
            Route::delete('/cbt/media/{id}', [\App\Http\Controllers\Api\V1\CbtController::class, 'destroyMedia']);

            Route::get('/cbt/questions', [\App\Http\Controllers\Api\V1\CbtController::class, 'listQuestions']);
            Route::post('/cbt/questions', [\App\Http\Controllers\Api\V1\CbtController::class, 'storeQuestion']);
            Route::post('/cbt/questions/import', [\App\Http\Controllers\Api\V1\CbtController::class, 'importQuestions']);
            Route::put('/cbt/questions/{id}', [\App\Http\Controllers\Api\V1\CbtController::class, 'updateQuestion']);
            Route::delete('/cbt/questions/{id}', [\App\Http\Controllers\Api\V1\CbtController::class, 'destroyQuestion']);

            // Exam authoring.
            Route::get('/cbt/exams', [\App\Http\Controllers\Api\V1\CbtController::class, 'listExams']);
            Route::post('/cbt/exams', [\App\Http\Controllers\Api\V1\CbtController::class, 'storeExam']);
            Route::get('/cbt/exams/{id}', [\App\Http\Controllers\Api\V1\CbtController::class, 'showExam']);
            Route::put('/cbt/exams/{id}', [\App\Http\Controllers\Api\V1\CbtController::class, 'updateExam']);
            Route::post('/cbt/exams/{id}/questions', [\App\Http\Controllers\Api\V1\CbtController::class, 'attachQuestions']);
            Route::delete('/cbt/exams/{id}/questions/{questionId}', [\App\Http\Controllers\Api\V1\CbtController::class, 'detachQuestion']);
            Route::post('/cbt/exams/{id}/publish', [\App\Http\Controllers\Api\V1\CbtController::class, 'publishExam']);
            Route::post('/cbt/exams/{id}/close', [\App\Http\Controllers\Api\V1\CbtController::class, 'closeExam']);
            Route::get('/cbt/exams/{id}/results', [\App\Http\Controllers\Api\V1\CbtController::class, 'examResults']);
            Route::post('/cbt/attempts/{attemptId}/grade', [\App\Http\Controllers\Api\V1\CbtController::class, 'gradeAttempt']);
        });

        // Sitting a paper — candidates only. CbtAttemptPolicy::sit is what
        // actually binds an attempt to the student who owns it.
        Route::middleware('role:student')->group(function () {
            Route::get('/cbt/available', [\App\Http\Controllers\Api\V1\CbtController::class, 'availableExams']);
            Route::post('/cbt/exams/{examId}/start', [\App\Http\Controllers\Api\V1\CbtController::class, 'startAttempt']);
            Route::get('/cbt/attempts/{attemptId}', [\App\Http\Controllers\Api\V1\CbtController::class, 'showAttempt']);
            Route::post('/cbt/attempts/{attemptId}/answers', [\App\Http\Controllers\Api\V1\CbtController::class, 'saveAnswers']);
            Route::post('/cbt/attempts/{attemptId}/submit', [\App\Http\Controllers\Api\V1\CbtController::class, 'submitAttempt']);
            Route::post('/cbt/attempts/{attemptId}/events', [\App\Http\Controllers\Api\V1\CbtController::class, 'logAttemptEvent']);
        });

        // Results: candidate, guardian and staff paths all land here and are
        // separated by CbtAttemptPolicy::view plus the exam's release setting.
        Route::get('/cbt/attempts/{attemptId}/result', [\App\Http\Controllers\Api\V1\CbtController::class, 'attemptResult']);

        /*
         * What to cache before exam day (gap G4). Staff get the paper's shape
         * for provisioning a lab; a candidate gets a media manifest for their
         * own paper and nothing else — no question text, no options, no
         * answers. The controller splits on role, and CbtExamPolicy::sit binds
         * the candidate to the class the paper was set for.
         */
        Route::get('/cbt/exams/{examId}/offline-package', [\App\Http\Controllers\Api\V1\CbtController::class, 'offlinePackage'])
            ->middleware('role:super_admin,school_admin,teacher,student');

        Route::post('/cbt/offline-sync', [\App\Http\Controllers\Api\V1\CbtController::class, 'syncOfflineAnswers'])->middleware('role:student');

        /*
        |------------------------------------------------------------------
        | Report card design templates (per school, imported)
        |------------------------------------------------------------------
        */
        Route::get('/report-cards/template-contract', [\App\Http\Controllers\Api\V1\ReportCardTemplateController::class, 'contract'])->middleware('role:super_admin,school_admin');
        Route::get('/report-cards/templates', [\App\Http\Controllers\Api\V1\ReportCardTemplateController::class, 'index'])->middleware('role:super_admin,school_admin');
        Route::post('/report-cards/templates', [\App\Http\Controllers\Api\V1\ReportCardTemplateController::class, 'import'])->middleware('role:super_admin,school_admin');
        Route::get('/report-cards/templates/{id}', [\App\Http\Controllers\Api\V1\ReportCardTemplateController::class, 'show'])->middleware('role:super_admin,school_admin');
        Route::get('/report-cards/templates/{id}/preview', [\App\Http\Controllers\Api\V1\ReportCardTemplateController::class, 'preview'])->middleware('role:super_admin,school_admin');
        Route::post('/report-cards/templates/{id}/activate', [\App\Http\Controllers\Api\V1\ReportCardTemplateController::class, 'activate'])->middleware('role:super_admin,school_admin');
        Route::delete('/report-cards/templates/{id}', [\App\Http\Controllers\Api\V1\ReportCardTemplateController::class, 'destroy'])->middleware('role:super_admin,school_admin');
        Route::get('/report-cards/{studentId}/{termId}', [\App\Http\Controllers\Api\V1\ReportCardTemplateController::class, 'renderReportCard'])->middleware('role:super_admin,school_admin,teacher');

        /*
        |------------------------------------------------------------------
        | Result checker (PIN-gated results)
        |------------------------------------------------------------------
        |
        | Two money flows, deliberately separate:
        |   school -> SchoolPilot  buys PIN stock at the platform rate card
        |   guardian -> school     spends one PIN to open a child's result
        |
        | Staff report-card access (/report-cards/{student}/{term} above) is
        | never metered — the people who produce the results do not pay to
        | read them.
        */

        // Guardians and students.
        Route::middleware('role:super_admin,school_admin,parent,student')->group(function () {
            Route::get('/result-checker/{studentId}/{termId}/summary', [\App\Http\Controllers\Api\V1\ResultCheckerController::class, 'summary']);
            Route::get('/result-checker/{studentId}/{termId}', [\App\Http\Controllers\Api\V1\ResultCheckerController::class, 'show']);
            Route::get('/result-checker/my-pins', [\App\Http\Controllers\Api\V1\ResultCheckerController::class, 'myPins']);
            Route::post('/result-checker/purchase', [\App\Http\Controllers\Api\V1\ResultCheckerController::class, 'purchase']);
            // Tighter limiter than the shared 60/min bucket: redeem is the one
            // endpoint where guessing a code is worth something.
            Route::post('/result-checker/redeem', [\App\Http\Controllers\Api\V1\ResultCheckerController::class, 'redeem'])
                ->middleware('throttle:10,1');
        });

        // School admins and directors.
        Route::middleware('role:super_admin,school_admin')->group(function () {
            Route::get('/result-pins/price-tiers', [\App\Http\Controllers\Api\V1\ResultPinController::class, 'priceTiers']);
            Route::get('/result-pins/inventory', [\App\Http\Controllers\Api\V1\ResultPinController::class, 'inventory']);
            Route::get('/result-pins/batches', [\App\Http\Controllers\Api\V1\ResultPinController::class, 'batches']);
            Route::post('/result-pins/batches', [\App\Http\Controllers\Api\V1\ResultPinController::class, 'purchaseBatch']);
            Route::get('/result-pins/batches/{id}/reveal', [\App\Http\Controllers\Api\V1\ResultPinController::class, 'revealBatch']);
            Route::post('/result-pins/counter-sale', [\App\Http\Controllers\Api\V1\ResultPinController::class, 'sellOverCounter']);
            Route::put('/result-pins/settings', [\App\Http\Controllers\Api\V1\ResultPinController::class, 'updateSettings']);
            Route::post('/result-pins/waive', [\App\Http\Controllers\Api\V1\ResultPinController::class, 'waive']);
            Route::get('/result-pins/sales-report', [\App\Http\Controllers\Api\V1\ResultPinController::class, 'salesReport']);
        });

        /*
         * Releasing results is the school's own academic decision.
         *
         * Deliberately school_admin only, with no super_admin fallback. A
         * SchoolPilot operator sells the school software; they do not get to
         * declare another organisation's marking finished and its report cards
         * fit to publish to parents. Platform staff who need a result released
         * ask the school to do it.
         */
        Route::middleware('role:school_admin')->group(function () {
            Route::get('/results/releases', [\App\Http\Controllers\Api\V1\ResultPinController::class, 'releases']);
            Route::post('/results/release', [\App\Http\Controllers\Api\V1\ResultPinController::class, 'release']);
        });

        // SchoolPilot platform operators only.
        Route::middleware('role:super_admin')->group(function () {
            Route::get('/platform/result-pins/price-tiers', [\App\Http\Controllers\Api\V1\PlatformResultPinController::class, 'listPriceTiers']);
            Route::post('/platform/result-pins/price-tiers', [\App\Http\Controllers\Api\V1\PlatformResultPinController::class, 'storePriceTier']);
            Route::get('/platform/result-pins/batches', [\App\Http\Controllers\Api\V1\PlatformResultPinController::class, 'batches']);
            // Issues sellable stock with no gateway involved — for schools that
            // paid by bank transfer or arranged it directly with SchoolPilot.
            Route::post('/platform/result-pins/grant', [\App\Http\Controllers\Api\V1\PlatformResultPinController::class, 'grantBatch']);
        });

        Route::post('/finance/scholarships', [\App\Http\Controllers\Api\V1\Phase1And2Controller::class, 'storeScholarship'])->middleware('role:super_admin,school_admin');
        Route::post('/transport/bus/{busId}/gps-ping', [\App\Http\Controllers\Api\V1\Phase1And2Controller::class, 'updateBusLocation'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/library/scan-barcode', [\App\Http\Controllers\Api\V1\Phase1And2Controller::class, 'scanBookBarcode'])->middleware('role:super_admin,school_admin,teacher,student');
        Route::post('/hostel/assign-bed', [\App\Http\Controllers\Api\V1\Phase1And2Controller::class, 'assignBed'])->middleware('role:super_admin,school_admin');
        Route::post('/health/clinic-visit', [\App\Http\Controllers\Api\V1\Phase1And2Controller::class, 'recordClinicVisit'])->middleware('role:super_admin,school_admin,teacher');

        Route::get('/user', function (\Illuminate\Http\Request $request) {
            return response()->json($request->user()->load('userProfile'));
        });
    });

    /*
     * Where a gateway sends the payer's browser when checkout ends.
     *
     * Unauthenticated by necessity — a redirect from Paystack carries no token
     * and no session — and it says nothing about the payment, because it cannot
     * verify one. It exists so the mobile webview has a URL it can recognise as
     * "checkout is over" and close on. Host sniffing cannot do that job: bank
     * 3-D Secure steps bounce through arbitrary domains mid-payment.
     */
    Route::get('/payments/return', [\App\Http\Controllers\Api\V1\FinanceController::class, 'paymentReturn'])
        ->name('payments.return');

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

});

/*
|--------------------------------------------------------------------------
| Payment Gateway Webhooks
|--------------------------------------------------------------------------
|
| Deliberately outside the shared throttle group. A 429 on a gateway callback
| silently drops a payment confirmation, and gateway retries arrive in bursts
| during peak fee-collection windows. Authenticity comes from the HMAC
| signature check in the controller, not from rate limiting.
|
*/
Route::middleware([TenantResolutionMiddleware::class, 'throttle:webhooks'])
    ->post('/webhooks/{gateway}', [\App\Http\Controllers\Api\V1\FinanceController::class, 'handleWebhook']);
