<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\Term;
use App\Models\TermBill;
use App\Services\PartnerCommissions;
use App\Services\SchoolBilling;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Billing (super-admin-menu items 5 and 6): which plan each school is on,
 * what it owes for its current term, and the bills SchoolPilot has issued.
 *
 * SchoolPilot invoices and collects outside the product (bank transfer,
 * Paystack link); this records the bill and whether it was paid or waived.
 * Every change is audited as `platform.billing.*`.
 */
class PlatformBillingController extends Controller
{
    public function __construct(private TenantContext $tenant, private SchoolBilling $billing)
    {
    }

    public function index()
    {
        return $this->tenant->withoutScoping(function () {
            $schools = School::query()->tenants()->orderBy('name')->get();

            $rows = $schools->map(function (School $school) {
                $term = $this->currentTerm($school->id);

                return [
                    'school_id' => $school->id,
                    'school' => $school->name,
                    'subdomain' => $school->subdomain,
                    'status' => $school->status,
                    'plan' => $school->billing_plan,
                    'trial_ends_on' => $school->trial_ends_on?->toDateString(),
                    'term' => $term ? ['id' => $term->id, 'name' => $term->name, 'start_date' => $term->start_date?->toDateString(), 'end_date' => $term->end_date?->toDateString()] : null,
                    'statement' => $term ? $this->naira($this->billing->statement($school, $term)) : null,
                ];
            });

            $bills = TermBill::query()->selectRaw('status, COUNT(*) as bills, SUM(amount_kobo) as total')->groupBy('status')->get()->keyBy('status');

            return response()->json([
                'plans' => SchoolBilling::PLANS,
                'rates' => [
                    'results' => SchoolBilling::rateKobo('results') / 100,
                    'subscription' => SchoolBilling::rateKobo('subscription') / 100,
                    'boarding' => SchoolBilling::boardingRateKobo() / 100,
                    'trial_days' => (int) config('services.billing.trial_days'),
                ],
                'totals' => collect(['issued', 'paid', 'waived'])->mapWithKeys(fn ($s) => [$s => [
                    'bills' => (int) ($bills[$s]->bills ?? 0),
                    'naira' => round(((int) ($bills[$s]->total ?? 0)) / 100, 2),
                ]]),
                'data' => $rows->values(),
            ]);
        });
    }

    /** A school's bill history, newest first. */
    public function history(int $schoolId)
    {
        return $this->tenant->withoutScoping(function () use ($schoolId) {
            School::query()->tenants()->findOrFail($schoolId);

            $bills = TermBill::with('term:id,name,start_date')->where('school_id', $schoolId)->latest('id')->get();

            return response()->json(['data' => $bills->map(fn (TermBill $b) => [
                'id' => $b->id,
                'term' => $b->term?->name,
                'plan' => $b->plan,
                'active_students' => $b->active_students,
                'boarding_students' => $b->boarding_students,
                'rate_naira' => $b->rate_kobo / 100,
                'boarding_rate_naira' => $b->boarding_rate_kobo / 100,
                'amount_naira' => $b->amount_kobo / 100,
                'status' => $b->status,
                'issued_at' => $b->created_at?->toIso8601String(),
                'paid_at' => $b->paid_at?->toIso8601String(),
                'note' => $b->note,
            ])]);
        });
    }

    /**
     * Put a school on a plan. The subscription's free trial starts the first
     * time a school is put on it, and is never restarted by switching back.
     */
    public function setPlan(Request $request, int $schoolId)
    {
        $validated = $request->validate([
            'plan' => ['present', 'nullable', Rule::in(array_keys(SchoolBilling::PLANS))],
        ]);

        return $this->tenant->withoutScoping(function () use ($request, $schoolId, $validated) {
            $school = School::query()->tenants()->findOrFail($schoolId);
            $before = ['plan' => $school->billing_plan, 'trial_ends_on' => $school->trial_ends_on?->toDateString()];

            $school->billing_plan = $validated['plan'];

            if ($validated['plan'] === 'subscription' && $school->trial_ends_on === null) {
                $school->trial_ends_on = now()->addDays((int) config('services.billing.trial_days'))->toDateString();
            }

            $school->save();
            $after = ['plan' => $school->billing_plan, 'trial_ends_on' => $school->trial_ends_on?->toDateString()];

            $this->audit($request, $school->id, 'platform.billing.plan_changed', School::class, $school->id, $before, $after);

            return response()->json([
                'message' => $school->billing_plan
                    ? SchoolBilling::PLANS[$school->billing_plan].' set for '.$school->name.'.'
                    : 'Plan cleared for '.$school->name.'.',
            ] + $after);
        });
    }

