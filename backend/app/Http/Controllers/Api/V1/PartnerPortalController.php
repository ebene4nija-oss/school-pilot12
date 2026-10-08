<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\PartnerPayout;
use App\Models\School;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * A partner's own view: their referral link, the schools credited to them, and
 * what they have earned and been paid.
 *
 * Deliberately shows commission amounts only. It never returns a school's bill
 * or the rate: with both, a partner could divide one by the other and read the
 * rate that was negotiated privately. The partner is found from the signed-in
 * user, never from the request, so one partner cannot ask for another's data.
 */
class PartnerPortalController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function summary(Request $request)
    {
        $partner = $this->partner($request);

        $by = PartnerCommission::where('partner_id', $partner->id)
            ->where('status', '!=', 'void')
            ->selectRaw('status, SUM(amount_kobo) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $owed = (int) ($by['accrued'] ?? 0);
        $paid = (int) ($by['paid'] ?? 0);

        return response()->json([
            'partner' => [
                'name' => $partner->name,
                'email' => $partner->email,
                'phone' => $partner->phone,
                'status' => $partner->status,
                'code' => $partner->code,
                'has_bank_details' => filled($partner->account_number),
            ],
            'referral_link' => rtrim((string) config('services.marketing.site_url', 'https://schoolpilot.org.ng'), '/').'/?p='.$partner->code,
            'owed_naira' => $owed / 100,
            'paid_naira' => $paid / 100,
            'lifetime_naira' => ($owed + $paid) / 100,
            'schools' => $this->tenant->withoutScoping(fn () => School::query()->tenants()->where('partner_id', $partner->id)->count()),
        ]);
    }

    /** Schools credited to this partner and what each has earned them. */
    public function schools(Request $request)
    {
        $partner = $this->partner($request);

        $earned = PartnerCommission::where('partner_id', $partner->id)->where('status', '!=', 'void')
            ->selectRaw('school_id, SUM(amount_kobo) as total, COUNT(*) as bills')
            ->groupBy('school_id')->get()->keyBy('school_id');

        return $this->tenant->withoutScoping(fn () => response()->json([
            'data' => School::query()->tenants()->where('partner_id', $partner->id)->orderBy('name')->get(['id', 'name', 'status'])
                ->map(fn (School $s) => [
                    'name' => $s->name,
                    'status' => $s->status,
                    'paid_terms' => (int) ($earned[$s->id]->bills ?? 0),
                    'earned_naira' => ((int) ($earned[$s->id]->total ?? 0)) / 100,
                ])->values(),
        ]));
    }

    public function commissions(Request $request)
    {
        $partner = $this->partner($request);

        return $this->tenant->withoutScoping(fn () => response()->json([
            'data' => PartnerCommission::with('school:id,name')->where('partner_id', $partner->id)
                ->where('status', '!=', 'void')->latest('id')->limit(200)->get()
                ->map(fn (PartnerCommission $c) => [
                    'school' => $c->school?->name,
                    'amount_naira' => $c->amount_kobo / 100,
                    'status' => $c->status === 'paid' ? 'paid' : 'owed',
                    'earned_on' => $c->created_at?->toDateString(),
                ]),
        ]));
    }

    public function payouts(Request $request)
    {
        $partner = $this->partner($request);

        return response()->json([
            'data' => PartnerPayout::where('partner_id', $partner->id)->latest('id')->get()
                ->map(fn (PartnerPayout $p) => [
                    'amount_naira' => $p->amount_kobo / 100,
                    'paid_on' => $p->paid_on?->toDateString(),
                    'reference' => $p->reference,
                ]),
        ]);
    }

    /** Where payouts go. Read back masked: the number is never returned in full. */
    public function bank(Request $request)
    {
        $partner = $this->partner($request);

        return response()->json($this->bankView($partner));
    }

    public function updateBank(Request $request)
    {
        $partner = $this->partner($request);

        $validated = $request->validate([
            'bank_name' => 'required|string|min:2|max:80',
            'account_number' => ['required', 'string', 'regex:/^\d{10}$/'],
            'account_name' => 'required|string|min:2|max:120',
        ], ['account_number.regex' => 'A Nigerian account number has 10 digits.']);

        $partner->update($validated);

        return response()->json($this->bankView($partner->fresh()));
    }

    private function bankView(Partner $partner): array
    {
        $number = (string) $partner->account_number;

        return [
            'bank_name' => $partner->bank_name,
            'account_name' => $partner->account_name,
            'account_number_masked' => $number === '' ? null : str_repeat('•', max(strlen($number) - 4, 0)).substr($number, -4),
        ];
    }

    private function partner(Request $request): Partner
    {
        return Partner::where('user_id', $request->user()->id)->firstOrFail();
    }
}
