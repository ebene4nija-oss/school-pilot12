<?php

/*
 * Staff employment lifecycle and payroll (TASK 19, 20). A bursar prepares
 * payroll; a school admin approves it, records payment and changes a staff
 * member's employment status. Staff read their own payslips.
 */

use App\Http\Controllers\Api\V1\HrPayrollController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:school_admin,bursar')->group(function () {
    Route::get('/hr/pay-components', [HrPayrollController::class, 'components']);
    Route::post('/hr/pay-components', [HrPayrollController::class, 'saveComponent']);
    Route::get('/hr/staff-pay', [HrPayrollController::class, 'staffPay']);
    Route::post('/hr/staff/{id}/salary', [HrPayrollController::class, 'setSalary'])->whereNumber('id');
    Route::get('/hr/staff/{id}/history', [HrPayrollController::class, 'history'])->whereNumber('id');
    Route::post('/hr/loans', [HrPayrollController::class, 'storeLoan']);
    Route::get('/payroll/runs', [HrPayrollController::class, 'runs']);
    Route::post('/payroll/runs', [HrPayrollController::class, 'prepare']);
    Route::get('/payroll/runs/{id}', [HrPayrollController::class, 'show'])->whereNumber('id');
    Route::get('/payroll/runs/{id}/export', [HrPayrollController::class, 'export'])->whereNumber('id')->middleware('throttle:20,1');
});

Route::middleware('role:school_admin')->group(function () {
    Route::put('/hr/staff/{id}/status', [HrPayrollController::class, 'setStatus'])->whereNumber('id');
    Route::post('/payroll/runs/{id}/approve', [HrPayrollController::class, 'approve'])->whereNumber('id');
    Route::post('/payroll/runs/{id}/reopen', [HrPayrollController::class, 'reopen'])->whereNumber('id');
    Route::post('/payroll/runs/{id}/paid', [HrPayrollController::class, 'markPaid'])->whereNumber('id');
});

Route::get('/me/payslips', [HrPayrollController::class, 'myPayslips'])->middleware('role:school_admin,teacher,nurse,bursar');
