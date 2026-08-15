<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ResultPinBatch;
use App\Models\ResultPinPriceTier;
use App\Models\ResultPinSchoolRate;
use App\Models\School;
use App\Services\ResultPinPricing;
use App\Services\ResultPinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * SchoolPilot's own console for result-checker PINs.
 *
 * Three jobs: publish the wholesale rate card every school buys against, agree a
 * discounted rate with an individual school, and issue PINs to a school that
 * paid outside the platform — a bank transfer, or a director who rang the office
 * and settled by invoice. Those schools are the majority in this market, so "we
 * took their money offline" cannot be a dead end that leaves them unable to use
 * the feature.
 *
 * Super admin only, and every grant is audited: this endpoint mints sellable
 * inventory out of nothing, which is exactly the power that needs a paper trail.
 */
class PlatformResultPinController extends Controller
{
    public function __construct(private ResultPinService $pins)
    {
    }

    public function listPriceTiers()
    {
        return response()->json([
            'currency' => 'NGN',
            'tiers' => ResultPinPriceTier::orderBy('min_quantity')->get(),
        ]);
    }

    /**
     * Publish or update a band of the wholesale rate card.
     *
     * Changing a band never rewrites history: batches record the unit price
     * they were bought at, so a school's past invoices stay true.
     */
    public function storePriceTier(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'min_quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tier = ResultPinPriceTier::updateOrCreate(
            ['min_quantity' => (int) $request->min_quantity],
            [
                'unit_price' => $request->unit_price,
                'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
            ]
        );

        AuditLog::create([
            'school_id' => null,
            'user_id' => $request->user()->id,
            'action' => 'platform.result_pin_price_tier_set',
            'auditable_type' => ResultPinPriceTier::class,
            'auditable_id' => $tier->id,
            'new_values' => $tier->only(['min_quantity', 'unit_price', 'is_active']),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Rate card updated.', 'tier' => $tier], 201);
    }

    /** Every school currently on terms other than the published card. */
    public function listSchoolRates(Request $request)
    {
        $query = ResultPinSchoolRate::with(['school:id,name,subdomain', 'setBy:id,name']);

        // Lifted rates stay on file, so they are hidden unless asked for.
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json([
            'currency' => 'NGN',
            'rates' => $query->orderByDesc('id')->get(),
        ]);
    }

    /**
     * Agree a flat per-PIN price with one school.
     *
     * Replaces any rate already on file for that school — a renegotiation is a
     * new number, not a second deal — and reactivates a previously lifted one
     * rather than leaving two rows fighting over the same school.
     *
     * Only stock bought from now on is affected. Batches record the price they
     * were bought at, so agreeing a discount today does not retrospectively
     * discount what a school already paid, and lifting one does not bill it more.
     */
    public function setSchoolRate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'school_id' => 'required|integer|exists:schools,id',
            'unit_price' => 'required|numeric|min:0',
            // Required, not optional: a rate nobody can account for six months
            // later is how a discount outlives the deal that justified it.
            'notes' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $school = School::find($request->school_id);

        $existing = ResultPinSchoolRate::where('school_id', $school->id)->first();
        $previous = $existing ? $existing->only(['unit_price', 'is_active', 'notes']) : null;

        $rate = ResultPinSchoolRate::updateOrCreate(
            ['school_id' => $school->id],
            [
                'unit_price' => $request->unit_price,
                'is_active' => true,
                'notes' => $request->notes,
                'set_by' => $request->user()->id,
            ]
        );

        AuditLog::create([
            'school_id' => $school->id,
            'user_id' => $request->user()->id,
            'action' => 'platform.result_pin_school_rate_set',
            'auditable_type' => ResultPinSchoolRate::class,
            'auditable_id' => $rate->id,
            'old_values' => $previous,
            'new_values' => $rate->only(['school_id', 'unit_price', 'is_active', 'notes']),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => "{$school->name} now pays ₦{$rate->unit_price} per PIN at any order size.",
            'rate' => $rate->load('school:id,name,subdomain'),
        ], 201);
    }