    public function issue(Request $request, int $schoolId)
    {
        $validated = $request->validate(['term_id' => 'required|integer']);

        return $this->tenant->withoutScoping(function () use ($request, $schoolId, $validated) {
            $school = School::query()->tenants()->findOrFail($schoolId);
            $term = Term::where('school_id', $school->id)->findOrFail($validated['term_id']);

            try {
                $bill = $this->billing->issue($school, $term, $request->user()->id);
            } catch (\DomainException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $this->audit($request, $school->id, 'platform.billing.issued', TermBill::class, $bill->id, [], [
                'term_id' => $term->id,
                'plan' => $bill->plan,
                'active_students' => $bill->active_students,
                'boarding_students' => $bill->boarding_students,
                'amount_kobo' => $bill->amount_kobo,
            ]);

            return response()->json([
                'message' => 'Bill issued for '.$school->name.', '.$term->name.': ₦'.number_format($bill->amount_kobo / 100, 2).'.',
                'bill_id' => $bill->id,
            ], 201);
        });
    }

    /** Record that a bill was paid, waived, or reopen it. */
    public function setStatus(Request $request, int $billId)
    {
        $validated = $request->validate([
            'status' => 'required|in:issued,paid,waived',
            // A waiver always says why; a payment usually names the reference.
            'note' => 'nullable|string|max:255|required_if:status,waived',
        ]);

        return $this->tenant->withoutScoping(function () use ($request, $billId, $validated) {
            $bill = TermBill::findOrFail($billId);
            $before = ['status' => $bill->status, 'note' => $bill->note];

            $bill->status = $validated['status'];
            $bill->paid_at = $validated['status'] === 'paid' ? now() : null;
            $bill->note = $validated['note'] ?? $bill->note;
            $bill->save();

            // Partner commission follows the bill: earned when paid, undone if reopened or waived.
            app(PartnerCommissions::class)->sync($bill);

            $this->audit($request, $bill->school_id, 'platform.billing.'.$validated['status'], TermBill::class, $bill->id, $before, ['status' => $bill->status, 'note' => $bill->note]);

            return response()->json(['message' => match ($bill->status) {
                'paid' => 'Marked paid.',
                'waived' => 'Bill waived.',
                default => 'Bill reopened.',
            }]);
        });
    }

    /** The term in progress, or the latest one that has started. */
    private function currentTerm(int $schoolId): ?Term
    {
        $today = now()->toDateString();

        return Term::where('school_id', $schoolId)->where('is_current', true)->first()
            ?? Term::where('school_id', $schoolId)->where('start_date', '<=', $today)->where('end_date', '>=', $today)->first()
            ?? Term::where('school_id', $schoolId)->where('start_date', '<=', $today)->orderByDesc('start_date')->first();
    }

    /** @param array<string, mixed> $s */
    private function naira(array $s): array
    {
        return [
            'plan' => $s['plan'],
            'active_students' => $s['active_students'],
            'day_students' => $s['day_students'],
            'boarding_students' => $s['boarding_students'],
            'rate_naira' => $s['rate_kobo'] / 100,
            'boarding_rate_naira' => $s['boarding_rate_kobo'] / 100,
            'amount_naira' => $s['amount_kobo'] / 100,
            'in_trial' => $s['in_trial'],
            'bill' => $s['bill'],
        ];
    }

    private function audit(Request $request, int $schoolId, string $action, string $type, int $id, array $old, array $new): void
    {
        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
