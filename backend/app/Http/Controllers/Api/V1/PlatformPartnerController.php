<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MarketingLead;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\PartnerPayout;
use App\Models\School;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The operator console's partner programme: who the partners are, the rate each
 * one earns, the schools they brought, and what is owed or has been paid out.
 *
 * Super admins only. This is the one place a partner's negotiated rate is read
 * or written; it is audited on every change and never sent to the partner or
 * the public site. Payouts are made outside the product and recorded here.
 */
class PlatformPartnerController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function index()
    {
        $default = (int) config('services.partners.default_rate_bps');

        $totals = PartnerCommission::query()
            ->selectRaw('partner_id, status, SUM(amount_kobo) as total')
            ->groupBy('partner_id', 'status')
            ->get()
            ->groupBy('partner_id');

        $schools = School::query()->tenants()->whereNotNull('partner_id')
            ->selectRaw('partner_id, COUNT(*) as total')->groupBy('partner_id')->pluck('total', 'partner_id');

        $partners = Partner::query()->orderByDesc('id')->get()->map(function (Partner $p) use ($default, $totals, $schools) {
            $by = ($totals[$p->id] ?? collect())->pluck('total', 'status');

            return $this->present($p, $default) + [
                'schools' => (int) ($schools[$p->id] ?? 0),
                'owed_naira' => ((int) ($by['accrued'] ?? 0)) / 100,
                'paid_naira' => ((int) ($by['paid'] ?? 0)) / 100,
            ];
        });

        return response()->json([
            'default_rate_percent' => $default / 100,
            'data' => $partners->values(),
        ]);
    }

    /** Add a partner directly (an application is approved through `update`). */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email:rfc|max:150|unique:partners,email',
            'phone' => 'nullable|string|max:24',
            'commission_rate_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        $partner = Partner::create([
            'code' => Partner::newCode(),
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'status' => 'active',
            'approved_at' => now(),
            'commission_rate_override' => $this->bps($validated['commission_rate_percent'] ?? null),
        ]);

        $this->audit($request, 'partner.created', $partner->id, [], $this->auditValues($partner));

        return response()->json(['data' => $this->present($partner, (int) config('services.partners.default_rate_bps'))], 201);
    }

    /**
     * Approve, suspend, or change a partner's details or private rate.
     * Sending `commission_rate_percent: null` clears the override, so the
     * public default applies again. A new rate affects bills paid from now on.
     */
    public function update(Request $request, int $id)
    {
        $partner = Partner::findOrFail($id);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(Partner::STATUSES)],
            'name' => 'sometimes|string|min:2|max:120',
            'phone' => 'sometimes|nullable|string|max:24',
            'commission_rate_percent' => 'sometimes|nullable|numeric|min:0|max:100',
        ]);

        $before = $this->auditValues($partner);

        if (array_key_exists('commission_rate_percent', $validated)) {
            $partner->commission_rate_override = $this->bps($validated['commission_rate_percent']);
        }

        foreach (['status', 'name', 'phone'] as $field) {
            if (array_key_exists($field, $validated)) {
                $partner->{$field} = $validated[$field];
            }
        }

        if ($partner->status === 'active' && $partner->approved_at === null) {
            $partner->approved_at = now();
        }

        $partner->save();
        $this->audit($request, 'partner.updated', $partner->id, $before, $this->auditValues($partner));

        return response()->json(['data' => $this->present($partner, (int) config('services.partners.default_rate_bps'))]);
    }

    /** A partner's schools, commissions and payouts. */
    public function show(int $id)
    {
        $partner = Partner::findOrFail($id);

        return $this->tenant->withoutScoping(fn () => response()->json([
            'data' => $this->present($partner, (int) config('services.partners.default_rate_bps')),
            'schools' => School::query()->tenants()->where('partner_id', $partner->id)->orderBy('name')->get(['id', 'name', 'subdomain', 'status']),
            'commissions' => PartnerCommission::with('school:id,name')->where('partner_id', $partner->id)->latest('id')->limit(200)->get()
                ->map(fn (PartnerCommission $c) => [
                    'id' => $c->id,
                    'school' => $c->school?->name,
                    'amount_naira' => $c->amount_kobo / 100,
                    'rate_percent' => $c->rate_bps / 100,
                    'status' => $c->status,
                    'created_at' => $c->created_at?->toIso8601String(),
                ]),
            'payouts' => PartnerPayout::where('partner_id', $partner->id)->latest('id')->get()
                ->map(fn (PartnerPayout $p) => [
                    'id' => $p->id,
                    'amount_naira' => $p->amount_kobo / 100,
                    'paid_on' => $p->paid_on?->toDateString(),
                    'reference' => $p->reference,
                    'note' => $p->note,
                ]),
        ]));
    }

    /** Credit a school to a partner (or clear it with `partner_id: null`). */
    public function attachSchool(Request $request, int $schoolId)
    {
        $validated = $request->validate(['partner_id' => 'present|nullable|integer|exists:partners,id']);

        return $this->tenant->withoutScoping(function () use ($request, $schoolId, $validated) {
            $school = School::query()->tenants()->findOrFail($schoolId);
            $before = ['partner_id' => $school->partner_id];

            $school->partner_id = $validated['partner_id'];
            $school->save();

            $this->audit($request, 'partner.school_attached', $school->id, $before, ['partner_id' => $school->partner_id], School::class);

            return response()->json(['message' => $school->partner_id ? 'School credited to partner.' : 'Partner cleared.']);
        });
    }

    /** Credit a lead to a partner. The partner follows the lead onto the school it becomes. */
    public function attachLead(Request $request, int $leadId)
    {
        $validated = $request->validate(['partner_id' => 'present|nullable|integer|exists:partners,id']);

        $lead = MarketingLead::findOrFail($leadId);
        $before = ['partner_id' => $lead->partner_id];
        $lead->update(['partner_id' => $validated['partner_id']]);

        $this->audit($request, 'partner.lead_attached', $lead->id, $before, ['partner_id' => $lead->partner_id], MarketingLead::class);

        return response()->json(['message' => $lead->partner_id ? 'Lead credited to partner.' : 'Partner cleared.']);
    }

    /**
     * Record a payout. It settles every commission accrued so far, so the
     * amount is never typed: it is what the partner is owed, and the same
     * commission cannot be paid twice.
     */
    public function payout(Request $request, int $id)
    {
        $partner = Partner::findOrFail($id);

        $validated = $request->validate([
            'paid_on' => 'required|date',
            'reference' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $partner, $validated) {
            $accrued = PartnerCommission::where('partner_id', $partner->id)->where('status', 'accrued')->lockForUpdate()->get();

            if ($accrued->isEmpty()) {
                return response()->json(['message' => 'Nothing is owed to this partner.'], 422);
            }

            $payout = PartnerPayout::create([
                'partner_id' => $partner->id,
                'amount_kobo' => $accrued->sum('amount_kobo'),
                'paid_on' => $validated['paid_on'],
                'reference' => $validated['reference'] ?? null,
                'note' => $validated['note'] ?? null,
                'recorded_by' => $request->user()->id,
            ]);

            PartnerCommission::whereIn('id', $accrued->pluck('id'))->update(['status' => 'paid', 'payout_id' => $payout->id]);

            $this->audit($request, 'partner.payout_recorded', $payout->id, [], [
                'partner_id' => $partner->id,
                'amount_kobo' => $payout->amount_kobo,
                'commissions' => $accrued->count(),
            ], PartnerPayout::class);

            return response()->json([
                'message' => 'Payout of ₦'.number_format($payout->amount_kobo / 100, 2).' recorded.',
                'payout_id' => $payout->id,
            ], 201);
        });
    }

    /** Percent (e.g. 35) to basis points; null clears the override. */
    private function bps(mixed $percent): ?int
    {
        return $percent === null ? null : (int) round(((float) $percent) * 100);
    }

    private function present(Partner $p, int $defaultBps): array
    {
        $rate = $p->makeVisible('commission_rate_override')->rateBps();

        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'email' => $p->email,
            'phone' => $p->phone,
            'status' => $p->status,
            'pitch' => $p->pitch,
            'rate_percent' => $rate / 100,
            // True when this partner has a rate other than the published one.
            'negotiated' => $p->commission_rate_override !== null && $p->commission_rate_override !== $defaultBps,
            'has_bank_details' => filled($p->account_number),
            'approved_at' => $p->approved_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    private function auditValues(Partner $p): array
    {
        return [
            'status' => $p->status,
            'name' => $p->name,
            'commission_rate_override' => $p->makeVisible('commission_rate_override')->commission_rate_override,
        ];
    }

    private function audit(Request $request, string $action, int $id, array $old, array $new, string $type = Partner::class): void
    {
        AuditLog::create([
            'school_id' => null,
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
