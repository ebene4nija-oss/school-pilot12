<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRefusal;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InventoryCharge;
use App\Models\InventoryIssue;
use App\Models\InventoryItem;
use App\Models\InventoryPack;
use App\Models\InventorySetting;
use App\Models\InventoryStockMovement;
use App\Models\Student;
use App\Models\Term;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The school store: uniforms, books and consumables handed to students, each
 * hand-over confirmed received by a parent.
 *
 * Two switches: SchoolPilot's "inventory" module (EnforceSchoolModules) and
 * the school's own setting, which starts off. While the school has it off,
 * everything but the setting answers 403 inventory_off.
 */
class InventoryController extends Controller
{
    public function __construct(private InventoryService $store)
    {
    }

    public function callAction($method, $parameters)
    {
        if (! in_array($method, ['settings', 'updateSettings', 'parent'], true) && ! InventorySetting::enabledFor($this->schoolId())) {
            return response()->json(['message' => 'The school store is switched off for this school.', 'code' => 'inventory_off'], 403);
        }

        try {
            return $this->{$method}(...array_values($parameters));
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    // ------------------------------------------------------------ settings

    public function settings(): JsonResponse
    {
        return response()->json(['enabled' => InventorySetting::enabledFor($this->schoolId())]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate(['enabled' => 'required|boolean']);
        $s = InventorySetting::updateOrCreate(['school_id' => $schoolId], $data);
        AuditLog::create(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'action' => 'inventory.settings_updated', 'auditable_type' => InventorySetting::class, 'auditable_id' => $s->id, 'new_values' => $data]);

        return response()->json(['enabled' => (bool) $s->enabled, 'message' => $s->enabled ? 'The school store is on.' : 'The school store is off. Records are kept.']);
    }

    // ------------------------------------------------------------ overview

    public function summary(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $termId = $request->integer('term_id') ?: Term::where('school_id', $schoolId)->where('is_current', true)->value('id');
        $items = InventoryItem::where('school_id', $schoolId)->where('is_active', true)->get();
        $counts = InventoryIssue::where('school_id', $schoolId)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $charges = InventoryCharge::where('school_id', $schoolId)->when($termId, fn ($q) => $q->where('term_id', $termId))->where('status', '!=', 'void')
            ->selectRaw('status, COUNT(*) as n, COALESCE(SUM(amount), 0) as total')->groupBy('status')->get()->keyBy('status');

        return response()->json([
            'term_id' => $termId,
            'items' => $items->count(),
            'stock_value' => round($items->sum(fn ($i) => (float) $i->unit_price * max(0, $i->quantity_on_hand)), 2),
            'low_stock' => $items->filter->low_stock->values()->map(fn ($i) => ['id' => $i->id, 'label' => $i->label, 'quantity_on_hand' => $i->quantity_on_hand, 'reorder_level' => $i->reorder_level]),
            'awaiting_confirmation' => (int) ($counts['awaiting'] ?? 0),
            'disputed' => (int) ($counts['disputed'] ?? 0),
            'sales' => [
                'billed' => round((float) ($charges['posted']->total ?? 0), 2),
                'awaiting_invoice' => round((float) ($charges['pending']->total ?? 0), 2),
                'awaiting_invoice_count' => (int) ($charges['pending']->n ?? 0),
            ],
        ]);
    }

    // ------------------------------------------------------------ items

    public function items(Request $request): JsonResponse
    {
        $items = InventoryItem::where('school_id', $this->schoolId())
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('category')->orderBy('name')->orderBy('variant')->get();

        return response()->json(['data' => $items]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $this->validateItem($request, $schoolId);
        $opening = (int) ($request->validate(['opening_stock' => 'sometimes|integer|min:0|max:100000'])['opening_stock'] ?? 0);

        $item = InventoryItem::create($data + ['school_id' => $schoolId, 'quantity_on_hand' => 0]);
        if ($opening > 0) {
            $item = $this->store->adjustStock($item, $opening, 'received', 'Opening stock', $request->user());
        }

        return response()->json(['item' => $item->fresh()], 201);
    }

    public function updateItem(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $item = InventoryItem::where('school_id', $schoolId)->findOrFail($id);
        $item->update($this->validateItem($request, $schoolId, $item));

        return response()->json(['item' => $item->fresh()]);
    }

    /** Stock in (a delivery) or a correction after a count. */
    public function adjust(Request $request, int $id): JsonResponse
    {
        $item = InventoryItem::where('school_id', $this->schoolId())->findOrFail($id);
        $data = $request->validate([
            'change' => 'required|integer|not_in:0|between:-100000,100000',
            'reason' => 'required|in:received,adjusted',
            'note' => 'nullable|string|max:255',
        ]);

        if ($data['reason'] === 'received' && $data['change'] < 0) {
            throw new DomainRefusal('A delivery adds stock. Use a correction to take stock off.');
        }

        return response()->json(['item' => $this->store->adjustStock($item, (int) $data['change'], $data['reason'], $data['note'] ?? null, $request->user())]);
    }

    public function movements(int $id): JsonResponse
    {
        $item = InventoryItem::where('school_id', $this->schoolId())->findOrFail($id);
        $rows = InventoryStockMovement::where('item_id', $item->id)->orderByDesc('id')->limit(200)
            ->leftJoin('users', 'users.id', '=', 'inventory_stock_movements.created_by')
            ->get(['inventory_stock_movements.*', 'users.name as by_name']);

        return response()->json(['item' => $item, 'data' => $rows]);
    }

    // ------------------------------------------------------------ packs

    public function packs(): JsonResponse
    {
        $packs = InventoryPack::where('school_id', $this->schoolId())->with('items')->orderBy('name')->get();

        return response()->json(['data' => $packs->map(fn ($p) => $this->presentPack($p))]);
    }

    public function storePack(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $this->validatePack($request, $schoolId);
        $pack = DB::transaction(function () use ($data, $schoolId) {
            $pack = InventoryPack::create(['school_id' => $schoolId, 'name' => $data['name'], 'class_id' => $data['class_id'] ?? null, 'is_active' => $data['is_active'] ?? true]);
            $pack->items()->sync($this->packItems($data['items']));

            return $pack;
        });

        return response()->json(['pack' => $this->presentPack($pack->load('items'))], 201);
    }

    public function updatePack(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $pack = InventoryPack::where('school_id', $schoolId)->findOrFail($id);
        $data = $this->validatePack($request, $schoolId, true);

        DB::transaction(function () use ($pack, $data) {
            $pack->update(collect($data)->only(['name', 'class_id', 'is_active'])->all());
            if (isset($data['items'])) {
                $pack->items()->sync($this->packItems($data['items']));
            }
        });

        return response()->json(['pack' => $this->presentPack($pack->fresh('items'))]);
    }

    // ------------------------------------------------------------ hand-overs

    public function issues(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $issues = InventoryIssue::where('school_id', $schoolId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->with(['lines', 'student.user:id,name', 'student.currentClass:id,name'])
            ->orderByDesc('id')->limit(300)->get();

        $withParent = DB::table('student_guardian')->whereIn('student_id', $issues->pluck('student_id')->unique())->distinct()->pluck('student_id')->flip();

        return response()->json(['data' => $issues->map(fn ($i) => $this->presentIssue($i) + ['has_parent' => isset($withParent[$i->student_id])])]);
    }

    public function issue(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'term_id' => ['nullable', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'pack_id' => ['nullable', Rule::exists('inventory_packs', 'id')->where('school_id', $schoolId)],
            'lines' => 'sometimes|array|max:50',
            'lines.*.item_id' => ['required', Rule::exists('inventory_items', 'id')->where('school_id', $schoolId)],
            'lines.*.quantity' => 'required|integer|min:1|max:1000',
        ]);

        $student = Student::where('school_id', $schoolId)->findOrFail($data['student_id']);
        $issue = $this->store->issue($student, $data['term_id'] ?? null, $data['pack_id'] ?? null, $data['lines'] ?? [], $request->user());

        return response()->json(['issue' => $this->presentIssue($issue->load(['student.user:id,name', 'student.currentClass:id,name']))], 201);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $issue = InventoryIssue::where('school_id', $this->schoolId())->findOrFail($id);
        $data = $request->validate([
            'resolution' => 'required|in:redelivered,cancelled',
            'restock' => 'sometimes|boolean',
            'note' => 'nullable|string|max:500',
        ]);

        $issue = $this->store->resolve($issue, $data['resolution'], (bool) ($data['restock'] ?? true), $data['note'] ?? null, $request->user());

        return response()->json(['issue' => $this->presentIssue($issue)]);
    }

    /** Post charges that were waiting for the student's term invoice. */
    public function postCharges(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate(['term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)]]);
        $result = $this->store->postPending($schoolId, (int) $data['term_id'], $request->user());

        return response()->json($result + ['message' => "{$result['posted']} charge(s) added to term invoices.".($result['awaiting_invoice'] ? " {$result['awaiting_invoice']} still wait for the student's term invoice in Finance." : '')]);
    }

    // ------------------------------------------------------------ parents

    /** A parent's children's hand-overs. Answers even while the store is off, with nothing in it. */
    public function parent(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();

        if (! InventorySetting::enabledFor($schoolId)) {
            return response()->json(['enabled' => false, 'awaiting' => 0, 'data' => []]);
        }

        $childIds = Student::whereHas('guardians', fn ($q) => $q->where('guardians.user_id', $request->user()->id))->pluck('id');
        $issues = InventoryIssue::where('school_id', $schoolId)->whereIn('student_id', $childIds)
            ->with(['lines', 'student.user:id,name'])->orderByDesc('id')->limit(100)->get();

        return response()->json([
            'enabled' => true,
            'awaiting' => $issues->where('status', 'awaiting')->count(),
            'data' => $issues->map(fn ($i) => $this->presentIssue($i, false)),
        ]);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        $issue = InventoryIssue::where('school_id', $this->schoolId())->findOrFail($id);
        abort_unless(Student::findOrFail($issue->student_id)->isGuardedBy($request->user()), 404);

        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.id' => 'required|integer',
            'lines.*.received' => 'required|boolean',
            'note' => 'nullable|string|max:500',
        ]);

        $received = collect($data['lines'])->mapWithKeys(fn ($l) => [(int) $l['id'] => (bool) $l['received']])->all();
        $issue = $this->store->confirm($issue, $received, $data['note'] ?? null, $request->user());

        return response()->json([
            'issue' => $this->presentIssue($issue->load('student.user:id,name'), false),
            'message' => $issue->status === 'confirmed' ? 'Thank you. The school can see you received everything.' : 'Thank you. The school has been told what is missing.',
        ]);
    }

    // ------------------------------------------------------------ helpers

    private function validateItem(Request $request, int $schoolId, ?InventoryItem $item = null): array
    {
        $sometimes = $item ? 'sometimes|' : '';
        $variant = $request->input('variant', $item?->variant);

        return $request->validate([
            'name' => [$item ? 'sometimes' : 'required', 'string', 'max:120',
                Rule::unique('inventory_items', 'name')->where('school_id', $schoolId)->where(fn ($q) => $variant === null || $variant === '' ? $q->whereNull('variant') : $q->where('variant', $variant))->ignore($item?->id)],
            'category' => $sometimes.'required|in:'.implode(',', InventoryItem::CATEGORIES),
            'variant' => 'sometimes|nullable|string|max:60',
            'unit_price' => $sometimes.'required|numeric|min:0|max:10000000',
            'charge_to_fees' => 'sometimes|boolean',
            'reorder_level' => 'sometimes|integer|min:0|max:100000',
            'is_active' => 'sometimes|boolean',
        ], ['name.unique' => 'There is already an item with that name and size.']);
    }

    private function validatePack(Request $request, int $schoolId, bool $partial = false): array
    {
        $s = $partial ? 'sometimes|' : '';

        return $request->validate([
            'name' => $s.'required|string|max:120',
            'class_id' => ['sometimes', 'nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'is_active' => 'sometimes|boolean',
            'items' => $s.'required|array|min:1|max:50',
            'items.*.item_id' => ['required', Rule::exists('inventory_items', 'id')->where('school_id', $schoolId)],
            'items.*.quantity' => 'required|integer|min:1|max:100',
        ]);
    }

    private function packItems(array $items): array
    {
        return collect($items)->mapWithKeys(fn ($i) => [(int) $i['item_id'] => ['quantity' => (int) $i['quantity']]])->all();
    }

    private function presentPack(InventoryPack $p): array
    {
        $items = $p->items->map(fn ($i) => ['item_id' => $i->id, 'label' => $i->label, 'quantity' => (int) $i->pivot->quantity, 'unit_price' => $i->unit_price, 'charge_to_fees' => $i->charge_to_fees, 'quantity_on_hand' => $i->quantity_on_hand]);

        return [
            'id' => $p->id, 'name' => $p->name, 'class_id' => $p->class_id, 'is_active' => $p->is_active,
            'items' => $items,
            'charged_total' => round($items->filter(fn ($i) => $i['charge_to_fees'])->sum(fn ($i) => (float) $i['unit_price'] * $i['quantity']), 2),
        ];
    }

    private function presentIssue(InventoryIssue $i, bool $staff = true): array
    {
        $lines = $i->lines->map(fn ($l) => [
            'id' => $l->id, 'item_name' => $l->item_name, 'variant' => $l->variant, 'quantity' => $l->quantity,
            'unit_price' => $l->unit_price, 'charged' => $l->charged, 'status' => $l->status,
        ]);

        return [
            'id' => $i->id,
            'student_id' => $i->student_id,
            'student_name' => $i->student?->user?->name,
            'term_id' => $i->term_id,
            'status' => $i->status,
            'issued_at' => $i->created_at?->toIso8601String(),
            'confirmed_at' => $i->confirmed_at?->toIso8601String(),
            'parent_note' => $i->parent_note,
            'resolution_note' => $i->resolution_note,
            'lines' => $lines,
            'charged_total' => round($lines->filter(fn ($l) => $l['charged'] && $l['status'] !== 'cancelled')->sum(fn ($l) => (float) $l['unit_price'] * $l['quantity']), 2),
        ] + ($staff ? ['admission_number' => $i->student?->admission_number, 'class_name' => $i->student?->currentClass?->name] : []);
    }
}
