<?php

namespace App\Services\Payroll;

use App\Exceptions\DomainRefusal;
use App\Models\Expense;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Staff;
use App\Models\StaffLoan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly payroll (TASK 20): prepare from each staff member's salary
 * structure, approve, record payment. Every run is versioned; correcting an
 * approved run makes a new version and keeps the old one. Amounts are
 * Naira, rounded to kobo at each line.
 */
class PayrollService
{
    /** The structure in force at the end of the period. */
    public function structureFor(Staff $staff, Carbon $periodEnd): ?SalaryStructure
    {
        return SalaryStructure::withoutGlobalScopes()->where('staff_id', $staff->id)->whereDate('effective_from', '<=', $periodEnd)
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /**
     * One staff member's pay for a period, or null with a reason if they
     * cannot be paid from their record.
     *
     * @return array{basic: float, earnings: array, deductions: array, gross: float, total_deductions: float, net: float, leave_days: int}|string
     */
    public function compute(Staff $staff, Carbon $start, Carbon $end, $components): array|string
    {
        $structure = $this->structureFor($staff, $end);
        $basic = $structure ? (float) $structure->basic_monthly : (float) $staff->salary;
        if ($basic <= 0) {
            return 'No salary set';
        }
        $values = collect($structure?->components ?? [])->keyBy('component_id');

        $earnings = [];
        foreach ($components->where('kind', 'earning') as $c) {
            $v = (float) ($values[$c->id]['value'] ?? ($structure ? null : $c->default_value)) ?: 0;
            $amount = $c->calc === 'fixed' ? $v : round($basic * $v / 100, 2);
            if ($amount > 0) {
                $earnings[] = ['name' => $c->name, 'amount' => round($amount, 2)];
            }
        }
        $gross = round($basic + array_sum(array_column($earnings, 'amount')), 2);

        $deductions = [];
        foreach ($components->where('kind', 'deduction') as $c) {
            $v = (float) ($values[$c->id]['value'] ?? ($structure ? null : $c->default_value)) ?: 0;
            $amount = match ($c->calc) {
                'percent_of_basic' => round($basic * $v / 100, 2),
                'percent_of_gross' => round($gross * $v / 100, 2),
                default => $v,
            };
            if ($amount > 0) {
                $deductions[] = ['name' => $c->name, 'amount' => round($amount, 2), 'statutory' => (bool) $c->statutory];
            }
        }
        foreach (StaffLoan::withoutGlobalScopes()->where('staff_id', $staff->id)->where('status', 'active')->get() as $loan) {
            $amount = round(min((float) $loan->monthly_deduction, (float) $loan->balance), 2);
            if ($amount > 0) {
                $deductions[] = ['name' => ucfirst($loan->kind).' repayment', 'amount' => $amount, 'loan_id' => $loan->id];
            }
        }
        $total = round(array_sum(array_column($deductions, 'amount')), 2);

        $leaveDays = DB::table('leave_requests')->where('staff_id', $staff->id)->where('status', 'approved')
            ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->get(['start_date', 'end_date'])
            ->sum(fn ($l) => Carbon::parse(max($l->start_date, $start->toDateString()))->diffInDays(Carbon::parse(min($l->end_date, $end->toDateString()))) + 1);

        return ['basic' => $basic, 'earnings' => $earnings, 'deductions' => $deductions, 'gross' => $gross, 'total_deductions' => $total, 'net' => round($gross - $total, 2), 'leave_days' => (int) $leaveDays];
    }

    /**
     * Prepare (or re-prepare a draft of) a month's payroll.
     *
     * @return array{run: PayrollRun, skipped: array}
     */
    public function prepare(int $schoolId, string $period, User $by, ?PayrollRun $supersedes = null): array
    {
        $start = Carbon::createFromFormat('Y-m-d', "{$period}-01")->startOfDay();
        $end = $start->copy()->endOfMonth();
        $current = PayrollRun::withoutGlobalScopes()->where('school_id', $schoolId)->where('period', $period)->where('status', '!=', 'superseded')->orderByDesc('version')->first();

        if ($current && ! $supersedes && $current->status !== 'draft') {
            throw new DomainRefusal("The {$period} payroll is already {$current->status}. Reopen it to make a correction.");
        }

        return DB::transaction(function () use ($schoolId, $period, $by, $start, $end, $current, $supersedes) {
            $run = $supersedes ? null : $current;
            if ($run) {
                Payslip::withoutGlobalScopes()->where('run_id', $run->id)->delete();
            } else {
                $run = PayrollRun::create([
                    'school_id' => $schoolId, 'period' => $period, 'status' => 'draft', 'prepared_by' => $by->id,
                    'version' => ($supersedes?->version ?? 0) + 1, 'supersedes_id' => $supersedes?->id,
                ]);
            }

            $components = PayComponent::withoutGlobalScopes()->where('school_id', $schoolId)->where('is_active', true)->get();
            // Paid while employed in the period: not before they joined, not after they left.
            $staff = Staff::withoutGlobalScopes()->where('school_id', $schoolId)->with('user:id,name')
                ->where(fn ($q) => $q->whereNull('employment_date')->orWhereDate('employment_date', '<=', $end))
                ->where(fn ($q) => $q->where('employment_status', '!=', 'separated')->orWhereDate('separated_on', '>=', $start))->get();

            $skipped = [];
            foreach ($staff as $s) {
                $pay = $this->compute($s, $start, $end, $components);
                if (is_string($pay)) {
                    $skipped[] = ['staff_id' => $s->id, 'name' => $s->user?->name, 'reason' => $pay];
                    continue;
                }
                Payslip::create(['school_id' => $schoolId, 'run_id' => $run->id, 'staff_id' => $s->id] + $pay);
            }

            $slips = Payslip::withoutGlobalScopes()->where('run_id', $run->id)->get();
            $run->update(['total_gross' => $slips->sum('gross'), 'total_deductions' => $slips->sum('total_deductions'), 'total_net' => $slips->sum('net'), 'staff_count' => $slips->count(), 'prepared_by' => $by->id]);

            return ['run' => $run->fresh(), 'skipped' => $skipped];
        });
    }

    /** Approval locks the run. Approving your own run needs a recorded reason. */
    public function approve(PayrollRun $run, User $by, ?string $selfReason): PayrollRun
    {
        if ($run->status !== 'draft') {
            throw new DomainRefusal('Only a draft payroll can be approved.');
        }
        if ((int) $run->prepared_by === $by->id && ! $selfReason) {
            throw new DomainRefusal('You prepared this payroll. Ask another administrator to approve it, or record why you are approving your own.');
        }
        if (Payslip::withoutGlobalScopes()->where('run_id', $run->id)->where('net', '<', 0)->exists()) {
            throw new DomainRefusal('A payslip has deductions larger than pay. Fix it before approving.');
        }
        $run->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), 'self_approval_reason' => (int) $run->prepared_by === $by->id ? $selfReason : null]);

        return $run->fresh();
    }

    /** A correction to an approved run is a new version; the old one is kept. */
    public function reopen(PayrollRun $run, User $by, string $reason): PayrollRun
    {
        if ($run->status !== 'approved') {
            throw new DomainRefusal($run->status === 'paid' ? 'This payroll has been paid. Correct it in next month\'s payroll.' : 'Only an approved payroll can be reopened.');
        }

        return DB::transaction(function () use ($run, $by, $reason) {
            $run->update(['status' => 'superseded']);
            $new = $this->prepare((int) $run->school_id, $run->period, $by, $run)['run'];
            $new->update(['reopen_reason' => $reason]);

            return $new->fresh();
        });
    }

    /**
     * The school's bank has paid it. Loan balances come down, and the
     * salary bill is posted to Finance as one expense.
     */
    public function markPaid(PayrollRun $run, User $by, string $reference, Carbon $paidOn): PayrollRun
    {
        if ($run->status !== 'approved') {
            throw new DomainRefusal('Only an approved payroll can be marked paid.');
        }

        return DB::transaction(function () use ($run, $reference, $paidOn) {
            foreach (Payslip::withoutGlobalScopes()->where('run_id', $run->id)->get() as $slip) {
                foreach ($slip->deductions as $d) {
                    if (! empty($d['loan_id']) && $loan = StaffLoan::withoutGlobalScopes()->find($d['loan_id'])) {
                        $balance = max(0, round((float) $loan->balance - (float) $d['amount'], 2));
                        $loan->update(['balance' => $balance, 'status' => $balance <= 0 ? 'settled' : $loan->status]);
                    }
                }
            }
            Expense::create([
                'school_id' => $run->school_id, 'category' => 'Salaries', 'title' => "Salaries {$run->period}".($run->version > 1 ? " (v{$run->version})" : ''),
                'amount' => $run->total_net, 'expense_date' => $paidOn->toDateString(), 'payment_method' => 'bank_transfer', 'status' => 'paid',
                'notes' => "Payroll run {$run->id}, reference {$reference}. Statutory deductions withheld: ₦".number_format((float) $run->total_deductions, 2).'.',
            ]);
            $run->update(['status' => 'paid', 'paid_at' => $paidOn, 'payment_reference' => $reference]);

            return $run->fresh();
        });
    }
}
