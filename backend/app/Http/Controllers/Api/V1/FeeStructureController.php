<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FeeStructure;
use App\Models\InvoiceItem;
use App\Models\SchoolClass;
use App\Models\Term;
use App\Services\InvoiceGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The fee-structure builder (§7.12).
 *
 * There was a create endpoint and nothing else — no way to read back what a
 * school had defined, no way to correct a figure, no way to remove a fee that
 * no longer applies. An admin who typed ₦450,000 instead of ₦45,000 had to be
 * fixed in the database by hand.
 *
 * Everything here is school-scoped through the acting user's profile, and the
 * `term_id`/`class_id` existence rules are scoped too: without that, an admin
 * at one school could hang a fee off another school's term.
 */
class FeeStructureController extends Controller
{
    public function __construct(private InvoiceGenerationService $invoices)
    {
    }

    private function schoolId(Request $request): ?int
    {
        return $request->user()?->userProfile?->school_id;
    }

    /**
     * What this school charges, plus the totals a bursar actually quotes.
     *
     * `by_class` is the answer to "what does JSS 1 cost this term?" — school-wide
     * fees plus that class's own, which is the figure on the fee slip and is not
     * something the caller should have to reassemble.
     *
     * Terms and classes ride along because the builder form needs them to
     * populate its dropdowns and there is no other endpoint that lists them.
     */
    public function index(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $request->validate([
            'term_id' => ['nullable', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
        ]);

        $terms = Term::where('school_id', $schoolId)->orderByDesc('is_current')->orderByDesc('start_date')->get();
        $classes = SchoolClass::where('school_id', $schoolId)->orderBy('order_index')->orderBy('name')->get();

        // Default to the term the school is actually in — a bursar opening the
        // page wants this term's fees, not an empty table.
        $termId = $request->filled('term_id')
            ? $request->integer('term_id')
            : $terms->firstWhere('is_current', true)?->id ?? $terms->first()?->id;

        $fees = FeeStructure::where('school_id', $schoolId)
            ->when($termId, fn ($q) => $q->where('term_id', $termId))
            ->with(['schoolClass:id,name', 'term:id,name'])
            ->orderBy('class_id')
            ->orderBy('title')
            ->get();

        $schoolWide = $fees->whereNull('class_id')->where('is_mandatory', true);

        return response()->json([
            'term_id' => $termId,
            'currency' => 'NGN',
            'data' => $fees->map(fn (FeeStructure $fee) => [
                'id' => $fee->id,
                'term_id' => $fee->term_id,
                'term' => $fee->term?->name,
                'class_id' => $fee->class_id,
                'class' => $fee->schoolClass?->name,
                'scope' => $fee->class_id === null ? 'school_wide' : 'class',
                'title' => $fee->title,
                'amount' => (float) $fee->amount,
                'is_mandatory' => (bool) $fee->is_mandatory,
                // Lets the UI warn before an edit that touches issued bills.
                'invoiced_lines' => $fee->invoiceItems()->count(),
            ]),
            'by_class' => $classes->map(function (SchoolClass $class) use ($fees, $schoolWide) {
                $own = $fees->where('class_id', $class->id)->where('is_mandatory', true);

                return [
                    'class_id' => $class->id,
                    'class' => $class->name,
                    'lines' => $own->concat($schoolWide)
                        ->map(fn (FeeStructure $f) => ['title' => $f->title, 'amount' => (float) $f->amount])
                        ->values(),
                    'total_payable' => round($own->sum('amount') + $schoolWide->sum('amount'), 2),
                ];
            }),
            'terms' => $terms->map(fn (Term $t) => [
                'id' => $t->id, 'name' => $t->name, 'is_current' => (bool) $t->is_current,
            ]),
            'classes' => $classes->map(fn (SchoolClass $c) => ['id' => $c->id, 'name' => $c->name]),
        ]);
    }

    public function store(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $validated = $request->validate($this->rules($schoolId));

        $fee = FeeStructure::create([
            'school_id' => $schoolId,
            'term_id' => $validated['term_id'],
            'class_id' => $validated['class_id'] ?? null,
            'title' => $validated['title'],
            'amount' => $validated['amount'],
            'is_mandatory' => $request->boolean('is_mandatory', true),
        ]);

        $this->audit($request, 'fee_structure.created', $fee, null, $fee->toArray());

        // Response shape preserved from the original FinanceController endpoint —
        // this route is already published under /api/v1 and may have callers.
        return response()->json([
            'message' => 'Fee structure created successfully',
            'fee_structure' => $fee,
        ], 201);
    }

    /**
     * Correct a fee.
     *
     * Deliberately does not rewrite invoices already issued from this structure.
     * Repricing tuition mid-term must not silently restate what families have
     * already been billed and in some cases paid; `invoiced_lines` tells the
     * caller how many bills carry the old figure so the school can decide.
     */
    public function update(Request $request, $id)
    {
        $schoolId = $this->schoolId($request);
        $fee = FeeStructure::where('school_id', $schoolId)->findOrFail($id);

        $validated = $request->validate($this->rules($schoolId));

        $before = $fee->toArray();

        $fee->update([
            'term_id' => $validated['term_id'],
            'class_id' => $validated['class_id'] ?? null,
            'title' => $validated['title'],
            'amount' => $validated['amount'],
            'is_mandatory' => $request->boolean('is_mandatory', true),
        ]);

        $this->audit($request, 'fee_structure.updated', $fee, $before, $fee->fresh()->toArray());

        return response()->json([
            'message' => 'Fee structure updated.',
            'fee_structure' => $fee->fresh(),
            'invoiced_lines' => $fee->invoiceItems()->count(),
            'note' => 'Invoices already issued keep the amount they were raised with.',
        ]);
    }

    /**
     * Retire a fee.
     *
     * The structure goes; lines already billed from it stay, orphaned by the
     * set-null foreign key. Deleting a family's history because the school
     * stopped charging for the bus next term would be the wrong trade.
     */
    public function destroy(Request $request, $id)
    {
        $schoolId = $this->schoolId($request);
        $fee = FeeStructure::where('school_id', $schoolId)->findOrFail($id);

        $retained = InvoiceItem::where('fee_structure_id', $fee->id)->count();
        $before = $fee->toArray();

        $fee->delete();

        $this->audit($request, 'fee_structure.deleted', $fee, $before, null);

        return response()->json([
            'message' => 'Fee structure removed.',
            'invoiced_lines_retained' => $retained,
            'note' => $retained > 0
                ? "{$retained} invoice line(s) already issued from this fee remain on those bills."
                : null,
        ]);
    }

    /**
     * Raise the term's invoices from the fee structures.
     *
     * Safe to press twice — see InvoiceGenerationService.
     */
    public function generateInvoices(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $validated = $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'due_date' => 'nullable|date',
        ]);

        $term = Term::where('school_id', $schoolId)->findOrFail($validated['term_id']);

        $result = $this->invoices->generateForTerm(
            $schoolId,
            $term,
            isset($validated['class_id']) ? (int) $validated['class_id'] : null,
            isset($validated['due_date']) ? Carbon::parse($validated['due_date']) : null,
        );

        // Raising a term's fees is the single largest money event in the school
        // calendar. It leaves a trail.
        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'invoices.generated',
            'auditable_type' => Term::class,
            'auditable_id' => $term->id,
            'old_values' => null,
            'new_values' => $result['summary'],
            'ip_address' => $request->ip(),
        ]);

        return response()->json($result);
    }

    private function rules(?int $schoolId): array
    {
        return [
            // Scoped existence checks: an unscoped `exists:terms,id` would let a
            // school attach a fee to another tenant's term.
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0|max:99999999.99',
            'is_mandatory' => 'nullable|boolean',
        ];
    }

    private function audit(Request $request, string $action, FeeStructure $fee, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'school_id' => $fee->school_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => FeeStructure::class,
            'auditable_id' => $fee->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
        ]);
    }
}
