<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentInstallment;
use App\Models\Scholarship;
use App\Models\Student;
use App\Services\FeeReminderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Chasing unpaid fees — the part of §7.12 that was missing.
 *
 * The finance module could take money (cash, bank transfer, Paystack,
 * Flutterwave) but could not tell a bursar who had not paid. That is the one
 * question a Nigerian school bursar asks the software every single week, and
 * there was no endpoint for it, so schools were keeping the answer in a
 * parallel notebook.
 *
 * Also here: installment plans and discounts, both of which had tables and no
 * code.
 */
class FeeCollectionController extends Controller
{
    private function schoolId(Request $request): ?int
    {
        return $request->user()?->userProfile?->school_id;
    }

    /**
     * The debtor list.
     *
     * Aged into buckets because "owes ₦40,000" and "owes ₦40,000 and has done
     * for 90 days" are different conversations. Ordered by how overdue, not by
     * size — the oldest debts are the ones that never get paid.
     */
    public function defaulters(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $request->validate([
            'term_id' => ['nullable', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'min_balance' => 'nullable|numeric|min:0',
        ]);

        $invoices = $this->outstandingInvoices($request, $schoolId);

        $minBalance = (float) $request->input('min_balance', 0);
        $today = now();

        $rows = $invoices
            ->map(function (Invoice $invoice) use ($today) {
                $balance = $invoice->balance();

                $daysOverdue = $invoice->due_date
                    ? max(0, $invoice->due_date->diffInDays($today, false))
                    : 0;

                /*
                 * Who a reminder would reach — names only, never phone numbers.
                 *
                 * The bursar's workflow is "chase this family", and the reminder
                 * endpoint does the contacting server-side, so the numbers have
                 * no reason to be shipped to every browser session that opens
                 * the debtor list. NDPA data minimisation (§12): an admin who
                 * genuinely needs to dial can open the guardian's profile.
                 */
                $guardians = $invoice->student
                    ? $invoice->student->guardians->pluck('user')->filter()
                    : collect();

                $contacts = $guardians->isNotEmpty()
                    ? $guardians
                    : collect([$invoice->student?->user])->filter();

                return [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'student_id' => $invoice->student_id,
                    'student_name' => $invoice->student?->user?->name,
                    'admission_number' => $invoice->student?->admission_number,
                    'class' => $invoice->student?->currentClass?->name,
                    'term' => $invoice->term?->name,
                    'total_amount' => (float) $invoice->total_amount,
                    'amount_paid' => (float) $invoice->amount_paid,
                    'balance' => $balance,
                    'due_date' => $invoice->due_date?->toDateString(),
                    'days_overdue' => (int) $daysOverdue,
                    'ageing_bucket' => $this->ageingBucket((int) $daysOverdue),
                    'contacts' => $contacts->map(fn ($user) => $user->name)->values(),
                    'contactable' => $contacts->contains(fn ($user) => filled($user->userProfile?->phone)),
                ];
            })
            ->filter(fn ($row) => $row['balance'] > $minBalance)
            ->sortByDesc('days_overdue')
            ->values();

        return response()->json([
            'summary' => [
                'defaulting_students' => $rows->pluck('student_id')->unique()->count(),
                'invoices_outstanding' => $rows->count(),
                'total_outstanding' => round($rows->sum('balance'), 2),
                'currency' => 'NGN',
                'by_ageing' => $rows->groupBy('ageing_bucket')->map(fn ($group) => [
                    'invoices' => $group->count(),
                    'amount' => round($group->sum('balance'), 2),
                ]),
            ],
            'defaulters' => $rows,
        ]);
    }

    /**
     * The outstanding-invoice query behind both the dashboard and the reminder
     * sweep, so the bursar cannot be shown one set of debtors and message
     * another.
     *
     * @return \Illuminate\Support\Collection<int,Invoice>
     */
    private function outstandingInvoices(Request $request, ?int $schoolId)
    {
        $invoices = Invoice::where('school_id', $schoolId)
            ->whereIn('status', ['unpaid', 'partial'])
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->whereRaw('total_amount > amount_paid')
            ->with([
                'student.user:id,name',
                'student.currentClass:id,name',
                // Guardian contacts, for "who would this reminder reach".
                'student.guardians.user.userProfile',
                'term:id,name',
            ])
            ->get();

        if ($request->filled('class_id')) {
            $classId = $request->integer('class_id');
            $invoices = $invoices->filter(fn (Invoice $i) => (int) $i->student?->class_id === $classId);
        }

        return $invoices;
    }

    /**
     * Send the debtor list a reminder.
     *
     * The web UI has had a "Send SMS Reminder" button since the finance page was
     * built; it was wired to nothing. This is what it calls.
     *
     * Either tick specific invoices (`invoice_ids`) or sweep the same filters
     * the dashboard uses. Grouping and message composition live in
     * FeeReminderService — notably, one parent with three children owing gets a
     * single message, because SMS is billed per message.
     */
    public function remindDefaulters(Request $request, FeeReminderService $reminders)
    {
        $schoolId = $this->schoolId($request);

        $validated = $request->validate([
            'invoice_ids' => 'nullable|array|min:1|max:1000',
            'invoice_ids.*' => 'integer',
            'term_id' => ['nullable', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'min_balance' => 'nullable|numeric|min:0',
            // Never defaulted: push is free, SMS is not.
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:push,sms,whatsapp',
            'note' => 'nullable|string|max:300',
        ]);

        $invoices = $this->outstandingInvoices($request, $schoolId);

        if (! empty($validated['invoice_ids'])) {
            $wanted = array_map('intval', $validated['invoice_ids']);
            // Intersected against the scoped query rather than fetched by id —
            // a bursar cannot chase another school's invoice, or one already
            // settled between loading the page and pressing send.
            $invoices = $invoices->filter(fn (Invoice $i) => in_array((int) $i->id, $wanted, true));
        }

        $minBalance = (float) $request->input('min_balance', 0);
        $invoices = $invoices->filter(fn (Invoice $i) => $i->balance() > $minBalance)->values();

        if ($invoices->isEmpty()) {
            return response()->json([
                'message' => 'No outstanding invoices match that selection. Nothing was sent.',
                'reminders_queued' => 0,
            ], 422);
        }

        $result = $reminders->remind(
            $invoices,
            $validated['channels'],
            $schoolId,
            $request->user(),
            $validated['note'] ?? null
        );

        /*
         * Anchored to the school, not to an invoice: a sweep spans many bills,
         * and `auditable_id` is NOT NULL. The invoice ids go in the payload so
         * the trail still answers "who did we chase, on what, and how".
         */
        \App\Models\AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'finance.reminders_sent',
            'auditable_type' => \App\Models\School::class,
            'auditable_id' => $schoolId,
            'old_values' => null,
            'new_values' => [
                'invoices' => $invoices->count(),
                'invoice_ids' => $invoices->pluck('id')->all(),
                'recipients' => $result['reminders_queued'],
                'channels' => $validated['channels'],
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json($result, 202);
    }

    private function ageingBucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'not_yet_due',
            $daysOverdue <= 30 => '1-30_days',
            $daysOverdue <= 60 => '31-60_days',
            $daysOverdue <= 90 => '61-90_days',
            default => 'over_90_days',
        };
    }

    /**
     * Break an invoice into agreed part-payments.
     *
     * Replaces any existing unpaid plan rather than adding to it — a
     * renegotiated arrangement supersedes the old one, and leaving both would
     * double-count what the family owes. Amounts already paid are untouched.
     */
    public function createInstallmentPlan(Request $request, $invoiceId)
    {
        $schoolId = $this->schoolId($request);
        $invoice = Invoice::where('school_id', $schoolId)->findOrFail($invoiceId);

        $validated = $request->validate([
            'installments' => 'required|array|min:2|max:12',
            'installments.*.amount' => 'required|numeric|min:1',
            'installments.*.due_date' => 'required|date',
        ]);

        $planTotal = round(collect($validated['installments'])->sum('amount'), 2);
        $balance = $invoice->balance();

        // A plan that does not add up to what is owed is a plan that will end
        // in an argument at the school gate.
        if (abs($planTotal - $balance) > 0.01) {
            return response()->json([
                'errors' => [
                    'installments' => [
                        "The instalments total ₦{$planTotal} but ₦{$balance} is outstanding on this invoice.",
                    ],
                ],
            ], 422);
        }

        $plan = DB::transaction(function () use ($invoice, $validated) {
            PaymentInstallment::where('invoice_id', $invoice->id)
                ->where('status', '!=', 'paid')
                ->delete();

            $created = [];
            foreach ($validated['installments'] as $row) {
                $created[] = PaymentInstallment::create([
                    'invoice_id' => $invoice->id,
                    'amount' => $row['amount'],
                    'due_date' => $row['due_date'],
                    'status' => 'pending',
                ]);
            }

            return $created;
        });

        return response()->json([
            'message' => 'Instalment plan agreed.',
            'invoice_id' => $invoice->id,
            'installments' => $plan,
        ], 201);
    }

    /** The plan on an invoice, with overdue tranches flagged against today. */
    public function installmentPlan(Request $request, $invoiceId)
    {
        $schoolId = $this->schoolId($request);
        $invoice = Invoice::where('school_id', $schoolId)->findOrFail($invoiceId);

        $installments = PaymentInstallment::where('invoice_id', $invoice->id)
            ->orderBy('due_date')
            ->get()
            ->map(fn (PaymentInstallment $i) => [
                'id' => $i->id,
                'amount' => (float) $i->amount,
                'due_date' => $i->due_date?->toDateString(),
                // Derived, not stored: a tranche becomes overdue by the passage
                // of time, and nothing runs nightly to write that down.
                'status' => $i->isOverdue() ? 'overdue' : $i->status,
            ]);

        return response()->json([
            'invoice_id' => $invoice->id,
            'invoice_balance' => $invoice->balance(),
            'installments' => $installments,
            'overdue_count' => $installments->where('status', 'overdue')->count(),
        ]);
    }

    /** Discounts and scholarships a school offers. */
    public function listScholarships(Request $request)
    {
        return response()->json([
            'data' => Scholarship::where('school_id', $this->schoolId($request))->orderBy('name')->get(),
        ]);
    }

    /**
     * Apply a discount to an invoice.
     *
     * Reduces `total_amount` and re-derives status, so a bursary that covers
     * the remaining balance settles the invoice outright rather than leaving a
     * paid-in-full invoice sitting in the defaulter list.
     */
    public function applyDiscount(Request $request, $invoiceId)
    {
        $schoolId = $this->schoolId($request);

        $validated = $request->validate([
            'scholarship_id' => ['required', Rule::exists('scholarships', 'id')->where('school_id', $schoolId)],
            'reason' => 'nullable|string|max:255',
        ]);

        $invoice = Invoice::where('school_id', $schoolId)->findOrFail($invoiceId);
        $scholarship = Scholarship::where('school_id', $schoolId)->findOrFail($validated['scholarship_id']);

        $discount = $scholarship->discountOn((float) $invoice->total_amount);

        $updated = DB::transaction(function () use ($request, $invoice, $discount, $scholarship, $validated) {
            $before = ['total_amount' => (float) $invoice->total_amount, 'status' => $invoice->status];

            $newTotal = round((float) $invoice->total_amount - $discount, 2);
            $paid = (float) $invoice->amount_paid;

            $invoice->update([
                'total_amount' => $newTotal,
                'status' => $paid <= 0
                    ? ($newTotal <= 0 ? 'paid' : 'unpaid')
                    : ($paid >= $newTotal ? 'paid' : 'partial'),
            ]);

            // Money coming off an invoice needs a trail — this is the record a
            // proprietor asks about when the term's takings look light.
            \App\Models\AuditLog::create([
                'school_id' => $invoice->school_id,
                'user_id' => $request->user()->id,
                'action' => 'invoice.discount_applied',
                'auditable_type' => Invoice::class,
                'auditable_id' => $invoice->id,
                'old_values' => $before,
                'new_values' => [
                    'total_amount' => $newTotal,
                    'status' => $invoice->status,
                    'scholarship' => $scholarship->name,
                    'discount_applied' => $discount,
                    'reason' => $validated['reason'] ?? null,
                ],
                'ip_address' => $request->ip(),
            ]);

            return $invoice->fresh();
        });

        return response()->json([
            'message' => 'Discount applied.',
            'discount_applied' => $discount,
            'currency' => 'NGN',
            'invoice' => $updated,
        ]);
    }

    /** One family's position across every invoice — what a parent sees. */
    public function studentStatement(Request $request, $studentId)
    {
        $schoolId = $this->schoolId($request);
        $student = Student::where('school_id', $schoolId)->findOrFail($studentId);

        $this->authorize('view', $student);

        $invoices = Invoice::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->with('term:id,name')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'student_id' => $student->id,
            'total_invoiced' => round((float) $invoices->sum('total_amount'), 2),
            'total_paid' => round((float) $invoices->sum('amount_paid'), 2),
            'balance' => round($invoices->sum(fn (Invoice $i) => $i->balance()), 2),
            'currency' => 'NGN',
            'invoices' => $invoices->map(fn (Invoice $i) => [
                'invoice_id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'term' => $i->term?->name,
                'total_amount' => (float) $i->total_amount,
                'amount_paid' => (float) $i->amount_paid,
                'balance' => $i->balance(),
                'status' => $i->status,
                'due_date' => $i->due_date?->toDateString(),
            ]),
        ]);
    }
}
