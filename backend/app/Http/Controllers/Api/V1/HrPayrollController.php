<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRefusal;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Staff;
use App\Models\StaffLoan;
use App\Services\IdCard\IdCardLifecycleService;
use App\Services\Payroll\PayrollService;
use App\Support\InAppNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Staff employment lifecycle and payroll (TASK 19, 20). A bursar prepares
 * payroll; a school admin approves it and records payment. Staff read their
 * own payslips once a run is approved.
 */
class HrPayrollController extends Controller
{
    public function __construct(private PayrollService $payroll)
    {
    }

    // ==================================================================
    // Employment lifecycle (TASK 19)
    // ==================================================================

    public function setStatus(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $staff = Staff::where('school_id', $schoolId)->with('user:id,name,status')->findOrFail($id);
        $data = $request->validate([
            'status' => 'required|in:active,on_leave,suspended,separated',
            'note' => 'nullable|string|max:255',
            'separated_on' => 'required_if:status,separated|nullable|date',
            'separation_reason' => 'required_if:status,separated|nullable|in:resigned,retired,dismissed,contract_ended,deceased,other',
            'employment_type' => 'nullable|in:full_time,part_time,contract',
            'department' => 'nullable|string|max:100',
        ]);
        $from = $staff->employment_status;

        $staff->update(array_filter([
            'employment_status' => $data['status'], 'employment_type' => $data['employment_type'] ?? null, 'department' => $data['department'] ?? null,
            'separated_on' => $data['status'] === 'separated' ? $data['separated_on'] : null, 'separation_reason' => $data['status'] === 'separated' ? $data['separation_reason'] : null,
        ], fn ($v) => $v !== null) + ($data['status'] !== 'separated' ? ['separated_on' => null, 'separation_reason' => null] : []));
        DB::table('staff_status_events')->insert(['school_id' => $schoolId, 'staff_id' => $staff->id, 'from' => $from, 'to' => $data['status'], 'note' => $data['note'] ?? ($data['separation_reason'] ?? null), 'user_id' => $request->user()->id, 'created_at' => now()]);

        $handover = null;
        if ($from === 'separated' && $data['status'] !== 'separated' && $staff->user?->status === 'left') {
            // Re-engaged: they can sign in again.
            $staff->user->forceFill(['status' => 'active'])->save();
        }
        if ($data['status'] === 'separated' && $from !== 'separated') {
            // The card stops working; the duties are listed for reassignment rather than silently dropped.
            app(IdCardLifecycleService::class)->holderLeft('staff', $staff->id, $schoolId, $request->user()->id);
            if ($staff->user) {
                $staff->user->forceFill(['status' => 'left'])->save();
                $staff->user->tokens()->delete();
            }
            DB::table('hostel_staff_assignments')->where('user_id', $staff->user_id)->whereNull('ends_on')->update(['ends_on' => $data['separated_on'], 'updated_at' => now()]);
            $handover = [
                'subjects_taught' => DB::table('teacher_subjects')->where('school_id', $schoolId)->where('teacher_id', $staff->user_id)->count(),
                'class_teacher_of' => DB::table('class_teacher_assignments')->where('school_id', $schoolId)->where(fn ($q) => $q->where('form_teacher_id', $staff->user_id)->orWhere('assistant_teacher_id', $staff->user_id))->count(),
                'upcoming_virtual_classes' => DB::table('live_classes')->where('teacher_id', $staff->user_id)->where('status', 'scheduled')->where('scheduled_for', '>', now())->count(),
                'active_loans' => StaffLoan::where('staff_id', $staff->id)->where('status', 'active')->sum('balance'),
            ];
        }
        AuditLog::create(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'action' => 'hr.staff_status_changed', 'auditable_type' => Staff::class, 'auditable_id' => $staff->id, 'new_values' => ['from' => $from, 'to' => $data['status']]]);

