<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Middleware\TenantResolutionMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AbsenceAlertController;
use App\Http\Controllers\Api\V1\AcademicHistoryController;
use App\Http\Controllers\Api\V1\AcademicStructureController;
use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\AddedFeaturesController;
use App\Http\Controllers\Api\V1\AdmissionController;
use App\Http\Controllers\Api\V1\AiStudioController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AssessmentController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\CbtController;
use App\Http\Controllers\Api\V1\CbtOfflineBundleController;
use App\Http\Controllers\Api\V1\CbtQuestionGroupController;
use App\Http\Controllers\Api\V1\ClassTeacherController;
use App\Http\Controllers\Api\V1\ExamTimetableController;
use App\Http\Controllers\Api\V1\FeeCollectionController;
use App\Http\Controllers\Api\V1\FeeStructureController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\GamificationController;
use App\Http\Controllers\Api\V1\GeneratedReportCardController;
use App\Http\Controllers\Api\V1\GradingScaleController;
use App\Http\Controllers\Api\V1\HomeworkSubmissionController;
use App\Http\Controllers\Api\V1\IdCardController;
use App\Http\Controllers\Api\V1\IdCardTemplateController;
use App\Http\Controllers\Api\V1\JobStatusController;
use App\Http\Controllers\Api\V1\LearningMaterialController;
use App\Http\Controllers\Api\V1\LiveClassController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ParentPortalController;
use App\Http\Controllers\Api\V1\PaymentGatewayController;
use App\Http\Controllers\Api\V1\Phase1And2Controller;
use App\Http\Controllers\Api\V1\PlatformAccessController;
use App\Http\Controllers\Api\V1\PlatformAiRemarksController;
use App\Http\Controllers\Api\V1\PlatformAnnouncementController;
use App\Http\Controllers\Api\V1\PlatformBillingController;
use App\Http\Controllers\Api\V1\PlatformPartnerController;
use App\Http\Controllers\Api\V1\PlatformComplianceController;
use App\Http\Controllers\Api\V1\PlatformController;
use App\Http\Controllers\Api\V1\PlatformLeadController;
use App\Http\Controllers\Api\V1\PlatformMessagingController;
use App\Http\Controllers\Api\V1\PlatformReportCardDesignController;
use App\Http\Controllers\Api\V1\PlatformResultPinController;
use App\Http\Controllers\Api\V1\PlatformSecurityController;
use App\Http\Controllers\Api\V1\PlatformSettingsController;
use App\Http\Controllers\Api\V1\PlatformWebsiteController;
use App\Http\Controllers\Api\V1\PublicMarketingController;
use App\Http\Controllers\Api\V1\ReportCardDesignRequestController;
use App\Http\Controllers\Api\V1\ReportCardTemplateController;
use App\Http\Controllers\Api\V1\ReportEntryController;
use App\Http\Controllers\Api\V1\ResultCheckerController;
use App\Http\Controllers\Api\V1\ResultPinController;
use App\Http\Controllers\Api\V1\ResultPinReportController;
use App\Http\Controllers\Api\V1\SchoolDataExportController;
use App\Http\Controllers\Api\V1\SchoolFeatureController;
use App\Http\Controllers\Api\V1\SchoolMigrationController;
use App\Http\Controllers\Api\V1\SchoolProfileController;
use App\Http\Controllers\Api\V1\SchoolSmsGatewayController;
use App\Http\Controllers\Api\V1\SelfProfileController;
use App\Http\Controllers\Api\V1\SessionRolloverController;
use App\Http\Controllers\Api\V1\StaffDirectoryController;
use App\Http\Controllers\Api\V1\StaffHrController;
use App\Http\Controllers\Api\V1\StaffStudentAccessController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\SubjectManagementController;
use App\Http\Controllers\Api\V1\SwaggerDocController;
use App\Http\Controllers\Api\V1\TeacherProductivityController;
use App\Http\Controllers\Api\V1\TimetableController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UserManagementController;

/*
|--------------------------------------------------------------------------
| API Routes — Version 1 (/api/v1/...)
|--------------------------------------------------------------------------
*/

/*
 * The public marketing site (schoolpilot.org.ng, Landing PRD §14.2).
 *
 * Outside tenant resolution on purpose: the site is on the apex domain, whose
 * first label is not a school and would 404 there, and none of these routes
 * concern one school's data.
 */
