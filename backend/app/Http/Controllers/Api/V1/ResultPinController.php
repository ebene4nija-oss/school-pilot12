<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ResultPin;
use App\Models\ResultPinBatch;
use App\Models\ResultPinPriceTier;
use App\Models\ResultPinSchoolRate;
use App\Models\ResultRelease;
use App\Models\School;
use App\Models\Student;
use App\Models\Term;
use App\Services\ResultPinPricing;
use App\Services\ResultPinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The school's side of the result checker: buying PIN stock from SchoolPilot,
 * pricing it for guardians, selling it over the counter, and declaring results
 * ready.
 *
 * School admin and director only. A teacher can enter scores but cannot set
 * what a parent is charged to read them.
 */
class ResultPinController extends Controller
{
    public function __construct(private ResultPinService $pins)
    {
    }

    private function schoolId(Request $request): ?int
    {
        return $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
    }

    /**
     * What this school pays SchoolPilot per PIN.
     *
     * Normally the published rate card. A school on a negotiated rate is shown
     * that number instead, and told the bands no longer apply to it — quoting it
     * volume discounts it will not receive is how a bursar budgets for the wrong
     * figure and queries the invoice.
     */
    public function priceTiers(Request $request)
    {
        $tiers = ResultPinPriceTier::where('is_active', true)
            ->orderBy('min_quantity')
            ->get(['min_quantity', 'unit_price']);

        $rate = ResultPinSchoolRate::activeFor($this->schoolId($request));

        if ($rate) {
            return response()->json([
                'currency' => 'NGN',
                'negotiated' => true,
                'unit_price' => $rate->unit_price,
                'tiers' => $tiers,
                'note' => 'Your school has an agreed rate with SchoolPilot, so you pay this price per PIN at any order size. You set your own price to parents separately.',
            ]);
        }

        return response()->json([
            'currency' => 'NGN',
            'negotiated' => false,
            'unit_price' => null,
            'tiers' => $tiers,
            'note' => 'Price per PIN falls as order size rises. You set your own price to parents separately.',
        ]);
    }

