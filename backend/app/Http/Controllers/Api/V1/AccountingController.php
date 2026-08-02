<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\PettyCash;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AccountingController extends Controller
{
    /**
     * Process Staff Payroll Batch / Disburse Salary
     */
    public function processPayroll(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

        $validator = Validator::make($request->all(), [
            'staff_id'     => ['required', \Illuminate\Validation\Rule::exists('users', 'id')],
            'month_year'   => 'required|string',
            'basic_salary' => 'required|numeric|min:0',
            'allowances'   => 'nullable|numeric|min:0',
            'deductions'   => 'nullable|numeric|min:0',
            'status'       => 'nullable|in:draft,approved,paid',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $basic = $request->basic_salary;
        $allowances = $request->get('allowances', 0);
        $deductions = $request->get('deductions', 0);
        $netSalary = $basic + $allowances - $deductions;
        $status = $request->get('status', 'approved');

        $payroll = Payroll::create([
            'school_id' => $schoolId,
            'staff_id' => $request->staff_id,
            'month_year' => $request->month_year,
            'basic_salary' => $basic,
            'allowances' => $allowances,
            'deductions' => $deductions,
            'net_salary' => $netSalary,
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
        ]);

        return response()->json([
            'message' => 'Staff payroll processed successfully',
            'payroll' => $payroll->load('staff'),
        ], 201);
    }

    /**
     * Manage Vendors & Suppliers
     */
    public function storeVendor(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

        $validator = Validator::make($request->all(), [
            'name'           => 'required|string|max:255',
            'company_name'   => 'nullable|string|max:255',
            'phone'          => 'nullable|string',
            'email'          => 'nullable|email',
            'bank_name'      => 'nullable|string',
            'account_number' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $vendor = Vendor::create(array_merge($validator->validated(), ['school_id' => $schoolId]));

        return response()->json([
            'message' => 'Vendor registered successfully',
            'vendor' => $vendor,
        ], 201);
    }

    /**
     * Record Expense / Vendor Payment
     */
    public function recordExpense(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

        $validator = Validator::make($request->all(), [
            'vendor_id'      => ['nullable', \Illuminate\Validation\Rule::exists('vendors', 'id')->where('school_id', $schoolId)],
            'category'       => 'required|string',
            'title'          => 'required|string',
            'amount'         => 'required|numeric|min:0.01',
            'expense_date'   => 'required|date',
            'payment_method' => 'required|in:cash,bank_transfer,cheque,petty_cash',
            'notes'          => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $expense = Expense::create(array_merge($validator->validated(), [
            'school_id' => $schoolId,
            'status' => 'paid',
        ]));

        return response()->json([
            'message' => 'Expense recorded successfully',
            'expense' => $expense->load('vendor'),
        ], 201);
    }

    /**
     * Log Petty Cash Disbursement / Replenishment
     */
    public function logPettyCash(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

        $validator = Validator::make($request->all(), [
            'type'       => 'required|in:disbursement,replenishment',
            'amount'     => 'required|numeric|min:0.01',
            'purpose'    => 'required|string',
            'entry_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $pettyCash = PettyCash::create([
            'school_id' => $schoolId,
            'custodian_id' => $request->user()->id,
            'type' => $request->type,
            'amount' => $request->amount,
            'purpose' => $request->purpose,
            'entry_date' => $request->entry_date,
        ]);

        return response()->json([
            'message' => 'Petty cash transaction logged successfully',
            'petty_cash' => $pettyCash,
        ], 201);
    }

    /**
     * Set School Category Budget
     */
    public function storeBudget(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

        $validator = Validator::make($request->all(), [
            'term_id'          => ['nullable', \Illuminate\Validation\Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'category'         => 'required|string',
            'allocated_amount' => 'required|numeric|min:0',
            'fiscal_year'      => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $budget = Budget::create(array_merge($validator->validated(), ['school_id' => $schoolId]));

        return response()->json([
            'message' => 'Budget allocated successfully',
            'budget' => $budget,
        ], 201);
    }

    /**
     * Income, Expense & Profitability Financial Statement Report
     */
    public function getIncomeReport(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

        $totalFeeIncome = Payment::where('school_id', $schoolId)->where('status', 'successful')->sum('amount');
        $totalExpenses = Expense::where('school_id', $schoolId)->where('status', 'paid')->sum('amount');
        $totalPayroll = Payroll::where('school_id', $schoolId)->where('status', 'paid')->sum('net_salary');
        $netProfit = $totalFeeIncome - ($totalExpenses + $totalPayroll);

        $expensesByCategory = Expense::where('school_id', $schoolId)
            ->where('status', 'paid')
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->get();

        return response()->json([
            'financial_statement' => [
                'total_fee_income' => round($totalFeeIncome, 2),
                'total_operating_expenses' => round($totalExpenses, 2),
                'total_payroll_disbursed' => round($totalPayroll, 2),
                'net_profit_or_loss' => round($netProfit, 2),
                'currency' => 'NGN',
            ],
            'expense_breakdown' => $expensesByCategory,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
