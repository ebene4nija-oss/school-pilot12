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
        $schoolId = $this->schoolId();

        $validator = Validator::make($request->all(), [
            // Someone on this school's staff, not any user on the platform.
            'staff_id'     => ['required', \Illuminate\Validation\Rule::exists('user_profiles', 'user_id')->where('school_id', $schoolId)],
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
        $schoolId = $this->schoolId();

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
        $schoolId = $this->schoolId();

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
        $schoolId = $this->schoolId();

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
        $schoolId = $this->schoolId();

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
    /*
     * Lists for the accounts screen. Vendors, expenses, petty cash and budgets
     * could be written but never read back: a bursar recorded an expense and
     * had no way to see it again, and petty cash had no running balance.
     */

    public function listVendors(Request $request)
    {
        return response()->json(['data' => Vendor::where('school_id', $this->schoolId())->orderBy('name')->get()]);
    }

    public function listExpenses(Request $request)
    {
        $schoolId = $this->schoolId();
        $validated = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'category' => 'nullable|string|max:100']);

        $query = Expense::where('school_id', $schoolId)
            ->when($validated['from'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($validated['to'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->when($validated['category'] ?? null, fn ($q, $v) => $q->where('category', $v));

        return response()->json([
            'data' => (clone $query)->with('vendor:id,name')->orderByDesc('expense_date')->orderByDesc('id')->limit(500)->get(),
            'total' => round((float) (clone $query)->where('status', 'paid')->sum('amount'), 2),
            'categories' => Expense::where('school_id', $schoolId)->distinct()->orderBy('category')->pluck('category'),
            'currency' => 'NGN',
        ]);
    }

    public function listPettyCash(Request $request)
    {
        $schoolId = $this->schoolId();
        $rows = PettyCash::where('school_id', $schoolId)->orderByDesc('entry_date')->orderByDesc('id')->limit(500)->get();
        $in = (float) PettyCash::where('school_id', $schoolId)->where('type', 'replenishment')->sum('amount');
        $out = (float) PettyCash::where('school_id', $schoolId)->where('type', 'disbursement')->sum('amount');
        $names = \App\Models\User::whereIn('id', $rows->pluck('custodian_id')->filter()->unique())->pluck('name', 'id');

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id, 'type' => $r->type, 'amount' => (float) $r->amount, 'purpose' => $r->purpose,
                'entry_date' => $r->entry_date?->toDateString(), 'custodian' => $names[$r->custodian_id] ?? null,
            ]),
            'balance' => round($in - $out, 2),
            'currency' => 'NGN',
        ]);
    }

    /** Budgets beside what has actually been spent in each category. */
    public function listBudgets(Request $request)
    {
        $schoolId = $this->schoolId();
        $budgets = Budget::where('school_id', $schoolId)->with('term:id,name,start_date,end_date')->orderByDesc('id')->get();

        return response()->json([
            'data' => $budgets->map(function (Budget $b) use ($schoolId) {
                $spent = Expense::where('school_id', $schoolId)->where('status', 'paid')->where('category', $b->category);
                if ($b->term) {
                    $spent->whereBetween('expense_date', [$b->term->start_date, $b->term->end_date]);
                } elseif (preg_match('/^\d{4}$/', (string) $b->fiscal_year)) {
                    $spent->whereYear('expense_date', (int) $b->fiscal_year);
                } elseif (preg_match('/^(\d{4})\/(\d{4})$/', (string) $b->fiscal_year, $m)) {
                    // A Nigerian school year: September to August.
                    $spent->whereBetween('expense_date', ["{$m[1]}-09-01", "{$m[2]}-08-31"]);
                }
                $spentTotal = round((float) $spent->sum('amount'), 2);

                return [
                    'id' => $b->id, 'category' => $b->category, 'fiscal_year' => $b->fiscal_year, 'term' => $b->term?->name,
                    'allocated_amount' => (float) $b->allocated_amount, 'spent' => $spentTotal,
                    'remaining' => round((float) $b->allocated_amount - $spentTotal, 2),
                ];
            })->values(),
            'currency' => 'NGN',
        ]);
    }

    public function getIncomeReport(Request $request)
    {
        $schoolId = $this->schoolId();
        // An optional period; without one, everything to date, as before.
        $range = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $from = $range['from'] ?? null;
        $to = $range['to'] ?? null;

        $totalFeeIncome = Payment::where('school_id', $schoolId)->where('status', 'successful')
            ->when($from, fn ($q) => $q->whereDate('paid_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('paid_at', '<=', $to))
            ->sum('amount');
        $totalExpenses = Expense::where('school_id', $schoolId)->where('status', 'paid')
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
            ->sum('amount');
        $totalPayroll = Payroll::where('school_id', $schoolId)->where('status', 'paid')->sum('net_salary');
        $netProfit = $totalFeeIncome - ($totalExpenses + $totalPayroll);

        $expensesByCategory = Expense::where('school_id', $schoolId)
            ->where('status', 'paid')
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
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
            'period' => ['from' => $from, 'to' => $to],
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