        return response()->json(['staff' => $staff->fresh(), 'handover' => $handover, 'message' => $handover
            ? "{$staff->user?->name} is recorded as separated. Their ID card is revoked. They have moved to Left members and can no longer sign in. Reassign their classes and duties."
            : 'Status updated.']);
    }

    public function history(int $id): JsonResponse
    {
        $staff = Staff::where('school_id', $this->schoolId())->findOrFail($id);

        return response()->json([
            'status_events' => DB::table('staff_status_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')->where('e.staff_id', $staff->id)->orderBy('e.id')->get(['e.from', 'e.to', 'e.note', 'e.created_at', 'u.name as by']),
            'salary_history' => SalaryStructure::where('staff_id', $staff->id)->orderByDesc('effective_from')->get(['basic_monthly', 'components', 'effective_from', 'note']),
            'loans' => StaffLoan::where('staff_id', $staff->id)->orderByDesc('id')->get(),
        ]);
    }

    // ==================================================================
    // Pay set-up
    // ==================================================================

    public function components(): JsonResponse
    {
        return response()->json(['data' => PayComponent::where('school_id', $this->schoolId())->orderBy('kind')->orderBy('name')->get()]);
    }

    public function saveComponent(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('pay_components', 'id')->where('school_id', $schoolId)],
            'name' => ['required', 'string', 'max:100', Rule::unique('pay_components', 'name')->where('school_id', $schoolId)->ignore($request->input('id'))],
            'kind' => 'required|in:earning,deduction',
            'calc' => 'required|in:fixed,percent_of_basic,percent_of_gross',
            'default_value' => 'required|numeric|min:0|max:100000000',
            'statutory' => 'boolean', 'is_active' => 'boolean',
        ]);
        if ($data['calc'] !== 'fixed' && $data['default_value'] > 100) {
            return response()->json(['message' => 'A percentage cannot exceed 100.', 'errors' => ['default_value' => ['At most 100%.']]], 422);
        }
        if ($data['kind'] === 'earning' && $data['calc'] === 'percent_of_gross') {
            return response()->json(['message' => 'An earning cannot be a percentage of gross pay, which it is part of.', 'errors' => ['calc' => ['Use fixed or percent of basic.']]], 422);
        }
        $c = PayComponent::updateOrCreate(['id' => $data['id'] ?? null, 'school_id' => $schoolId], collect($data)->except('id')->all() + ['school_id' => $schoolId]);

        return response()->json(['component' => $c], ($data['id'] ?? null) ? 200 : 201);
    }

    public function staffPay(): JsonResponse
    {
        $schoolId = $this->schoolId();
        $periodEnd = now()->endOfMonth();
        $staff = Staff::where('school_id', $schoolId)->with('user:id,name')->orderBy('id')->get();

        return response()->json(['data' => $staff->map(function ($s) use ($periodEnd) {
            $structure = $this->payroll->structureFor($s, $periodEnd);

            return ['id' => $s->id, 'name' => $s->user?->name, 'staff_number' => $s->staff_id, 'designation' => $s->designation, 'department' => $s->department,
                'employment_status' => $s->employment_status, 'employment_type' => $s->employment_type,
                'basic_monthly' => $structure ? (float) $structure->basic_monthly : (float) $s->salary, 'structure_from' => $structure?->effective_from?->toDateString(),
                'active_loans' => (float) StaffLoan::where('staff_id', $s->id)->where('status', 'active')->sum('balance')];
        })]);
    }

    public function setSalary(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $staff = Staff::where('school_id', $schoolId)->findOrFail($id);
        $data = $request->validate([
            'basic_monthly' => 'required|numeric|min:0|max:100000000',
            'components' => 'nullable|array',
            'components.*.component_id' => ['required', Rule::exists('pay_components', 'id')->where('school_id', $schoolId)],
            'components.*.value' => 'required|numeric|min:0',
            'effective_from' => 'required|date',
            'note' => 'nullable|string|max:255',
        ]);
        $s = SalaryStructure::create($data + ['school_id' => $schoolId, 'staff_id' => $staff->id, 'set_by' => $request->user()->id]);
        AuditLog::create(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'action' => 'hr.salary_set', 'auditable_type' => Staff::class, 'auditable_id' => $staff->id, 'new_values' => ['basic_monthly' => $data['basic_monthly'], 'effective_from' => $data['effective_from']]]);

        return response()->json(['structure' => $s], 201);
    }

    public function storeLoan(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'staff_id' => ['required', Rule::exists('staff', 'id')->where('school_id', $schoolId)],
            'kind' => 'required|in:loan,advance',
            'principal' => 'required|numeric|min:1|max:100000000',
            'monthly_deduction' => 'required|numeric|min:1|lte:principal',
            'note' => 'nullable|string|max:255',
        ]);
        $loan = StaffLoan::create($data + ['school_id' => $schoolId, 'balance' => $data['principal'], 'status' => 'active', 'approved_by' => $request->user()->id]);
        AuditLog::create(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'action' => 'hr.loan_recorded', 'auditable_type' => StaffLoan::class, 'auditable_id' => $loan->id, 'new_values' => $data]);

        return response()->json(['loan' => $loan], 201);
    }

    // ==================================================================
    // Payroll runs
    // ==================================================================

    public function runs(): JsonResponse
    {
        return response()->json(['data' => PayrollRun::where('school_id', $this->schoolId())->orderByDesc('period')->orderByDesc('version')->limit(60)->get()]);
    }

    public function prepare(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        return $this->attempt(function () use ($request, $data) {
            $result = $this->payroll->prepare($this->schoolId(), $data['period'], $request->user());
            $this->audit($request, 'payroll.prepared', $result['run']->id, ['period' => $data['period'], 'staff' => $result['run']->staff_count]);

            return $result + ['message' => "Prepared for {$result['run']->staff_count} staff.".($result['skipped'] ? ' '.count($result['skipped']).' skipped: no salary set.' : '')];
        });
    }

    public function show(int $id): JsonResponse
    {
        $run = PayrollRun::where('school_id', $this->schoolId())->findOrFail($id);
        $names = Staff::whereIn('id', Payslip::where('run_id', $run->id)->pluck('staff_id'))->with('user:id,name')->get()->mapWithKeys(fn ($s) => [$s->id => $s->user?->name]);

        return response()->json(['run' => $run, 'payslips' => Payslip::where('run_id', $run->id)->get()->map(fn ($p) => $p->toArray() + ['name' => $names[$p->staff_id] ?? null])]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $run = PayrollRun::where('school_id', $this->schoolId())->findOrFail($id);
        $data = $request->validate(['self_approval_reason' => 'nullable|string|min:5|max:255']);

        return $this->attempt(function () use ($request, $run, $data) {
            $run = $this->payroll->approve($run, $request->user(), $data['self_approval_reason'] ?? null);
            $this->audit($request, 'payroll.approved', $run->id, ['period' => $run->period, 'total_net' => (string) $run->total_net, 'self_approved' => (bool) $run->self_approval_reason]);
            $users = Staff::whereIn('id', Payslip::where('run_id', $run->id)->pluck('staff_id'))->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            InAppNotifier::send($run->school_id, $users, 'payroll', 'Payslip ready', "Your payslip for {$run->period} is ready to view.");

            return ['run' => $run];
        });
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $run = PayrollRun::where('school_id', $this->schoolId())->findOrFail($id);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255']);

        return $this->attempt(function () use ($request, $run, $data) {
            $new = $this->payroll->reopen($run, $request->user(), $data['reason']);
            $this->audit($request, 'payroll.reopened', $new->id, ['period' => $run->period, 'from_version' => $run->version, 'reason' => $data['reason']]);

            return ['run' => $new, 'message' => "Version {$new->version} drafted. Version {$run->version} is kept as superseded."];
        });
    }

    public function markPaid(Request $request, int $id): JsonResponse
    {
        $run = PayrollRun::where('school_id', $this->schoolId())->findOrFail($id);
        $data = $request->validate(['payment_reference' => 'required|string|max:100', 'paid_on' => 'required|date|before_or_equal:today']);

        return $this->attempt(function () use ($request, $run, $data) {
            $run = $this->payroll->markPaid($run, $request->user(), $data['payment_reference'], Carbon::parse($data['paid_on']));
            $this->audit($request, 'payroll.paid', $run->id, ['period' => $run->period, 'reference' => $data['payment_reference']]);

            return ['run' => $run, 'message' => 'Recorded as paid and posted to Finance as a Salaries expense.'];
        });
    }

    public function export(Request $request, int $id)
    {
        $run = PayrollRun::where('school_id', $this->schoolId())->findOrFail($id);
        $names = Staff::whereIn('id', Payslip::where('run_id', $run->id)->pluck('staff_id'))->with('user:id,name')->get()->keyBy('id');
        $this->audit($request, 'payroll.exported', $run->id, ['period' => $run->period]);

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Staff no.', 'Name', 'Basic (₦)', 'Gross (₦)', 'Deductions (₦)', 'Net (₦)']);
        foreach (Payslip::where('run_id', $run->id)->get() as $p) {
            fputcsv($out, [$names[$p->staff_id]->staff_id ?? '', $names[$p->staff_id]->user->name ?? '', $p->basic, $p->gross, $p->total_deductions, $p->net]);
        }
        rewind($out);

        return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"payroll-{$run->period}-v{$run->version}.csv\""]);
    }

    // ==================================================================
    // Self-service
    // ==================================================================

    public function myPayslips(Request $request): JsonResponse
    {
        $staff = Staff::where('user_id', $request->user()->id)->first();
        if (! $staff) {
            return response()->json(['data' => []]);
        }

        // Approved or paid runs only, and the current version of each month.
        return response()->json(['data' => Payslip::where('staff_id', $staff->id)->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.run_id')
            ->whereIn('payroll_runs.status', ['approved', 'paid'])->orderByDesc('payroll_runs.period')
            ->get(['payslips.*', 'payroll_runs.period', 'payroll_runs.status as run_status', 'payroll_runs.paid_at'])]);
    }

    private function attempt(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    private function audit(Request $request, string $action, int $id, array $values): void
    {
        AuditLog::create(['school_id' => $this->schoolId(), 'user_id' => $request->user()->id, 'action' => $action, 'auditable_type' => PayrollRun::class, 'auditable_id' => $id, 'new_values' => $values]);
    }
}
