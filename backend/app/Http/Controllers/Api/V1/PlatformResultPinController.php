<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ResultPinBatch;
use App\Models\ResultPinPriceTier;
use App\Models\School;
use App\Services\ResultPinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * SchoolPilot's own console for result-checker PINs.
 *
 * Two jobs: publish the wholesale rate card every school buys against, and
 * issue PINs to a school that paid outside the platform — a bank transfer, or a
 * director who rang the office and settled by invoice. Those schools are the
 * majority in this market, so "we took their money offline" cannot be a dead
 * end that leaves them unable to use the feature.
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
         * The operator may override the rate — a negotiated price for a large
         * school, or a zero-priced goodwill top-up. Falling back to the rate
         * card keeps the common case a two-field form.
         */
        $unitPrice = $request->unit_price !== null
            ? (string) $request->unit_price
            : ResultPinPriceTier::priceFor($quantity);

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