Route::prefix('public')->group(function () {
    Route::post('/leads', [PublicMarketingController::class, 'storeLead'])
        ->middleware('throttle:marketing-leads');
    Route::post('/leads/touch', [PublicMarketingController::class, 'touch'])
        ->middleware('throttle:30,1');
    Route::get('/schools/resolve', [PublicMarketingController::class, 'resolveSchool'])
        ->middleware('throttle:20,1');
    Route::get('/stats', [PublicMarketingController::class, 'stats'])
        ->middleware('throttle:60,1');
    // Read by the site's build: console-managed contacts, switches and screenshots.
    Route::get('/site-config', [PlatformWebsiteController::class, 'siteConfig'])
        ->middleware('throttle:30,1');
});

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

    // A learning material opened from the mobile app in the phone's own viewer.
    // No session: the ten-minute signature issued by /learning-materials/{id}/link
    // is the permission.
    Route::get('/learning-materials/{id}/file', [LearningMaterialController::class, 'signedFile'])
        ->whereNumber('id')->middleware(['signed', 'throttle:60,1'])->name('learning-materials.file');

    // Authenticated Protected Routes
    //
    // BindTenantFromUser runs after authentication because the tenant
    // middleware executes before it and can only see the host — which is a
    // bare `localhost` on any deployment not using subdomains.
    Route::middleware(['auth:sanctum', \App\Http\Middleware\BindTenantFromUser::class, 'require.2fa', \App\Http\Middleware\EnforceSchoolModules::class])->group(function () {
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
        Route::get('/auth/2fa', [TwoFactorController::class, 'status']);
        Route::post('/auth/2fa/setup', [TwoFactorController::class, 'setup']);
        Route::post('/auth/2fa/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/auth/2fa/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        Route::delete('/auth/2fa', [TwoFactorController::class, 'disable']);
        
        // Student Information System (SIS) Routes
        Route::get('/students', [StudentController::class, 'index'])->middleware('role:super_admin,school_admin,teacher');
        // Search-as-you-type for pickers: at most 20 matches, name + admission
        // number + class only. Declared before /students/{id}, which would
        // otherwise take "lookup" as an id. Nurses use it to log clinic visits.
        Route::get('/students/lookup', [StudentController::class, 'lookup'])->middleware('role:super_admin,school_admin,teacher,nurse,bursar');
        // A teacher sees the classes they teach; the school admin may grant the whole school.
        Route::put('/staff/{userId}/student-access', [StaffStudentAccessController::class, 'update'])->middleware('role:school_admin')->whereNumber('userId');

        // Staff: the directory, adding one, and adding many from a CSV.
        Route::middleware('role:super_admin,school_admin')->group(function () {
            Route::get('/staff/directory', [StaffDirectoryController::class, 'index']);
            Route::post('/staff', [StaffDirectoryController::class, 'store']);
            Route::post('/staff/import', [StaffDirectoryController::class, 'import']);
            Route::get('/staff/import/template', [StaffDirectoryController::class, 'template']);
        });
        Route::post('/students', [StudentController::class, 'store'])->middleware('role:super_admin,school_admin');
        Route::post('/students/promote', [StudentController::class, 'promote'])->middleware('role:super_admin,school_admin');
        Route::get('/students/{student}/class-history', [StudentController::class, 'classHistory'])->middleware('role:super_admin,school_admin,teacher');

        /*
         * End-of-session rollover. Declared above `/students/{id}` so the
         * literal segments win the match — `rollover` is not a student id.
         *
         * `preview` writes nothing; `commit` is the only destructive call, and
         * it is one transaction for the whole school.
         */
        Route::post('/students/rollover/preview', [SessionRolloverController::class, 'preview'])->middleware('role:super_admin,school_admin');
        Route::post('/students/rollover/commit', [SessionRolloverController::class, 'commit'])->middleware('role:super_admin,school_admin');
        Route::post('/students/{id}/exit', [SessionRolloverController::class, 'exitStudent'])->middleware('role:super_admin,school_admin');
        Route::get('/students/{id}/enrollments', [SessionRolloverController::class, 'enrollments'])->middleware('role:super_admin,school_admin,teacher');

        /*
         * Health data is a separate, narrower route from the student record.
         * It used to ride along on every roster listing, which is open to
         * every teacher in the school. Admins and guardians only, policy-checked
         * per child, and every read writes an audit row (NDPA, doc §12).
         */
        Route::get('/students/{id}/medical', [StudentController::class, 'medical'])
            ->middleware('role:super_admin,school_admin,parent');

        // `show()` existed on the controller but had no route — a single
        // student's record was not fetchable at all.
        Route::get('/students/{id}', [StudentController::class, 'show'])
            ->middleware('role:super_admin,school_admin,teacher,parent,student');
        Route::post('/students/import', [StudentController::class, 'bulkImport'])->middleware('role:super_admin,school_admin');

        /*
         * Two-phase import: look before you leap. `preview` parses and
         * validates a register and returns a token; `commit` redeems the token
         * once. The single-shot route above stays for installed clients.
         */
        Route::post('/students/import/preview', [StudentController::class, 'importPreview'])->middleware('role:super_admin,school_admin');
        Route::post('/students/import/commit', [StudentController::class, 'importCommit'])->middleware('role:super_admin,school_admin');
        // A ready-made CSV with the exact headers the importer recognises.
        Route::get('/students/import/template', [StudentController::class, 'importTemplate'])->middleware('role:super_admin,school_admin');

        // Attendance & Hardware-Free Clock-In
        Route::post('/attendance/qr-token', [AttendanceController::class, 'generateQrToken'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/attendance/mark', [AttendanceController::class, 'markStudentAttendance'])->middleware('role:super_admin,school_admin,teacher');
        // Roll call: one request for the whole register instead of one per
        // child. Idempotent, so a retry on a dropped connection is safe.
        Route::post('/attendance/bulk', [AttendanceController::class, 'markBulkAttendance'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/attendance/register', [AttendanceController::class, 'classRegister'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/attendance/corrections', [AttendanceController::class, 'corrections'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/attendance/staff-gps', [AttendanceController::class, 'staffGpsClockIn'])->middleware('role:super_admin,school_admin,teacher');

        // Timetable Solver Engine & Management
        Route::post('/timetable/generate', [TimetableController::class, 'generate'])->middleware('role:super_admin,school_admin');
        Route::get('/timetable/versions', [TimetableController::class, 'versions'])->middleware('role:super_admin,school_admin');
        Route::post('/timetable/versions/{id}/publish', [TimetableController::class, 'publish'])->middleware('role:super_admin,school_admin');
        Route::get('/timetable/view', [TimetableController::class, 'view']);

        // Exam timetables. Everyone reads published ones; admins build them.
        Route::get('/exam-timetables', [ExamTimetableController::class, 'index']);
        Route::get('/exam-timetables/{id}', [ExamTimetableController::class, 'show'])->whereNumber('id');
        Route::middleware('role:super_admin,school_admin')->group(function () {
            Route::post('/exam-timetables', [ExamTimetableController::class, 'store']);
            Route::put('/exam-timetables/{id}', [ExamTimetableController::class, 'update'])->whereNumber('id');
            Route::delete('/exam-timetables/{id}', [ExamTimetableController::class, 'destroy'])->whereNumber('id');
            Route::post('/exam-timetables/{id}/sittings', [ExamTimetableController::class, 'storeSitting'])->whereNumber('id');
            Route::put('/exam-sittings/{id}', [ExamTimetableController::class, 'updateSitting'])->whereNumber('id');
            Route::delete('/exam-sittings/{id}', [ExamTimetableController::class, 'destroySitting'])->whereNumber('id');
        });

        // Assessment & Broadsheet Routes
        Route::post('/assessment/score', [AssessmentController::class, 'enterScores'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/assessment/score/{id}/ai-comment', [AssessmentController::class, 'generateAiComment'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/assessment/score/{id}/review-comment', [AssessmentController::class, 'reviewAiComment'])->middleware('role:super_admin,school_admin,teacher');
        // The review queue itself. Without a way to read it, the approval gate
        // on AI comments existed only on the write side.
        Route::get('/assessment/ai-comments/pending', [AssessmentController::class, 'pendingAiComments'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/assessment/broadsheet', [AssessmentController::class, 'getBroadsheet'])->middleware('role:super_admin,school_admin,teacher');

        // Configurable CA weighting (§7.7) — schools each split CA vs exam
        // differently, so this cannot be hardcoded.
        Route::get('/assessment/ca-schemes', [AssessmentController::class, 'listCaSchemes'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/assessment/ca-schemes', [AssessmentController::class, 'storeCaScheme'])->middleware('role:super_admin,school_admin');
        Route::post('/assessment/ca-schemes/{id}/activate', [AssessmentController::class, 'activateCaScheme'])->middleware('role:super_admin,school_admin');

        /*
         * Modules built from the TASK specifications. Each file is required
         * here, inside the authenticated, tenant-bound, module-enforced group,
         * so every route in them inherits the same guards as the rest of
         * /api/v1 without repeating them.
         */
        require __DIR__.'/modules/results.php';
        require __DIR__.'/modules/history.php';
        require __DIR__.'/modules/students.php';
        require __DIR__.'/modules/progress.php';
        require __DIR__.'/modules/admissions.php';
        require __DIR__.'/modules/cbt.php';
        require __DIR__.'/modules/hostel.php';
        require __DIR__.'/modules/lms.php';
        require __DIR__.'/modules/id-cards.php';
        require __DIR__.'/modules/student-portal.php';
        require __DIR__.'/modules/hr.php';
        require __DIR__.'/modules/library.php';
        require __DIR__.'/modules/timetable.php';

        /*
         * The fee-structure builder (§7.12).
         *
         * The POST path is unchanged from when this lived on FinanceController —
         * it is published under /api/v1 and mobile clients may already call it.
         * Only the handler moved.
         */
        Route::middleware('role:super_admin,school_admin,bursar')->group(function () {
            Route::get('/finance/fee-structure', [FeeStructureController::class, 'index']);
            Route::post('/finance/fee-structure', [FeeStructureController::class, 'store']);
            Route::put('/finance/fee-structure/{id}', [FeeStructureController::class, 'update']);
            Route::delete('/finance/fee-structure/{id}', [FeeStructureController::class, 'destroy']);

            // Fee structures -> actual bills. Nothing created an invoice before
            // this: fees were defined and no parent was ever charged.
            Route::post('/finance/invoices/generate', [FeeStructureController::class, 'generateInvoices']);
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
            Route::get('/finance/gateways', [PaymentGatewayController::class, 'index']);
            Route::put('/finance/gateways/{gateway}', [PaymentGatewayController::class, 'update']);
            Route::delete('/finance/gateways/{gateway}', [PaymentGatewayController::class, 'destroy']);
        });

        // Fees & Finance Routes
        //
        // `initialize` opens checkout on the school's own gateway and returns a
        // hosted URL; `payments` records a manual or already-taken payment. The
        // client never holds a key and never mints a reference (G8, G9).
        Route::post('/finance/payments/initialize', [FinanceController::class, 'initializePayment'])->middleware('role:super_admin,school_admin,parent,student');
        Route::post('/finance/payments', [FinanceController::class, 'recordPayment'])->middleware('role:super_admin,school_admin,bursar,parent,student');
        Route::post('/finance/payments/reconcile-bank-transfer', [FinanceController::class, 'reconcileBankTransfer'])->middleware('role:super_admin,school_admin,bursar');
        Route::get('/finance/invoices/{id}/pdf', [FinanceController::class, 'downloadInvoicePdf']);
        Route::get('/finance/payments/{id}/receipt', [FinanceController::class, 'downloadPaymentReceipt']);

        /*
         * Fee collection (§7.12): the defaulter dashboard, instalment plans and
         * discounts. The module could take money but could not report who had
         * not paid — the question a bursar asks weekly.
         */
        Route::middleware('role:super_admin,school_admin,bursar')->group(function () {
            Route::get('/finance/defaulters', [FeeCollectionController::class, 'defaulters']);
            // Chasing them. The finance page's reminder button was wired to
            // nothing until this existed.
            Route::post('/finance/defaulters/remind', [FeeCollectionController::class, 'remindDefaulters']);
            Route::post('/finance/invoices/{invoiceId}/installment-plan', [FeeCollectionController::class, 'createInstallmentPlan']);
            Route::get('/finance/scholarships', [FeeCollectionController::class, 'listScholarships']);
            Route::post('/finance/invoices/{invoiceId}/apply-discount', [FeeCollectionController::class, 'applyDiscount']);
        });

        Route::get('/finance/invoices/{invoiceId}/installment-plan', [FeeCollectionController::class, 'installmentPlan']);
        // Guardians read their own child's statement; StudentPolicy enforces which child.
        Route::get('/finance/students/{studentId}/statement', [FeeCollectionController::class, 'studentStatement'])
            ->middleware('role:super_admin,school_admin,bursar,parent,student');

        /*
         * Staff / HR (§7.3) — the people-management half. Payroll already
         * existed; leave and employment history were JSON columns nothing read.
         */
        // Every staff role holds leave; the school decides in Settings > Staff
        // leave which roles request it here (StaffLeaveSetting).
        Route::middleware('role:super_admin,school_admin,teacher,bursar,nurse')->group(function () {
            Route::post('/hr/leave', [StaffHrController::class, 'requestLeave']);
            Route::get('/hr/leave', [StaffHrController::class, 'listLeave']);
            Route::post('/hr/leave/{id}/cancel', [StaffHrController::class, 'cancelLeave']);
            Route::get('/hr/leave-settings', [StaffHrController::class, 'leaveSettings']);
        });

        Route::middleware('role:super_admin,school_admin,teacher')->group(function () {
            Route::get('/hr/leave-calendar', [StaffHrController::class, 'leaveCalendar']);
            Route::get('/hr/staff/{staffId}/employment-record', [StaffHrController::class, 'employmentRecord']);
        });

        Route::middleware('role:super_admin,school_admin')->group(function () {
            Route::post('/hr/leave/{id}/decision', [StaffHrController::class, 'decideLeave']);
            Route::put('/hr/leave-settings', [StaffHrController::class, 'updateLeaveSettings']);
            Route::put('/hr/staff/{staffId}/leave-allocation', [StaffHrController::class, 'setLeaveAllocation']);
            Route::post('/hr/staff/{staffId}/employment-history', [StaffHrController::class, 'addEmploymentHistory']);
        });

        // Advanced School Accounting Routes (Beyond Fee Collection)
        Route::post('/accounting/payroll', [AccountingController::class, 'processPayroll'])->middleware('role:super_admin,school_admin');
        Route::post('/accounting/vendors', [AccountingController::class, 'storeVendor'])->middleware('role:super_admin,school_admin,bursar');
        Route::post('/accounting/expenses', [AccountingController::class, 'recordExpense'])->middleware('role:super_admin,school_admin,bursar');
        Route::post('/accounting/petty-cash', [AccountingController::class, 'logPettyCash'])->middleware('role:super_admin,school_admin,bursar');
        Route::post('/accounting/budget', [AccountingController::class, 'storeBudget'])->middleware('role:super_admin,school_admin,bursar');
        Route::get('/accounting/income-report', [AccountingController::class, 'getIncomeReport'])->middleware('role:super_admin,school_admin,bursar');
        // Reading back what the accounts screen records (vendors, expenses, petty cash, budgets).
        Route::middleware('role:super_admin,school_admin,bursar')->group(function () {
            Route::get('/accounting/vendors', [AccountingController::class, 'listVendors']);
            Route::get('/accounting/expenses', [AccountingController::class, 'listExpenses']);
            Route::get('/accounting/petty-cash', [AccountingController::class, 'listPettyCash']);
            Route::get('/accounting/budget', [AccountingController::class, 'listBudgets']);
            // The payments ledger, including bank transfers waiting to be reconciled.
            Route::get('/finance/payments', [FinanceController::class, 'listPayments']);
        });

        // Advanced Parent Engagement Portal Routes
        /*
         * The guardian's own children (gap G11) — and the first request a
         * signed-in parent can make. Every other route in this block takes a
         * `{studentId}` that had no discoverable source: `GET /students` is
         * staff-only and nothing read `student_guardian` from the guardian's
         * side. Scoped to the caller's own pivot rows inside the controller,
         * so the role list here is the outer fence, not the check.
         */
        /*
         * The school's public profile: what its subdomain shows signed-out
         * visitors, and whether search engines may index it. School admin only.
         */
        Route::middleware('role:school_admin')->group(function () {
            Route::get('/school/profile', [SchoolProfileController::class, 'show']);
            Route::put('/school/profile', [SchoolProfileController::class, 'update']);
            Route::post('/school/profile/images/{kind}', [SchoolProfileController::class, 'uploadImage'])
                ->whereIn('kind', ['logo', 'hero'])->middleware('throttle:10,1');
            Route::delete('/school/profile/images/{kind}', [SchoolProfileController::class, 'removeImage'])
                ->whereIn('kind', ['logo', 'hero']);
        });

        /*
         * Absence alerts: off until the school switches them on, and then only
         * for parents who opt in, per child. The school office can record a
         * parent's choice for them (a phone-only parent cannot sign in).
         */
        Route::get('/school/absence-alerts', [AbsenceAlertController::class, 'schoolSettings'])->middleware('role:school_admin');
        Route::put('/school/absence-alerts', [AbsenceAlertController::class, 'updateSchool'])->middleware('role:school_admin');
        Route::get('/students/{studentId}/absence-alerts', [AbsenceAlertController::class, 'forStudent'])->middleware('role:school_admin')->whereNumber('studentId');
        Route::put('/students/{studentId}/guardians/{guardianId}/absence-alerts', [AbsenceAlertController::class, 'setForGuardian'])->middleware('role:school_admin')->whereNumber(['studentId', 'guardianId']);
        Route::get('/parent/absence-alerts', [AbsenceAlertController::class, 'mine'])->middleware('role:parent');
        Route::put('/parent/children/{studentId}/absence-alerts', [AbsenceAlertController::class, 'setMine'])->middleware('role:parent')->whereNumber('studentId');

        Route::get('/parent/children', [ParentPortalController::class, 'children'])
            ->middleware('role:super_admin,school_admin,parent');
        // A child's form teacher and subject teachers, to start a message.
        Route::get('/parent/children/{studentId}/contacts', [ParentPortalController::class, 'contacts'])->middleware('role:parent')->whereNumber('studentId');
        Route::get('/parent/feed/{studentId}', [ParentPortalController::class, 'getStudentFeed'])->middleware('role:super_admin,school_admin,parent');
        Route::post('/parent/pickup-authorization', [ParentPortalController::class, 'storePickupAuthorization'])->middleware('role:super_admin,school_admin,parent');
        Route::get('/parent/pickup-authorizations', [ParentPortalController::class, 'pickupAuthorizations'])->middleware('role:parent');
        Route::post('/parent/pickup-authorizations/{id}/revoke', [ParentPortalController::class, 'revokePickupAuthorization'])->whereNumber('id')->middleware('role:parent');
        // Full behaviour history, paginated. The feed carries only the ten most
        // recent so the parent home screen is not re-downloading nine years of
        // rows on every open.
        Route::get('/students/{studentId}/behavior-reports', [ParentPortalController::class, 'behaviorHistory'])
            ->middleware('role:super_admin,school_admin,teacher,parent');
        Route::post('/academics/homework', [ParentPortalController::class, 'storeHomework'])->middleware('role:super_admin,school_admin,teacher');

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
        Route::get('/academics/homework', [HomeworkSubmissionController::class, 'feed']);

        /*
         * Homework submissions — the return half, which did not exist. A
         * teacher could set work and never receive it back.
         */
        Route::middleware('role:student')->group(function () {
            Route::post('/academics/homework/{homeworkId}/submit', [HomeworkSubmissionController::class, 'store']);
            Route::get('/academics/homework/{homeworkId}/my-submission', [HomeworkSubmissionController::class, 'mine']);
        });

        Route::middleware('role:super_admin,school_admin,teacher')->group(function () {
            Route::get('/academics/homework/{homeworkId}/submissions', [HomeworkSubmissionController::class, 'index']);
            Route::post('/academics/homework/{homeworkId}/bulk-grade', [HomeworkSubmissionController::class, 'bulkGrade']);
            Route::post('/academics/submissions/{submissionId}/grade', [HomeworkSubmissionController::class, 'grade']);
        });
        Route::post('/students/behavior-report', [ParentPortalController::class, 'storeBehaviorReport'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/calendar/events', [ParentPortalController::class, 'storeCalendarEvent'])->middleware('role:super_admin,school_admin');
        Route::get('/calendar/events', [ParentPortalController::class, 'calendarEvents'])->middleware('role:super_admin,school_admin');
        Route::delete('/calendar/events/{id}', [ParentPortalController::class, 'deleteCalendarEvent'])->whereNumber('id')->middleware('role:super_admin,school_admin');

        // User Management & Administration Routes (bulk/static BEFORE parameterized)
        Route::post('/users/bulk-status', [UserManagementController::class, 'bulkUpdateStatus'])->middleware('role:super_admin,school_admin');
        Route::post('/users/bulk-import', [UserManagementController::class, 'bulkImportUsers'])->middleware('role:super_admin,school_admin');
        Route::get('/users/export', [UserManagementController::class, 'exportUsers'])->middleware('role:super_admin,school_admin');
        Route::get('/users', [UserManagementController::class, 'listUsers'])->middleware('role:super_admin,school_admin');
        Route::get('/users/{id}', [UserManagementController::class, 'showUser'])->middleware('role:super_admin,school_admin');
        Route::post('/users/{id}/status', [UserManagementController::class, 'updateUserStatus'])->middleware('role:super_admin,school_admin');
        Route::put('/users/{id}/profile', [UserManagementController::class, 'updateProfile'])->middleware('role:super_admin,school_admin');
        Route::get('/users/{id}/profile-completion', [UserManagementController::class, 'getProfileCompletion'])->middleware('role:super_admin,school_admin');
        Route::get('/users/{id}/timeline', [UserManagementController::class, 'getUserTimeline'])->middleware('role:super_admin,school_admin');
        Route::get('/users/{id}/login-history', [UserManagementController::class, 'getLoginHistory'])->middleware('role:super_admin,school_admin');
        Route::post('/users/{id}/reset-password', [UserManagementController::class, 'resetPassword'])->middleware('role:super_admin,school_admin');
        Route::post('/users/{id}/unlock', [UserManagementController::class, 'unlockAccount'])->middleware('role:super_admin,school_admin');
        Route::post('/users/{id}/revoke-sessions', [UserManagementController::class, 'revokeAllSessions'])->middleware('role:super_admin,school_admin');
        Route::get('/users/{id}/security', [UserManagementController::class, 'getSecurityOverview'])->middleware('role:super_admin,school_admin');

        // Custom Roles Management
        Route::get('/roles', [UserManagementController::class, 'listRoles'])->middleware('role:super_admin,school_admin');
        Route::post('/roles', [UserManagementController::class, 'createRole'])->middleware('role:super_admin,school_admin');
        Route::put('/roles/{id}', [UserManagementController::class, 'updateRole'])->middleware('role:super_admin,school_admin');
        Route::delete('/roles/{id}', [UserManagementController::class, 'deleteRole'])->middleware('role:super_admin,school_admin');
        Route::post('/users/{userId}/roles', [UserManagementController::class, 'assignRole'])->middleware('role:super_admin,school_admin');
        Route::delete('/users/{userId}/roles/{roleId}', [UserManagementController::class, 'revokeRole'])->middleware('role:super_admin,school_admin');

        // Extended Profile Routes (Teacher, Parent, Student Portfolio)
        Route::get('/teachers/{userId}/profile', [UserManagementController::class, 'getTeacherProfile'])->middleware('role:super_admin,school_admin,teacher');
        Route::put('/teachers/{userId}/profile', [UserManagementController::class, 'updateTeacherProfile'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/parents/{userId}/profile', [UserManagementController::class, 'getParentProfile'])->middleware('role:super_admin,school_admin,parent');
        Route::put('/parents/{userId}/profile', [UserManagementController::class, 'updateParentProfile'])->middleware('role:super_admin,school_admin,parent');
        Route::get('/students/{studentId}/portfolio', [UserManagementController::class, 'getPortfolio'])->middleware('role:super_admin,school_admin,teacher,student,parent');
        Route::post('/students/{studentId}/portfolio', [UserManagementController::class, 'addPortfolioEntry'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/portfolio/{id}/verify', [UserManagementController::class, 'verifyPortfolioEntry'])->middleware('role:super_admin,school_admin');

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
        Route::get('/classes', [AcademicStructureController::class, 'classes']);
        Route::get('/terms', [AcademicStructureController::class, 'terms']);

        // What this school has: modules SchoolPilot switched on, and the AI
        // features the school chose. Read by every role (the menu uses it).
        Route::get('/school/features', [SchoolFeatureController::class, 'show']);
        Route::put('/school/ai-features', [SchoolFeatureController::class, 'updateAi'])->middleware('role:super_admin,school_admin');
        Route::put('/school/ai-provider', [SchoolFeatureController::class, 'updateAiProvider'])->middleware('role:super_admin,school_admin');

        /*
         * Form teachers. The listing is open to staff — a teacher needs to know
         * who owns a class to route a message about a child — but only an admin
         * assigns one. `my-classes` is scoped to the caller by construction.
         */
        Route::get('/class-teachers', [ClassTeacherController::class, 'index'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/class-teachers', [ClassTeacherController::class, 'store'])->middleware('role:super_admin,school_admin');
        Route::delete('/class-teachers/{id}', [ClassTeacherController::class, 'destroy'])->middleware('role:super_admin,school_admin');
        Route::get('/teacher/my-classes', [ClassTeacherController::class, 'myClasses'])->middleware('role:super_admin,school_admin,teacher');

        // Subject Management & Enrollment Routes
        Route::get('/subjects', [SubjectManagementController::class, 'listSubjects']);
        Route::post('/subjects', [SubjectManagementController::class, 'storeSubject'])->middleware('role:super_admin,school_admin');
        Route::put('/subjects/{id}', [SubjectManagementController::class, 'updateSubject'])->middleware('role:super_admin,school_admin')->whereNumber('id');
        Route::get('/subjects/assignments', [SubjectManagementController::class, 'assignments'])->middleware('role:super_admin,school_admin,teacher');
        Route::delete('/classes/{classId}/subjects/{subjectId}', [SubjectManagementController::class, 'removeClassSubject'])->middleware('role:super_admin,school_admin');
        Route::post('/subjects/assign-teacher', [SubjectManagementController::class, 'assignTeacher'])->middleware('role:super_admin,school_admin');
        Route::post('/subjects/remove-teacher', [SubjectManagementController::class, 'removeTeacher'])->middleware('role:super_admin,school_admin');
        Route::get('/teachers/{teacherId}/subjects', [SubjectManagementController::class, 'getTeacherSubjects'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/classes/subjects', [SubjectManagementController::class, 'setClassSubjects'])->middleware('role:super_admin,school_admin');
        Route::get('/classes/{classId}/subjects', [SubjectManagementController::class, 'getClassSubjects']);
        Route::post('/students/{studentId}/subjects', [SubjectManagementController::class, 'enrollStudentSubjects'])->middleware('role:super_admin,school_admin,student');
        Route::get('/students/{studentId}/subjects', [SubjectManagementController::class, 'getStudentSubjects']);
        Route::delete('/students/{studentId}/subjects/{subjectId}', [SubjectManagementController::class, 'dropSubject'])->middleware('role:super_admin,school_admin,student');
        Route::post('/subjects/bulk-auto-enroll', [SubjectManagementController::class, 'bulkAutoEnroll'])->middleware('role:super_admin,school_admin');

        // Teacher Productivity Routes
        Route::post('/teacher/bulk-grading', [TeacherProductivityController::class, 'bulkGrading'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/teacher/import-scores', [TeacherProductivityController::class, 'importScoresCsv'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/teacher/export-scores', [TeacherProductivityController::class, 'exportScoresCsv'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/teacher/score-template', [TeacherProductivityController::class, 'scoreTemplate'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/teacher/copy-lesson-plans', [TeacherProductivityController::class, 'copyPreviousTermLessonPlans'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/teacher/duplicate-exam', [TeacherProductivityController::class, 'duplicateExam'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/teacher/comment-bank', [TeacherProductivityController::class, 'getCommentBank'])->middleware('role:super_admin,school_admin,teacher');

        // Advanced Role-Based Analytics Dashboard Routes
        Route::get('/analytics/principal', [AnalyticsController::class, 'getPrincipalDashboard'])->middleware('role:super_admin,school_admin');
        Route::get('/analytics/teacher', [AnalyticsController::class, 'getTeacherDashboard'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/analytics/parent/{studentId}', [AnalyticsController::class, 'getParentDashboard'])->middleware('role:super_admin,school_admin,parent');
        Route::get('/analytics/student', [AnalyticsController::class, 'getStudentDashboard'])->middleware('role:super_admin,school_admin,student');

        // AI Studio & Optional Module Routes
        Route::post('/ai/settings', [AiStudioController::class, 'updateAiSettings'])->middleware('role:super_admin,school_admin');
        Route::post('/ai/lesson-plan', [AiStudioController::class, 'generateLessonPlan'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/ai/exam-generation', [AiStudioController::class, 'generateExams'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/ai/homework-ideas', [AiStudioController::class, 'generateHomework'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/ai/translate', [AiStudioController::class, 'translate'])->middleware('role:super_admin,school_admin,teacher');
        Route::get('/ai/performance-summary/{studentId}', [AiStudioController::class, 'summarizePerformance'])->middleware('role:super_admin,school_admin,teacher');
        // AI Learning Hub — student tutor (§7.11). Student-only: this used to
        // carry no role middleware at all, unlike every sibling AI route.
        Route::middleware('role:student')->group(function () {
            Route::post('/ai/tutor-chat', [AiStudioController::class, 'tutorChat']);
            Route::get('/ai/tutor/conversations', [AiStudioController::class, 'tutorConversations']);
            Route::get('/ai/tutor/conversations/{id}', [AiStudioController::class, 'tutorConversation']);
            Route::get('/ai/tutor/mastery', [AiStudioController::class, 'tutorMastery']);
        });

        // NDPA Parental Consent & Data Export Routes
        Route::get('/parent/consents', [AddedFeaturesController::class, 'myParentalConsents'])->middleware('role:parent');
        Route::post('/compliance/parental-consent', [AddedFeaturesController::class, 'recordParentalConsent'])->middleware('role:super_admin,school_admin,parent');
        Route::post('/compliance/parental-consent/{id}/withdraw', [AddedFeaturesController::class, 'withdrawParentalConsent'])->middleware('role:super_admin,school_admin,parent');
        Route::post('/admin/export-data', [AddedFeaturesController::class, 'exportSchoolData'])->middleware('role:super_admin,school_admin');

        /*
         * The real thing: a queued archive a school can actually keep. The
         * inline JSON route above is left in place because clients call it,
         * but it returns students only and cannot survive a real school's row
         * counts — new work should use these.
         *
         * Admins only, including the download: an archive is every record the
         * school holds, so the roles that can read one child's file are not
         * the roles that can take all of them at once.
         */
        Route::get('/admin/exports', [SchoolDataExportController::class, 'index'])->middleware('role:super_admin,school_admin');
        Route::post('/admin/exports', [SchoolDataExportController::class, 'store'])->middleware('role:super_admin,school_admin');
        Route::get('/admin/exports/{id}', [SchoolDataExportController::class, 'show'])->middleware('role:super_admin,school_admin');
        Route::get('/admin/exports/{id}/download', [SchoolDataExportController::class, 'download'])->middleware('role:super_admin,school_admin');

        /*
         * Notifications (§7.13). NotificationController was the only controller
         * in the codebase with no routes at all, so push/SMS/WhatsApp were
         * unreachable except as inline calls buried in two other controllers.
         */
        Route::post('/notifications/devices', [NotificationController::class, 'registerDevice']);
        Route::delete('/notifications/devices', [NotificationController::class, 'unregisterDevice']);

        /*
         * Self-diagnostic: push to your own handset and get back a per-device
         * verdict. Open to every role because it can only ever reach the
         * caller's own devices. Throttled anyway — it is the one push route
         * that sends immediately rather than queueing.
         */
        Route::post('/notifications/devices/test', [NotificationController::class, 'testDevice'])
            ->middleware('throttle:10,1');

        Route::middleware('role:super_admin,school_admin,teacher')->group(function () {
            Route::post('/notifications/broadcast', [NotificationController::class, 'broadcast']);
            Route::post('/notifications/whatsapp', [NotificationController::class, 'sendWhatsAppNotification']);
            Route::post('/notifications/whatsapp/structured', [NotificationController::class, 'sendStructuredWhatsApp']);
        });

        Route::get('/notifications/history', [NotificationController::class, 'history'])
            ->middleware('role:super_admin,school_admin');

        // --- Messaging (school SMS gateway, audiences, channel readiness) ---
        Route::middleware('role:super_admin,school_admin,teacher')->group(function () {
            // Who an audience reaches, and which channels can deliver now.
            Route::post('/notifications/audience-preview', [NotificationController::class, 'audiencePreview']);
            Route::get('/notifications/channels', [NotificationController::class, 'channels']);
        });

        /*
         * The school's own SMS account. It sends from the school's phone and
         * SIM, or its own bulk account, so it belongs to the school. Admins
         * only: the key can send on the school's airtime.
         */
        Route::middleware('role:super_admin,school_admin')->group(function () {
            Route::get('/school/sms-gateway', [SchoolSmsGatewayController::class, 'show']);
            Route::put('/school/sms-gateway', [SchoolSmsGatewayController::class, 'update']);
            Route::delete('/school/sms-gateway', [SchoolSmsGatewayController::class, 'destroy']);
            Route::post('/school/sms-gateway/test', [SchoolSmsGatewayController::class, 'test'])
                ->middleware('throttle:10,1');
            Route::get('/school/sms-gateway/balance', [SchoolSmsGatewayController::class, 'balance']);
        });
        // --- end Messaging ---

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
        // Your own phone number and photo (gap G3). POST, because PHP does not
        // parse multipart bodies on PUT and the photo is a file upload.
        Route::post('/me/profile', [SelfProfileController::class, 'update']);
        Route::get('/me/notifications', [NotificationController::class, 'inbox']);
        Route::post('/me/notifications/read', [NotificationController::class, 'markInboxRead']);

        /*
         * Queued work and its status. Report-card runs and broadcasts return a
         * batch id immediately; clients poll here rather than holding a request
         * open past a shared-hosting PHP timeout.
         */
        Route::get('/jobs/{batchId}', [JobStatusController::class, 'show']);
        Route::middleware('role:super_admin,school_admin')->group(function () {
            Route::post('/jobs/{batchId}/cancel', [JobStatusController::class, 'cancel']);
            Route::post('/report-cards/generate', [JobStatusController::class, 'generateReportCards']);
            Route::get('/report-cards/generated', [GeneratedReportCardController::class, 'index']);
            Route::get('/report-cards/generated/{studentId}/{termId}', [GeneratedReportCardController::class, 'download'])->whereNumber('studentId')->whereNumber('termId');
        });

        // Teacher-Parent In-App Messaging Routes
        Route::get('/messages/threads', [AddedFeaturesController::class, 'getThreads']);
        // Reading a thread marks the other side's messages seen. Participation
        // is checked in the controller — a thread id is guessable.
        Route::get('/messages/threads/{id}', [AddedFeaturesController::class, 'showThread']);
        Route::post('/messages/send', [AddedFeaturesController::class, 'sendMessage']);

        // Phase 1 & Phase 2 Module Routes
        Route::get('/gamification/profile/{studentId?}', [GamificationController::class, 'getProfile']);
        // Point values are server-side (see ACTIVITY_POINTS); a student may only
        // record activity against themselves.
        Route::post('/gamification/activity', [GamificationController::class, 'recordActivity'])
            ->middleware('role:super_admin,school_admin,teacher,student');
        Route::get('/gamification/leaderboard', [GamificationController::class, 'getLeaderboard']);

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
            Route::get('/cbt/media', [CbtController::class, 'listMedia']);
            Route::post('/cbt/media', [CbtController::class, 'uploadMedia']);
            Route::delete('/cbt/media/{id}', [CbtController::class, 'destroyMedia']);

            // Grouped questions (§6.2): one shared passage, table or diagram
            // with several sub-questions hanging off it. Authored here, and
            // rendered by the web runner, the mobile app and the offline lab
            // client alike — the grouping rules live server-side so no client
            // re-derives them.
            Route::get('/cbt/question-groups', [CbtQuestionGroupController::class, 'index']);
            Route::post('/cbt/question-groups', [CbtQuestionGroupController::class, 'store']);
            Route::get('/cbt/question-groups/{id}', [CbtQuestionGroupController::class, 'show']);
            Route::put('/cbt/question-groups/{id}', [CbtQuestionGroupController::class, 'update']);
            Route::delete('/cbt/question-groups/{id}', [CbtQuestionGroupController::class, 'destroy']);

            Route::get('/cbt/questions', [CbtController::class, 'listQuestions']);
            Route::post('/cbt/questions', [CbtController::class, 'storeQuestion']);
            Route::post('/cbt/questions/import', [CbtController::class, 'importQuestions']);
            Route::get('/cbt/questions/{id}', [CbtController::class, 'showQuestion'])->whereNumber('id');
            Route::put('/cbt/questions/{id}', [CbtController::class, 'updateQuestion']);
            Route::delete('/cbt/questions/{id}', [CbtController::class, 'destroyQuestion']);

            // Exam authoring.
            Route::get('/cbt/exams', [CbtController::class, 'listExams']);
            Route::post('/cbt/exams', [CbtController::class, 'storeExam']);
            Route::get('/cbt/exams/{id}', [CbtController::class, 'showExam']);
            Route::put('/cbt/exams/{id}', [CbtController::class, 'updateExam']);
            Route::post('/cbt/exams/{id}/questions', [CbtController::class, 'attachQuestions']);
            Route::delete('/cbt/exams/{id}/questions/{questionId}', [CbtController::class, 'detachQuestion']);
            Route::post('/cbt/exams/{id}/publish', [CbtController::class, 'publishExam']);
            Route::post('/cbt/exams/{id}/close', [CbtController::class, 'closeExam']);
            Route::get('/cbt/exams/{id}/results', [CbtController::class, 'examResults']);
            Route::post('/cbt/attempts/{attemptId}/grade', [CbtController::class, 'gradeAttempt']);
        });

        // Sitting a paper — candidates only. CbtAttemptPolicy::sit is what
        // actually binds an attempt to the student who owns it.
        Route::middleware('role:student')->group(function () {
            Route::get('/cbt/available', [CbtController::class, 'availableExams']);
            Route::post('/cbt/exams/{examId}/start', [CbtController::class, 'startAttempt']);
            Route::get('/cbt/attempts/{attemptId}', [CbtController::class, 'showAttempt']);
            Route::post('/cbt/attempts/{attemptId}/answers', [CbtController::class, 'saveAnswers']);
            Route::post('/cbt/attempts/{attemptId}/submit', [CbtController::class, 'submitAttempt']);
            Route::post('/cbt/attempts/{attemptId}/events', [CbtController::class, 'logAttemptEvent']);
        });

        // Results: candidate, guardian and staff paths all land here and are
        // separated by CbtAttemptPolicy::view plus the exam's release setting.
        Route::get('/cbt/attempts/{attemptId}/result', [CbtController::class, 'attemptResult']);

        /*
         * What to cache before exam day (gap G4). Staff get the paper's shape
         * for provisioning a lab; a candidate gets a media manifest for their
         * own paper and nothing else — no question text, no options, no
         * answers. The controller splits on role, and CbtExamPolicy::sit binds
         * the candidate to the class the paper was set for.
         */
        Route::get('/cbt/exams/{examId}/offline-package', [CbtController::class, 'offlinePackage'])
            ->middleware('role:super_admin,school_admin,teacher,student');

        // Kept as-is: the mobile app sits a paper as the candidate and syncs
        // its own single attempt. The lab relay cannot use this — it uploads
        // for a whole room while authenticated as staff — and gets the batch
        // route below instead.
        Route::post('/cbt/offline-sync', [CbtController::class, 'syncOfflineAnswers'])->middleware('role:student');

        /*
        |------------------------------------------------------------------
        | Offline lab relay (docs/offline-cbt-client.md §9.1–§9.3)
        |------------------------------------------------------------------
        | The relay is a staff-authenticated API consumer, not a candidate. It
        | touches the network exactly three times: it takes delivery of an
        | encrypted bundle the day before, collects the key on exam morning,
        | and uploads the room afterwards. Nothing here is reachable during
        | the paper, which is the entire point of the component.
        |
        | Key release is throttled and audited on every call, granted or
        | refused: an unlock is the moment a paper becomes readable on a
        | machine we do not control.
        */
        Route::middleware('role:super_admin,school_admin,teacher')->group(function () {
            Route::get('/cbt/exams/{examId}/offline-bundles', [CbtOfflineBundleController::class, 'index']);
            Route::post('/cbt/exams/{examId}/offline-bundle', [CbtOfflineBundleController::class, 'store']);
            Route::post('/cbt/offline-bundle/{bundleId}/key', [CbtOfflineBundleController::class, 'releaseKey'])
                ->middleware('throttle:10,1');
            Route::post('/cbt/offline-bundle/{bundleId}/revoke', [CbtOfflineBundleController::class, 'revoke']);
            Route::post('/cbt/offline-sync/batch', [CbtOfflineBundleController::class, 'batchSync']);
        });

        /*
        |------------------------------------------------------------------
        | Report card design templates (per school, imported)
        |------------------------------------------------------------------
        */
        // What the school rates on report cards (set at onboarding), and the
        // per-pupil ratings and comments. Teachers: their own class only.
        Route::get('/report-settings', [ReportEntryController::class, 'settings'])->middleware('role:super_admin,school_admin,teacher');
        Route::put('/report-settings', [ReportEntryController::class, 'updateSettings'])->middleware('role:super_admin,school_admin');
        Route::get('/report-entries', [ReportEntryController::class, 'index'])->middleware('role:super_admin,school_admin,teacher');
        Route::put('/report-entries', [ReportEntryController::class, 'update'])->middleware('role:super_admin,school_admin,teacher');

        // Grading scales per class level, and asking SchoolPilot to design the
        // report card. School admins only.
        Route::middleware('role:super_admin,school_admin')->group(function () {
            Route::get('/grading-scales', [GradingScaleController::class, 'index']);
            Route::put('/grading-scales/{level}', [GradingScaleController::class, 'update']);
            Route::delete('/grading-scales/{level}', [GradingScaleController::class, 'destroy']);
            Route::get('/report-cards/design-requests', [ReportCardDesignRequestController::class, 'index']);
            Route::post('/report-cards/design-requests', [ReportCardDesignRequestController::class, 'store']);
            Route::post('/report-cards/design-requests/{id}/files', [ReportCardDesignRequestController::class, 'addFiles'])->whereNumber('id');
            Route::get('/report-cards/design-requests/{id}/files/{fileId}', [ReportCardDesignRequestController::class, 'downloadFile'])->whereNumber('id')->whereNumber('fileId');
            Route::get('/report-cards/design-requests/{id}/preview', [ReportCardDesignRequestController::class, 'preview'])->whereNumber('id');
            Route::post('/report-cards/design-requests/{id}/approve', [ReportCardDesignRequestController::class, 'approve'])->whereNumber('id');
            Route::post('/report-cards/design-requests/{id}/request-changes', [ReportCardDesignRequestController::class, 'requestChanges'])->whereNumber('id');
            Route::post('/report-cards/design-requests/{id}/cancel', [ReportCardDesignRequestController::class, 'cancel'])->whereNumber('id');
        });
        Route::get('/report-cards/template-contract', [ReportCardTemplateController::class, 'contract'])->middleware('role:super_admin,school_admin');
        Route::get('/report-cards/templates', [ReportCardTemplateController::class, 'index'])->middleware('role:super_admin,school_admin');
        Route::post('/report-cards/templates', [ReportCardTemplateController::class, 'import'])->middleware('role:super_admin,school_admin');
        Route::get('/report-cards/templates/{id}', [ReportCardTemplateController::class, 'show'])->middleware('role:super_admin,school_admin');
        Route::get('/report-cards/templates/{id}/preview', [ReportCardTemplateController::class, 'preview'])->middleware('role:super_admin,school_admin');
        Route::post('/report-cards/templates/{id}/activate', [ReportCardTemplateController::class, 'activate'])->middleware('role:super_admin,school_admin');
        Route::delete('/report-cards/templates/{id}', [ReportCardTemplateController::class, 'destroy'])->middleware('role:super_admin,school_admin');
        Route::get('/report-cards/{studentId}/{termId}', [ReportCardTemplateController::class, 'renderReportCard'])->middleware('role:super_admin,school_admin,teacher');

        /*
        |------------------------------------------------------------------
        | ID cards
        |------------------------------------------------------------------
        |
        | A printable PDF, not a credential (doc §3). The design half mirrors
        | report card templates exactly — import, preview, activate — and the
        | issuance half is its own thing: a card is issued once and printed
        | many times, so those are separate verbs.
        |
        | Admin-only throughout. A teacher can see a class roster but has no
        | business minting the identity documents for it, and the print run
        | endpoints hand back a PDF of every child's face in a class.
        */
        Route::get('/id-cards/template-contract', [IdCardTemplateController::class, 'contract'])->middleware('role:super_admin,school_admin');
        Route::get('/id-cards/templates', [IdCardTemplateController::class, 'index'])->middleware('role:super_admin,school_admin');
        // Ready-made designs in the school's colours (TASK 18).
        Route::get('/id-cards/gallery', [IdCardTemplateController::class, 'gallery'])->middleware('role:super_admin,school_admin');
        Route::get('/id-cards/gallery/{key}/preview', [IdCardTemplateController::class, 'galleryPreview'])->where('key', '[a-z-]+')->middleware('role:super_admin,school_admin');
        Route::post('/id-cards/gallery/{key}', [IdCardTemplateController::class, 'galleryUse'])->where('key', '[a-z-]+')->middleware('role:super_admin,school_admin');
        // The designer: a school's own artwork with live fields placed on it.
        Route::middleware('role:super_admin,school_admin')->group(function () {
            Route::get('/id-cards/designer/fields', [IdCardTemplateController::class, 'designerFields']);
            Route::post('/id-cards/designer/backgrounds', [IdCardTemplateController::class, 'uploadBackground'])->middleware('throttle:20,1');
            Route::get('/id-cards/designer/backgrounds/{ref}', [IdCardTemplateController::class, 'background'])->where('ref', '[a-z0-9]{32}');
            Route::post('/id-cards/designer/preview', [IdCardTemplateController::class, 'designerPreview'])->middleware('throttle:120,1');
            Route::post('/id-cards/designer', [IdCardTemplateController::class, 'designerSave'])->middleware('throttle:30,1');
        });
        Route::post('/id-cards/templates', [IdCardTemplateController::class, 'import'])->middleware('role:super_admin,school_admin');
        Route::get('/id-cards/templates/{id}', [IdCardTemplateController::class, 'show'])->middleware('role:super_admin,school_admin');
        Route::get('/id-cards/templates/{id}/preview', [IdCardTemplateController::class, 'preview'])->middleware('role:super_admin,school_admin');
        Route::post('/id-cards/templates/{id}/activate', [IdCardTemplateController::class, 'activate'])->middleware('role:super_admin,school_admin');
        Route::delete('/id-cards/templates/{id}', [IdCardTemplateController::class, 'destroy'])->middleware('role:super_admin,school_admin');

        // Dry run: what a print would produce, and who it would leave out.
        Route::post('/id-cards/preflight', [IdCardController::class, 'preflight'])->middleware('role:super_admin,school_admin');

        Route::get('/id-cards/print-runs', [IdCardController::class, 'indexRuns'])->middleware('role:super_admin,school_admin');
        Route::post('/id-cards/print-runs', [IdCardController::class, 'storeRun'])->middleware('role:super_admin,school_admin');
        Route::get('/id-cards/print-runs/{id}', [IdCardController::class, 'showRun'])->middleware('role:super_admin,school_admin');
        Route::get('/id-cards/print-runs/{id}/download', [IdCardController::class, 'downloadRun'])->middleware('role:super_admin,school_admin');

        Route::get('/id-cards/holders/{holderType}/{holderId}', [IdCardController::class, 'holderCards'])->middleware('role:super_admin,school_admin');
        Route::post('/id-cards/{id}/revoke', [IdCardController::class, 'revoke'])->middleware('role:super_admin,school_admin');
        Route::post('/id-cards/{id}/replace', [IdCardController::class, 'replace'])->middleware('role:super_admin,school_admin');

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
            Route::get('/result-checker/{studentId}/{termId}/summary', [ResultCheckerController::class, 'summary']);
            Route::get('/result-checker/{studentId}/{termId}', [ResultCheckerController::class, 'show']);
            Route::get('/result-checker/my-pins', [ResultCheckerController::class, 'myPins']);
            Route::post('/result-checker/purchase', [ResultCheckerController::class, 'purchase']);
            // Tighter limiter than the shared 60/min bucket: redeem is the one
            // endpoint where guessing a code is worth something.
            Route::post('/result-checker/redeem', [ResultCheckerController::class, 'redeem'])
                ->middleware('throttle:10,1');
        });

        // School admins and directors.
        Route::middleware('role:super_admin,school_admin,bursar')->group(function () {
            Route::get('/result-pins/price-tiers', [ResultPinController::class, 'priceTiers']);
            Route::get('/result-pins/inventory', [ResultPinController::class, 'inventory']);
            Route::get('/result-pins/batches', [ResultPinController::class, 'batches']);
            Route::post('/result-pins/batches', [ResultPinController::class, 'purchaseBatch']);
            Route::get('/result-pins/batches/{id}/reveal', [ResultPinController::class, 'revealBatch']);
            Route::post('/result-pins/counter-sale', [ResultPinController::class, 'sellOverCounter']);
            Route::put('/result-pins/settings', [ResultPinController::class, 'updateSettings'])->middleware('role:super_admin,school_admin');
            Route::post('/result-pins/waive', [ResultPinController::class, 'waive']);
            Route::get('/result-pins/sales-report', [ResultPinController::class, 'salesReport']);
            // Every PIN that has left stock: sold to whom, used for which child.
            Route::get('/result-pins/ledger', [ResultPinReportController::class, 'schoolLedger']);
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
            Route::get('/results/releases', [ResultPinController::class, 'releases']);
            Route::post('/results/release', [ResultPinController::class, 'release']);
        });

        // What SchoolPilot is currently telling this school's administrators.
        Route::get('/announcements', [PlatformAnnouncementController::class, 'forSchool'])->middleware('role:school_admin,super_admin');

        // SchoolPilot platform operators only.
        Route::middleware('role:super_admin')->group(function () {
            /*
             * The operator console's own data.
             *
             * A super admin has no school, so the school-side dashboards are
             * not merely unhelpful to them — `/analytics/principal` scopes to a
             * null `school_id` and reports a platform operator an entire
             * school's worth of zeroes. These endpoints answer the operator's
             * actual questions: how many tenants, how much AI spend, which
             * school is asking for help.
             */
            Route::get('/platform/overview', [PlatformController::class, 'overview']);

            // Leads from the marketing site (Landing PRD §12).
            Route::get('/platform/leads', [PlatformLeadController::class, 'index']);
            Route::put('/platform/leads/{id}', [PlatformLeadController::class, 'update'])->whereNumber('id');
            Route::post('/platform/leads/{id}/ref', [PlatformLeadController::class, 'attachRef'])->whereNumber('id');

            // Partner programme: partners, their private rates, credited schools and leads, payouts.
            Route::get('/platform/partners', [PlatformPartnerController::class, 'index']);
            Route::post('/platform/partners', [PlatformPartnerController::class, 'store']);
            Route::get('/platform/partners/{id}', [PlatformPartnerController::class, 'show'])->whereNumber('id');
            Route::put('/platform/partners/{id}', [PlatformPartnerController::class, 'update'])->whereNumber('id');
            Route::post('/platform/partners/{id}/payouts', [PlatformPartnerController::class, 'payout'])->whereNumber('id');
            Route::put('/platform/schools/{schoolId}/partner', [PlatformPartnerController::class, 'attachSchool'])->whereNumber('schoolId');
            Route::put('/platform/leads/{leadId}/partner', [PlatformPartnerController::class, 'attachLead'])->whereNumber('leadId');

            // The marketing site's screenshots, slot by slot.
            Route::get('/platform/website/screens', [PlatformWebsiteController::class, 'screens']);
            Route::post('/platform/website/screens/{slot}', [PlatformWebsiteController::class, 'upload']);
            Route::delete('/platform/website/screens/{slot}', [PlatformWebsiteController::class, 'destroy']);

            /*
             * Platform settings and AI configuration (doc §5). Integration keys,
             * the AI spend cap and model choice used to be editable only over
             * SSH in backend.env. Secrets are write-only through these routes.
             */
            Route::get('/platform/settings', [PlatformSettingsController::class, 'index']);
            Route::put('/platform/settings', [PlatformSettingsController::class, 'update']);
            Route::get('/platform/ai-usage', [PlatformSettingsController::class, 'aiUsage']);
            // Billing: plans, term bills, paid / waived (super-admin-menu items 5-6).
            Route::get('/platform/billing', [PlatformBillingController::class, 'index']);
            Route::get('/platform/schools/{schoolId}/bills', [PlatformBillingController::class, 'history'])->whereNumber('schoolId');
            Route::put('/platform/schools/{schoolId}/billing-plan', [PlatformBillingController::class, 'setPlan'])->whereNumber('schoolId');
            Route::post('/platform/schools/{schoolId}/bills', [PlatformBillingController::class, 'issue'])->whereNumber('schoolId');
            Route::post('/platform/bills/{billId}/status', [PlatformBillingController::class, 'setStatus'])->whereNumber('billId');
            Route::get('/platform/ai/models', [PlatformSettingsController::class, 'aiModels']);
            // Each test is a real (tiny) paid call.
            Route::post('/platform/ai/test', [PlatformSettingsController::class, 'aiTest'])->middleware('throttle:10,1');
            // Per-school email on/off and daily limits; what each school sent today.
            Route::get('/platform/messaging', [PlatformMessagingController::class, 'index']);
            Route::post('/platform/schools/{schoolId}/messaging', [PlatformMessagingController::class, 'update']);
            Route::post('/platform/schools/{schoolId}/ai', [PlatformSettingsController::class, 'setSchoolAi']);
            // The migration queue an operator actually works, across schools.
            Route::get('/platform/school-migrations', [SchoolMigrationController::class, 'queue']);
            Route::put('/platform/school-migrations/{id}', [SchoolMigrationController::class, 'update']);
            Route::get('/platform/schools', [PlatformController::class, 'schools']);
            // Onboarding a school, which nothing could do before: the only
            // tenant on the platform had been inserted by hand with SQL.
            Route::post('/platform/schools', [PlatformController::class, 'store']);
            Route::get('/platform/schools/{schoolId}', [PlatformController::class, 'show']);
            Route::post('/platform/schools/{schoolId}/status', [PlatformController::class, 'setStatus']);
            // Operator-only notes on a school. No school-side route reads them.
            Route::get('/platform/schools/{schoolId}/notes', [PlatformController::class, 'notes']);
            Route::post('/platform/schools/{schoolId}/notes', [PlatformController::class, 'storeNote']);

            /*
             * Opening a school: a reason, an audit row and a time limit.
             * BindTenantFromUser honours X-Platform-School only while one of
             * these grants is live.
             */
            Route::post('/platform/schools/{schoolId}/access', [PlatformAccessController::class, 'open']);
            Route::post('/platform/access/close', [PlatformAccessController::class, 'close']);
            Route::get('/platform/access-log', [PlatformAccessController::class, 'log']);

            // Notices to school administrators — never to parents (NDPA).
            Route::get('/platform/announcements', [PlatformAnnouncementController::class, 'index']);
            Route::post('/platform/announcements', [PlatformAnnouncementController::class, 'store']);
            Route::post('/platform/announcements/{id}/withdraw', [PlatformAnnouncementController::class, 'withdraw']);

            /*
             * Platform security (doc §5): the audit trail across schools, and
             * every administrator account's 2FA and session state.
             */
            Route::get('/platform/audit-log', [PlatformSecurityController::class, 'auditLog']);
            Route::get('/platform/security/admins', [PlatformSecurityController::class, 'admins']);
            Route::post('/platform/security/admins/{userId}/revoke-sessions', [PlatformSecurityController::class, 'revokeSessions']);

            Route::get('/platform/result-pins/price-tiers', [PlatformResultPinController::class, 'listPriceTiers']);
            Route::post('/platform/result-pins/price-tiers', [PlatformResultPinController::class, 'storePriceTier']);
            // A discounted per-PIN price agreed with one school, which overrides
            // the rate card for that school on every later purchase or grant.
            Route::get('/platform/result-pins/school-rates', [PlatformResultPinController::class, 'listSchoolRates']);
            Route::post('/platform/result-pins/school-rates', [PlatformResultPinController::class, 'setSchoolRate']);
            Route::delete('/platform/result-pins/school-rates/{schoolId}', [PlatformResultPinController::class, 'removeSchoolRate']);
            Route::get('/platform/result-pins/batches', [PlatformResultPinController::class, 'batches']);
            // Issues sellable stock with no gateway involved — for schools that
            // paid by bank transfer or arranged it directly with SchoolPilot.
            Route::post('/platform/result-pins/grant', [PlatformResultPinController::class, 'grantBatch']);
            // Per school: bought, paid, sold on, used, still in stock, owed.
            // Per-school module switches (CBT, transport, hostel, …). Audited.
            Route::get('/platform/schools/{schoolId}/features', [SchoolFeatureController::class, 'platformShow'])->whereNumber('schoolId');
            Route::put('/platform/schools/{schoolId}/features', [SchoolFeatureController::class, 'platformUpdate'])->whereNumber('schoolId');
            // Backups (read-only status + recorded restore tests) and NDPA data requests.
            Route::get('/platform/backups', [PlatformComplianceController::class, 'backups']);
            Route::post('/platform/backups/restore-tests', [PlatformComplianceController::class, 'recordRestoreTest']);
            Route::get('/platform/data-requests', [PlatformComplianceController::class, 'dataRequests']);
            // Report-card design requests from schools: the queue, their files,
            // uploading the finished template. The school approves it.
            Route::get('/platform/report-card-requests', [PlatformReportCardDesignController::class, 'index']);
            Route::get('/platform/report-card-requests/{id}', [PlatformReportCardDesignController::class, 'show'])->whereNumber('id');
            Route::get('/platform/report-card-requests/{id}/files/{fileId}', [PlatformReportCardDesignController::class, 'downloadFile'])->whereNumber('id')->whereNumber('fileId');
            Route::get('/platform/report-card-requests/{id}/preview', [PlatformReportCardDesignController::class, 'preview'])->whereNumber('id');
            Route::post('/platform/report-card-requests/{id}/start', [PlatformReportCardDesignController::class, 'start'])->whereNumber('id');
            Route::post('/platform/report-card-requests/{id}/template', [PlatformReportCardDesignController::class, 'uploadTemplate'])->whereNumber('id');
            Route::put('/platform/report-card-requests/{id}/grading-scales', [PlatformReportCardDesignController::class, 'updateScales'])->whereNumber('id');
            Route::put('/platform/report-card-requests/{id}/notes', [PlatformReportCardDesignController::class, 'notes'])->whereNumber('id');
            // AI report-card remarks per school: counts only, read-only.
            Route::get('/platform/ai-remarks', [PlatformAiRemarksController::class, 'index']);
            Route::get('/platform/result-pins/sales', [ResultPinReportController::class, 'platformSummary']);
        });

        Route::post('/finance/scholarships', [Phase1And2Controller::class, 'storeScholarship'])->middleware('role:super_admin,school_admin,bursar');

        /*
         * Transport, library, hostel and clinic.
         *
         * These five features had a write endpoint each and no way to read
         * anything back, so the portal's pages for them were built on invented
         * arrays and none of what a school entered was ever visible again.
         */
        Route::get('/transport/buses', [Phase1And2Controller::class, 'buses'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/transport/buses', [Phase1And2Controller::class, 'storeBus'])->middleware('role:super_admin,school_admin');
        Route::post('/transport/bus/{busId}/gps-ping', [Phase1And2Controller::class, 'updateBusLocation'])->middleware('role:super_admin,school_admin,teacher');

        Route::get('/library/books', [Phase1And2Controller::class, 'books'])->middleware('role:super_admin,school_admin,teacher,student');
        Route::post('/library/books', [Phase1And2Controller::class, 'storeBook'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/library/scan-barcode', [Phase1And2Controller::class, 'scanBookBarcode'])->middleware('role:super_admin,school_admin,teacher,student');

        Route::get('/hostel/rooms', [Phase1And2Controller::class, 'rooms'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/hostel/rooms', [Phase1And2Controller::class, 'storeRoom'])->middleware('role:super_admin,school_admin');
        Route::get('/hostel/bed-assignments', [Phase1And2Controller::class, 'bedAssignments'])->middleware('role:super_admin,school_admin,teacher');
        Route::post('/hostel/assign-bed', [Phase1And2Controller::class, 'assignBed'])->middleware('role:super_admin,school_admin');
        Route::delete('/hostel/bed-assignments/{assignmentId}', [Phase1And2Controller::class, 'releaseBed'])->middleware('role:super_admin,school_admin');

        /*
         * The clinic log is health data about a child. Teachers can record a
         * visit — they are who walks a child to the nurse — but reading the log
         * back is restricted to the school's administrators (doc §12).
         */
        // The nurse role reaches these two routes and the student lookup, and
        // nothing else.
        Route::get('/health/clinic-visits', [Phase1And2Controller::class, 'clinicVisits'])->middleware('role:super_admin,school_admin,nurse');
        Route::post('/health/clinic-visit', [Phase1And2Controller::class, 'recordClinicVisit'])->middleware('role:super_admin,school_admin,teacher,nurse');

        /*
         * Virtual classrooms. SchoolPilot stores the schedule and the joining
         * link; the call itself runs in whatever the school already uses.
         */
        /*
         * Assisted data migration. The self-service path is the bulk CSV
         * importer; this is the staffed one the portal advertised behind a form
         * that recorded nothing.
         */
        Route::get('/school-migration', [SchoolMigrationController::class, 'index'])->middleware('role:super_admin,school_admin');
        Route::post('/school-migration', [SchoolMigrationController::class, 'store'])->middleware('role:super_admin,school_admin');
        Route::post('/school-migration/{id}/cancel', [SchoolMigrationController::class, 'cancel'])->middleware('role:super_admin,school_admin');

        Route::get('/live-classes', [LiveClassController::class, 'index']);
        Route::post('/live-classes', [LiveClassController::class, 'store'])->middleware('role:super_admin,school_admin,teacher');
        Route::put('/live-classes/{id}', [LiveClassController::class, 'update'])->middleware('role:super_admin,school_admin,teacher');
        Route::delete('/live-classes/{id}', [LiveClassController::class, 'destroy'])->middleware('role:super_admin,school_admin,teacher');

        /*
         * Who is signed in, and which school they are acting on.
         *
         * The school is included because every page's chrome needs its name and
         * had nowhere to get it: the portal's sidebar hardcoded "Greenfield
         * Academy" for every tenant, so each school saw another school's name
         * on every screen.
         *
         * `school` is the *acting* school, which for a platform operator using
         * the school picker is the tenant they are inspecting rather than their
         * own (they have none). `is_platform_operator` tells the client which of
         * those it is looking at, so the chrome can say so plainly instead of
         * presenting a borrowed school as the operator's own.
         */
        Route::get('/user', function (\Illuminate\Http\Request $request) {
            $user = $request->user()->load('userProfile');
            $tenant = app(\App\Support\TenantContext::class);
            $role = $user->userProfile?->role;

            $schoolId = $tenant->schoolId() ?? $user->userProfile?->school_id;

            $school = $schoolId
                ? $tenant->withoutScoping(fn () => \App\Models\School::find($schoolId))
                : null;

            return response()->json([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_profile' => $user->userProfile,
                'role' => $role,
                'is_platform_operator' => $role === 'super_admin',
                // An operator's live grant on the school they have open: when
                // it ends, and the reason they gave.
                'platform_access' => ($access = $request->attributes->get('platform_access')) ? [
                    'expires_at' => $access->expires_at->toIso8601String(),
                    'reason' => $access->reason,
                ] : null,
                'school' => $school ? [
                    'id' => $school->id,
                    'name' => $school->name,
                    'subdomain' => $school->subdomain,
                    'status' => $school->status,
                    'ai_enabled' => (bool) $school->ai_enabled,
                    'result_checker_enabled' => (bool) $school->result_checker_enabled,
                ] : null,
            ]);
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
    Route::get('/payments/return', [FinanceController::class, 'paymentReturn'])
        ->name('payments.return');

    // Public unauthenticated result verification route with anti-scraping rate limiting
    Route::middleware('throttle:10,1')->get('/verify-result/{token}', [AssessmentController::class, 'verifyResult']);

    /*
     * Public ID card verification — the QR printed on every card.
     *
     * Unauthenticated by necessity: the people who check cards are gatekeepers,
     * bus drivers and receptionists, none of whom have a login, and requiring
     * one would mean the card could only be verified by the school that issued
     * it. The endpoint answers one question with the minimum that answers it
     * (see IdCardController::verify), and an unknown token gets the same page
     * as a revoked one so it cannot be walked.
     *
     * A looser throttle than /verify-result because a card gets scanned at a
     * gate, repeatedly, by people who are in a hurry.
     */
    Route::middleware('throttle:20,1')->get('/verify-id/{token}', [IdCardController::class, 'verify']);

    /*
     * Transcripts and transfer certificates carry a QR pointing here. Public
     * because the person checking is usually at another school; it reveals
     * only whether the document is genuine, unaltered and still valid.
     */
    Route::middleware('throttle:20,1')->get('/verify-document/{reference}', [AcademicHistoryController::class, 'verifyDocument']);

    /*
     * A school's public application form, only when the school has switched
     * it on (off by default). Tenant from the subdomain; tight limits because
     * each submission is a family's personal data.
     */
    Route::middleware('throttle:10,1')->group(function () {
        Route::get('/public/admissions/form', [AdmissionController::class, 'publicForm']);
        Route::post('/public/admissions/apply', [AdmissionController::class, 'publicApply']);
        Route::post('/public/admissions/status', [AdmissionController::class, 'publicStatus']);
    });

    /*
     * The school's own front door: name, crest and hero for its sign-in page,
     * and — once the admin publishes — about, contact and admissions. Tenant
     * from the subdomain; institutional fields only, never a person's data.
     */
    Route::get('/public/school-profile', [SchoolProfileController::class, 'publicShow']);

    // A transfer package by its expiring secret link, for a school not on SchoolPilot.
    Route::middleware('throttle:10,1')->get('/transfer-packages/{token}', [TransferController::class, 'packageByLink']);

    // OpenAPI / Swagger Documentation Spec
    Route::get('/docs/openapi.json', [SwaggerDocController::class, 'getSpec']);

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
    ->post('/webhooks/{gateway}', [FinanceController::class, 'handleWebhook']);
