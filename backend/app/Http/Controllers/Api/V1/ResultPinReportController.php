<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ResultPin;
use App\Models\ResultPinBatch;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Who bought result PINs, and what became of them.
 *
 * Two readers, two questions:
 *
 *  - A school asks "which of our PINs were sold, to whom, and were they used?"
 *    — a ledger of its own PINs, down to the parent and child.
 *  - SchoolPilot asks "which schools bought how many, what did they pay us,
 *    and are they actually selling them?" — per school and per batch.
 *
 * The operator view stops at counts. Which child's result a parent opened is
 * the school's record, not SchoolPilot's (doc §12), and the operator does not
 * need it to reconcile a PIN invoice. Neither view ever returns a PIN code:
 * serials identify a PIN, the code is only revealed by ResultPinController.
 */
class ResultPinReportController extends Controller
{
    private const STATUSES = ['available', 'sold', 'used', 'void'];

    /** The school's own PIN ledger. */
    public function schoolLedger(Request $request)
    {
        $validated = $request->validate([
            'status' => 'nullable|in:'.implode(',', self::STATUSES),
            'batch_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
        ]);

        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        $batches = ResultPinBatch::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->withCount($this->statusCounts())
            ->orderByDesc('id')
            ->get()
            ->map(fn (ResultPinBatch $b) => $this->batchRow($b));

        $query = ResultPin::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            // Stock still on the shelf is inventory, not a sale; it is counted
            // on the batch, not listed row by row.
            ->where('status', '!=', 'available')
            ->with([
                'soldTo:id,name',
                'student' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'user_id', 'admission_number')->with('user:id,name'),
                'term' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'),
                'batch' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'reference'),
            ]);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['batch_id'])) {
            $query->where('batch_id', $validated['batch_id']);
        }

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('serial', 'like', "%{$search}%")
                    ->orWhereHas('soldTo', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('student', fn ($s) => $s->withoutGlobalScopes()
                        ->where('admission_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")));
            });
        }

        $pins = $query->orderByDesc('sold_at')->orderByDesc('id')->paginate(50);

        return response()->json([
            'currency' => 'NGN',
            'batches' => $batches,
            'pins' => collect($pins->items())->map(fn (ResultPin $p) => [
                'serial' => $p->serial,
                'batch' => $p->batch?->reference,
                'origin' => $p->origin,
                'status' => $p->status,
                'channel' => $p->sold_channel,
                'amount' => $p->sold_amount !== null ? (float) $p->sold_amount : null,
                'sold_at' => $p->sold_at?->toIso8601String(),
                'sold_to' => $p->soldTo?->name,
                'student' => $p->student?->user?->name,
                'admission_number' => $p->student?->admission_number,
                'term' => $p->term?->name,
                'views_used' => $p->views_used,
                'max_views' => $p->max_views,
                'first_used_at' => $p->first_used_at?->toIso8601String(),
                'note' => $p->grant_reason ?? $p->void_reason,
            ]),
            'meta' => ['total' => $pins->total(), 'current_page' => $pins->currentPage(), 'last_page' => $pins->lastPage()],
        ]);
    }

    /**
     * Every school's PIN purchases and how far through their stock they are.
     *
     * Revenue is what schools paid SchoolPilot for stock — active batches only;
     * a pending batch is an abandoned or unfinished checkout, not money.
     */
    public function platformSummary(Request $request)
    {
        $validated = $request->validate([
            'school_id' => 'nullable|integer',
        ]);

        $pinCounts = ResultPin::withoutGlobalScopes()
            ->select('school_id')
            ->selectRaw("SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available")
            ->selectRaw("SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold")
            ->selectRaw("SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) as used")
            ->selectRaw("SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) as void")
            ->selectRaw("SUM(CASE WHEN origin = 'overdraft' THEN 1 ELSE 0 END) as overdraft")
            ->selectRaw("SUM(CASE WHEN origin = 'waiver' THEN 1 ELSE 0 END) as waived")
            ->selectRaw('COALESCE(SUM(sold_amount), 0) as parent_revenue')
            ->groupBy('school_id')
            ->get()
            ->keyBy('school_id');

        $batchTotals = ResultPinBatch::withoutGlobalScopes()
            ->select('school_id')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as batches_paid")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as batches_pending")
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN quantity ELSE 0 END) as pins_bought")
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN total_amount ELSE 0 END) as paid_to_platform")
            ->selectRaw("MAX(CASE WHEN status = 'active' THEN COALESCE(paid_at, created_at) END) as last_purchase_at")
            ->groupBy('school_id')
            ->get()
            ->keyBy('school_id');

        $schoolIds = $pinCounts->keys()->merge($batchTotals->keys())->unique();

        $schools = School::whereIn('id', $schoolIds)->get(['id', 'name', 'subdomain', 'result_checker_enabled'])
            ->map(function (School $s) use ($pinCounts, $batchTotals) {
                $p = $pinCounts->get($s->id);
                $b = $batchTotals->get($s->id);

                return [
                    'school_id' => $s->id,
                    'name' => $s->name,
                    'subdomain' => $s->subdomain,
                    'result_checker_enabled' => (bool) $s->result_checker_enabled,
                    'batches_paid' => (int) ($b->batches_paid ?? 0),
                    'batches_pending' => (int) ($b->batches_pending ?? 0),
                    'pins_bought' => (int) ($b->pins_bought ?? 0),
                    'paid_to_platform' => round((float) ($b->paid_to_platform ?? 0), 2),
                    'last_purchase_at' => $b->last_purchase_at ?? null,
                    'available' => (int) ($p->available ?? 0),
                    'sold' => (int) ($p->sold ?? 0),
                    'used' => (int) ($p->used ?? 0),
                    'void' => (int) ($p->void ?? 0),
                    // Issued after the school ran out: owed to SchoolPilot.
                    'overdraft' => (int) ($p->overdraft ?? 0),
                    'waived' => (int) ($p->waived ?? 0),
                    // What the school took from parents. The school's income,
                    // shown so an operator can see a school is selling at all.
                    'parent_revenue' => round((float) ($p->parent_revenue ?? 0), 2),
                ];
            })
            ->sortByDesc('paid_to_platform')
            ->values();

        $batches = ResultPinBatch::withoutGlobalScopes()
            ->with(['school:id,name', 'grantedBy:id,name'])
            ->withCount($this->statusCounts())
            ->when($validated['school_id'] ?? null, fn ($q, $id) => $q->where('school_id', $id))
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (ResultPinBatch $b) => $this->batchRow($b) + [
                'school_id' => $b->school_id,
                'school' => $b->school?->name,
                'granted_by' => $b->grantedBy?->name,
            ]);

        return response()->json([
            'currency' => 'NGN',
            'totals' => [
                'schools' => $schools->count(),
                'pins_bought' => $schools->sum('pins_bought'),
                'paid_to_platform' => round($schools->sum('paid_to_platform'), 2),
                'sold' => $schools->sum('sold') + $schools->sum('used'),
                'used' => $schools->sum('used'),
                'available' => $schools->sum('available'),
                'overdraft' => $schools->sum('overdraft'),
            ],
            'schools' => $schools,
            'batches' => $batches,
        ]);
    }

    private function statusCounts(): array
    {
        return collect(self::STATUSES)
            ->mapWithKeys(fn ($s) => ["pins as pins_{$s}" => fn ($q) => $q->withoutGlobalScopes()->where('status', $s)])
            ->all();
    }

    private function batchRow(ResultPinBatch $b): array
    {
        return [
            'id' => $b->id,
            'reference' => $b->reference,
            'quantity' => $b->quantity,
            'unit_price' => (float) $b->unit_price,
            'total_amount' => (float) $b->total_amount,
            'source' => $b->source,
            'gateway' => $b->gateway,
            'status' => $b->status,
            'paid_at' => $b->paid_at?->toIso8601String(),
            'created_at' => $b->created_at?->toIso8601String(),
            'available' => (int) $b->pins_available,
            'sold' => (int) $b->pins_sold,
            'used' => (int) $b->pins_used,
            'void' => (int) $b->pins_void,
        ];
    }
}