    public function inventory(Request $request)
    {
        $schoolId = $this->schoolId($request);
        $school = School::find($schoolId);

        $counts = ResultPin::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'available' => (int) ($counts['available'] ?? 0),
            'sold' => (int) ($counts['sold'] ?? 0),
            'used' => (int) ($counts['used'] ?? 0),
            'void' => (int) ($counts['void'] ?? 0),
            // PINs issued after stock ran out. A non-zero figure is money the
            // school owes SchoolPilot, so it is surfaced, not buried.
            'overdraft' => ResultPin::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('origin', 'overdraft')
                ->count(),
            'settings' => [
                'result_checker_enabled' => (bool) ($school->result_checker_enabled ?? false),
                'retail_price' => $school->result_pin_retail_price,
                'currency' => 'NGN',
            ],
        ]);
    }

    /**
     * Buy PIN stock from SchoolPilot.
     *
     * Creates a pending batch and a reference. No PINs exist until the gateway
     * webhook confirms — see FinanceController::handleWebhook. An abandoned
     * checkout therefore leaves a pending batch and nothing sellable.
     */
    public function purchaseBatch(Request $request)
    {
        $max = (int) config('schoolpilot.result_pin_max_batch_quantity', 10000);

        $validator = Validator::make($request->all(), [
            'quantity' => "required|integer|min:1|max:{$max}",
            'gateway' => 'required|in:paystack,flutterwave',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $this->schoolId($request);

        // Honours a negotiated rate if SchoolPilot agreed one with this school,
        // so buying online charges the same price as buying over the phone.
        $quote = ResultPinPricing::quote($schoolId, (int) $request->quantity);
        $unitPrice = $quote['unit_price'];

        if ($unitPrice === null) {
            return response()->json([
                'error' => 'PIN pricing is not configured. Please contact SchoolPilot.',
            ], 409);
        }

        $batch = ResultPinBatch::create([
            'school_id' => $schoolId,
            'reference' => $this->pins->generateReference('SPRB'),
            'quantity' => (int) $request->quantity,
            'unit_price' => $unitPrice,
            'total_amount' => bcmul((string) $unitPrice, (string) (int) $request->quantity, 2),
            'source' => 'online',
            'gateway' => $request->gateway,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Batch reserved. Complete payment to receive your PINs.',
            'batch' => [
                'id' => $batch->id,
                'reference' => $batch->reference,
                'quantity' => $batch->quantity,
                'unit_price' => $batch->unit_price,
                'total_amount' => $batch->total_amount,
                'currency' => 'NGN',
                'status' => $batch->status,
                // So the school can see on the receipt that its agreed rate was
                // applied, rather than having to check the arithmetic.
                'negotiated_rate' => $quote['negotiated'],
            ],
        ], 201);
    }

    public function batches(Request $request)
    {
        $batches = ResultPinBatch::withoutGlobalScopes()
            ->where('school_id', $this->schoolId($request))
            ->withCount([
                'pins as pins_available' => fn ($q) => $q->where('status', 'available'),
                'pins as pins_used' => fn ($q) => $q->where('status', 'used'),
            ])
            ->orderByDesc('id')
            ->get();

        return response()->json(['batches' => $batches]);
    }

    /**
     * Reveal the unsold codes in a batch so a bursar can print or sell them.
     *
     * This is the one endpoint that decrypts PIN material, which is why it is
     * admin-only, returns only PINs still in stock, and writes an audit row
     * naming who looked. Already-sold codes are not re-revealed: once a code is
     * in a parent's hands, re-reading it serves no operational purpose and only
     * widens who could quietly reuse it.
     */
    public function revealBatch(Request $request, $id)
    {
        $schoolId = $this->schoolId($request);

        $batch = ResultPinBatch::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->find($id);

        if (! $batch) {
            return response()->json(['error' => 'Batch not found.'], 404);
        }

        if ($batch->status !== 'active') {
            return response()->json(['error' => 'This batch is not active. PINs are issued once payment is confirmed.'], 409);
        }

        $pins = ResultPin::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('batch_id', $batch->id)
            ->where('status', 'available')
            ->orderBy('id')
            ->get();

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'result_pin.batch_revealed',
            'auditable_type' => ResultPinBatch::class,
            'auditable_id' => $batch->id,
            'new_values' => ['revealed_count' => $pins->count()],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'batch_reference' => $batch->reference,
            'count' => $pins->count(),
            'pins' => $pins->map(fn (ResultPin $pin) => [
                'serial' => $pin->serial,
                'pin' => $pin->revealCode(),
            ]),
        ]);
    }

    /**
     * Sell one PIN over the counter.
     *
     * The bursar takes cash or a transfer, and the response carries the code to
     * read out or print. The PIN stays unbound — the guardian binds it to a
     * child and term when they redeem it, which is what lets a school sell to a
     * parent who has three children and has not yet decided whose result to
     * open first.
     */
    public function sellOverCounter(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
            'sold_to_user_id' => 'nullable|integer|exists:users,id',
            'note' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $this->schoolId($request);

        $pin = ResultPin::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('status', 'available')
            ->orderBy('id')
            ->first();

        if (! $pin) {
            return response()->json([
                'error' => 'No PINs in stock. Buy a batch from SchoolPilot first.',
            ], 409);
        }

        $pin->fill([
            'status' => 'sold',
            'sold_to' => $request->sold_to_user_id,
            'sold_channel' => 'counter',
            'sold_amount' => $request->amount,
            'sold_at' => now(),
            'grant_reason' => $request->note,
        ])->save();

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'result_pin.counter_sale',
            'auditable_type' => ResultPin::class,
            'auditable_id' => $pin->id,
            'new_values' => ['serial' => $pin->serial, 'amount' => $request->amount],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'PIN sold. Give this code to the parent.',
            'serial' => $pin->serial,
            'pin' => $pin->revealCode(),
            'amount' => $pin->sold_amount,
        ], 201);
    }

    /** What guardians at this school pay for one PIN, and whether the wall is on. */
    public function updateSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'result_checker_enabled' => 'required|boolean',
            'retail_price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Switching the wall on without a price would show guardians a paywall
        // with no way through it.
        if ($request->boolean('result_checker_enabled') && $request->retail_price === null) {
            return response()->json([
                'errors' => ['retail_price' => ['Set a price per PIN before enabling the result checker.']],
            ], 422);
        }

        $school = School::find($this->schoolId($request));
        $school->result_checker_enabled = $request->boolean('result_checker_enabled');
        $school->result_pin_retail_price = $request->retail_price;
        $school->save();

        return response()->json([
            'message' => 'Result checker settings updated.',
            'result_checker_enabled' => $school->result_checker_enabled,
            'retail_price' => $school->result_pin_retail_price,
        ]);
    }

    /**
     * Open a child's result without payment — scholarship, hardship, or a
     * parent who paid at the office and the receipt went missing.
     */
    public function waive(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|integer',
            'term_id' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $this->schoolId($request);

        $student = Student::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->find($request->student_id);

        if (! $student) {
            return response()->json(['error' => 'Student not found.'], 404);
        }

        $term = Term::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->find($request->term_id);

        if (! $term) {
            return response()->json(['error' => 'Term not found.'], 404);
        }

        if ($this->pins->accessFor($schoolId, $student->id, $term->id)) {
            return response()->json(['message' => 'This result is already unlocked.'], 200);
        }

        $pin = $this->pins->waive($schoolId, $student->id, $term->id, $request->user()->id, $request->reason);

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'result_pin.waived',
            'auditable_type' => ResultPin::class,
            'auditable_id' => $pin->id,
            'new_values' => [
                'student_id' => $student->id,
                'term_id' => $term->id,
                'reason' => $request->reason,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Result unlocked for this student at no charge.',
            'serial' => $pin->serial,
        ], 201);
    }

    /**
     * Declare a term's results ready, for the whole school or one class.
     *
     * Nothing is purchasable or viewable by guardians until this happens.
     */
    public function release(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'term_id' => 'required|integer',
            'class_id' => 'nullable|integer',
            'is_released' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $this->schoolId($request);

        $term = Term::withoutGlobalScopes()->where('school_id', $schoolId)->find($request->term_id);

        if (! $term) {
            return response()->json(['error' => 'Term not found.'], 404);
        }

        $isReleased = $request->has('is_released') ? $request->boolean('is_released') : true;

        $release = ResultRelease::withoutGlobalScopes()->updateOrCreate(
            [
                'school_id' => $schoolId,
                'term_id' => $term->id,
                'class_id' => $request->class_id,
            ],
            [
                'is_released' => $isReleased,
                'released_by' => $request->user()->id,
                'released_at' => $isReleased ? now() : null,
            ]
        );

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => $isReleased ? 'results.released' : 'results.withdrawn',
            'auditable_type' => ResultRelease::class,
            'auditable_id' => $release->id,
            'new_values' => ['term_id' => $term->id, 'class_id' => $request->class_id],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => $isReleased
                ? 'Results released. Guardians can now check them.'
                : 'Results withdrawn. Guardians can no longer open them.',
            'release' => $release,
        ]);
    }

    public function releases(Request $request)
    {
        $releases = ResultRelease::withoutGlobalScopes()
            ->where('school_id', $this->schoolId($request))
            ->with(['term', 'schoolClass'])
            ->orderByDesc('id')
            ->get();

        return response()->json(['releases' => $releases]);
    }

    /** Sales ledger: what the school earned from result PINs. */
    public function salesReport(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $query = ResultPin::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('status', ['sold', 'used'])
            ->whereNotNull('sold_at');

        if ($request->filled('term_id')) {
            $query->where('term_id', $request->term_id);
        }

        $pins = $query->get();

        return response()->json([
            'currency' => 'NGN',
            'total_sold' => $pins->count(),
            'gross_revenue' => round((float) $pins->sum('sold_amount'), 2),
            'by_channel' => $pins->groupBy('sold_channel')->map(fn ($group) => [
                'count' => $group->count(),
                'revenue' => round((float) $group->sum('sold_amount'), 2),
            ]),
        ]);
    }
}