    /**
     * Put a school back on the published rate card.
     *
     * Deactivates rather than deletes: what the terms were, who agreed them and
     * why stays on file, and setting a new rate later revives the same row.
     */
    public function removeSchoolRate(Request $request, $schoolId)
    {
        $rate = ResultPinSchoolRate::where('school_id', (int) $schoolId)->first();

        if (! $rate || ! $rate->is_active) {
            return response()->json(['error' => 'This school has no agreed rate. It already buys at card price.'], 404);
        }

        $previous = $rate->only(['unit_price', 'is_active', 'notes']);

        $rate->update(['is_active' => false, 'set_by' => $request->user()->id]);

        AuditLog::create([
            'school_id' => $rate->school_id,
            'user_id' => $request->user()->id,
            'action' => 'platform.result_pin_school_rate_lifted',
            'auditable_type' => ResultPinSchoolRate::class,
            'auditable_id' => $rate->id,
            'old_values' => $previous,
            'new_values' => ['is_active' => false],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Agreed rate lifted. This school now buys at the published rate card.',
        ]);
    }

    /**
     * Issue PINs to a school that paid offline.
     *
     * Unlike an online purchase this mints immediately — a SchoolPilot operator
     * has already seen the bank alert, so there is no webhook to wait for. The
     * plaintext codes come back once, in this response, and the operator hands
     * them to the school (or the school reads them from its own batch reveal).
     */
    public function grantBatch(Request $request)
    {
        $max = (int) config('schoolpilot.result_pin_max_batch_quantity', 10000);

        $validator = Validator::make($request->all(), [
            'school_id' => 'required|integer|exists:schools,id',
            'quantity' => "required|integer|min:1|max:{$max}",
            'unit_price' => 'nullable|numeric|min:0',
            'gateway' => 'nullable|in:bank_transfer,cash',
            'notes' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $school = School::find($request->school_id);
        $quantity = (int) $request->quantity;

        /*
         * The operator may override the rate for this one grant — a one-off
         * settlement, or a zero-priced goodwill top-up. Otherwise the school's
         * standing negotiated rate applies, and failing that the rate card, so
         * the common case stays a two-field form and a school on a discount is
         * charged its agreed price without anyone having to remember it.
         */
        $unitPrice = $request->unit_price !== null
            ? (string) $request->unit_price
            : ResultPinPricing::unitPrice($school->id, $quantity);

        if ($unitPrice === null) {
            return response()->json([
                'error' => 'No rate card is published and no unit price was supplied.',
            ], 409);
        }

        $batch = ResultPinBatch::withoutGlobalScopes()->create([
            'school_id' => $school->id,
            'reference' => $this->pins->generateReference('SPGB'),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_amount' => bcmul($unitPrice, (string) $quantity, 2),
            'source' => 'manual_grant',
            'gateway' => $request->gateway ?? 'bank_transfer',
            // Born active: the money is already in SchoolPilot's account.
            'status' => 'active',
            'granted_by' => $request->user()->id,
            'notes' => $request->notes,
            'paid_at' => now(),
        ]);

        $codes = $this->pins->mintPins($batch, $quantity);

        AuditLog::create([
            'school_id' => $school->id,
            'user_id' => $request->user()->id,
            'action' => 'platform.result_pins_granted',
            'auditable_type' => ResultPinBatch::class,
            'auditable_id' => $batch->id,
            'new_values' => [
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $batch->total_amount,
                'notes' => $request->notes,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => "{$quantity} PINs issued to {$school->name}.",
            'batch' => [
                'id' => $batch->id,
                'reference' => $batch->reference,
                'quantity' => $batch->quantity,
                'unit_price' => $batch->unit_price,
                'total_amount' => $batch->total_amount,
                'source' => $batch->source,
                'notes' => $batch->notes,
            ],
            'pins' => $codes,
            'warning' => 'These codes are shown once. The school can re-read unsold codes from its own batch view.',
        ], 201);
    }

    /** Every batch across every school — SchoolPilot's revenue ledger. */
    public function batches(Request $request)
    {
        $query = ResultPinBatch::withoutGlobalScopes()->with('school:id,name,subdomain');

        if ($request->filled('school_id')) {
            $query->where('school_id', $request->school_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $batches = $query->orderByDesc('id')->limit(200)->get();

        $paid = $batches->where('status', 'active');

        return response()->json([
            'currency' => 'NGN',
            'totals' => [
                'batches' => $batches->count(),
                'pins_issued' => (int) $paid->sum('quantity'),
                'revenue' => round((float) $paid->sum('total_amount'), 2),
            ],
            'batches' => $batches,
        ]);
    }
}
