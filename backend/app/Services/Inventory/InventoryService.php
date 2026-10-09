<?php

namespace App\Services\Inventory;

use App\Exceptions\DomainRefusal;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InventoryCharge;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryItem;
use App\Models\InventoryPack;
use App\Models\InventoryStockMovement;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\InAppNotifier;
use Illuminate\Support\Facades\DB;

/**
 * The school store: stock, hand-overs to students, parent confirmation, and
 * the charges that reach the term invoice.
 *
 * Money rule (agreed with the school owner): the school decides per item
 * whether it is charged. A charged hand-over goes onto that term's invoice in
 * full — nothing is spread across terms.
 */
class InventoryService
{
    /** Receive stock or correct a count. `change` is signed. */
    public function adjustStock(InventoryItem $item, int $change, string $reason, ?string $note, User $by): InventoryItem
    {
        return DB::transaction(function () use ($item, $change, $reason, $note, $by) {
            $locked = InventoryItem::withoutGlobalScopes()->lockForUpdate()->findOrFail($item->id);
            $balance = $locked->quantity_on_hand + $change;

            if ($balance < 0) {
                throw new DomainRefusal("Only {$locked->quantity_on_hand} {$locked->label} in stock; you can't take off {$this->abs($change)}.");
            }

            $locked->update(['quantity_on_hand' => $balance]);
            $this->move($locked, $change, $reason, $note, null, $by);

            return $locked;
        });
    }

    /**
     * Hand items to a student: from a pack, single lines, or both. Stock comes
     * off at once; charged lines go to the term invoice; the student's parents
     * are asked to confirm.
     *
     * @param  array<int, array{item_id: int, quantity: int}>  $lines
     */
    public function issue(Student $student, ?int $termId, ?int $packId, array $lines, User $by): InventoryIssue
    {
        $schoolId = (int) $student->school_id;
        $wanted = [];

        if ($packId) {
            $pack = InventoryPack::withoutGlobalScopes()->where('school_id', $schoolId)->with('items')->findOrFail($packId);
            foreach ($pack->items as $item) {
                $wanted[$item->id] = ($wanted[$item->id] ?? 0) + (int) $item->pivot->quantity;
            }
        }
        foreach ($lines as $line) {
            $wanted[(int) $line['item_id']] = ($wanted[(int) $line['item_id']] ?? 0) + (int) $line['quantity'];
        }

        if ($wanted === []) {
            throw new DomainRefusal('Choose a pack or at least one item to hand over.');
        }

        $termId ??= Term::withoutGlobalScopes()->where('school_id', $schoolId)->where('is_current', true)->value('id');

        $issue = DB::transaction(function () use ($student, $schoolId, $termId, $packId, $wanted, $by) {
            $items = InventoryItem::withoutGlobalScopes()->where('school_id', $schoolId)->whereIn('id', array_keys($wanted))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $short = [];
            foreach ($wanted as $itemId => $qty) {
                $item = $items->get($itemId);
                if (! $item || ! $item->is_active) {
                    throw new DomainRefusal('One of the items is not in the store any more.');
                }
                if ($item->quantity_on_hand < $qty) {
                    $short[] = "{$item->label}: {$item->quantity_on_hand} left, {$qty} needed";
                }
            }
            if ($short !== []) {
                throw new DomainRefusal('Not enough stock. '.implode('; ', $short).'.');
            }

            $issue = InventoryIssue::create([
                'school_id' => $schoolId, 'student_id' => $student->id, 'term_id' => $termId, 'pack_id' => $packId,
                'status' => 'awaiting', 'issued_by' => $by->id,
            ]);

            foreach ($wanted as $itemId => $qty) {
                $item = $items->get($itemId);
                $item->update(['quantity_on_hand' => $item->quantity_on_hand - $qty]);
                $this->move($item, -$qty, 'issued', null, $issue->id, $by);

                $charged = $item->charge_to_fees && (float) $item->unit_price > 0;
                $line = InventoryIssueLine::create([
                    'issue_id' => $issue->id, 'item_id' => $item->id, 'item_name' => $item->name, 'variant' => $item->variant,
                    'quantity' => $qty, 'unit_price' => $item->unit_price, 'charged' => $charged, 'status' => 'pending',
                ]);

                if ($charged) {
                    $charge = InventoryCharge::create([
                        'school_id' => $schoolId, 'student_id' => $student->id, 'term_id' => $termId, 'issue_line_id' => $line->id,
                        'title' => "Store: {$item->label}".($qty > 1 ? " × {$qty}" : ''),
                        'amount' => round((float) $item->unit_price * $qty, 2), 'status' => 'pending',
                    ]);
                    $this->post($charge);
                }
            }

            $this->audit($by, $schoolId, 'inventory.issued', $issue->id, ['student_id' => $student->id, 'term_id' => $termId, 'items' => $wanted]);

            return $issue;
        });

        $name = $student->user?->name ?? 'your child';
        InAppNotifier::send($schoolId, $this->guardianUserIds($student), 'inventory', 'Items handed over', "The school has handed items to {$name}. Please confirm you received them.", true);

        return $issue->load('lines');
    }

    /**
     * A parent's answer: each pending line received or missing. Anything
     * missing needs a note, and the school is told.
     *
     * @param  array<int, bool>  $received  line id => received
     */
    public function confirm(InventoryIssue $issue, array $received, ?string $note, User $parent): InventoryIssue
    {
        if ($issue->status !== 'awaiting') {
            throw new DomainRefusal('This hand-over has already been answered.');
        }

        $pending = $issue->lines()->where('status', 'pending')->get();
        $missing = $pending->filter(fn ($l) => ($received[$l->id] ?? null) === false);

        if ($pending->contains(fn ($l) => ! array_key_exists($l->id, $received))) {
            throw new DomainRefusal('Tell us about every item on the list.');
        }
        if ($missing->isNotEmpty() && trim((string) $note) === '') {
            throw new DomainRefusal('Add a short note about what is missing, so the school can sort it out.');
        }

        DB::transaction(function () use ($issue, $pending, $received, $missing, $note, $parent) {
            foreach ($pending as $line) {
                $line->update(['status' => $received[$line->id] ? 'received' : 'missing']);
            }
            $issue->update([
                'status' => $missing->isEmpty() ? 'confirmed' : 'disputed',
                'parent_note' => $missing->isEmpty() ? $issue->parent_note : $note,
                'confirmed_by' => $parent->id,
                'confirmed_at' => now(),
            ]);
        });

        if ($missing->isNotEmpty()) {
            $admins = DB::table('user_profiles')->where('school_id', $issue->school_id)->where('role', 'school_admin')->pluck('user_id')->all();
            InAppNotifier::send((int) $issue->school_id, $admins, 'inventory', 'Item not received', 'A parent says an item handed to their child did not arrive. Open Store > Hand-overs to sort it out.');
        }

        return $issue->fresh('lines');
    }

    /**
     * The school's answer to a dispute.
     *
     * redelivered — the missing items have now been handed over; the parent
     *               is asked to confirm them again.
     * cancelled   — the missing items are taken off: their charge comes off
     *               the invoice, and (optionally) they go back into stock.
     */
    public function resolve(InventoryIssue $issue, string $resolution, bool $restock, ?string $note, User $by): InventoryIssue
    {
        if ($issue->status !== 'disputed') {
            throw new DomainRefusal('Only a hand-over a parent has disputed can be resolved.');
        }

        DB::transaction(function () use ($issue, $resolution, $restock, $note, $by) {
            $missing = $issue->lines()->where('status', 'missing')->get();

            if ($resolution === 'redelivered') {
                $issue->lines()->where('status', 'missing')->update(['status' => 'pending']);
                $issue->update(['status' => 'awaiting', 'resolution_note' => $note]);
            } else {
                foreach ($missing as $line) {
                    $this->voidCharge($line, $by);
                    if ($restock && $line->item_id) {
                        $item = InventoryItem::withoutGlobalScopes()->lockForUpdate()->find($line->item_id);
                        if ($item) {
                            $item->update(['quantity_on_hand' => $item->quantity_on_hand + $line->quantity]);
                            $this->move($item, $line->quantity, 'returned', 'Not received; hand-over cancelled', $issue->id, $by);
                        }
                    }
                    $line->update(['status' => 'cancelled']);
                }
                $issue->update(['status' => 'resolved', 'resolution_note' => $note]);
            }

            $this->audit($by, (int) $issue->school_id, 'inventory.dispute_resolved', $issue->id, ['resolution' => $resolution, 'restock' => $restock, 'lines' => $missing->pluck('id')]);
        });

        if ($resolution === 'redelivered') {
            $student = Student::withoutGlobalScopes()->find($issue->student_id);
            InAppNotifier::send((int) $issue->school_id, $student ? $this->guardianUserIds($student) : [], 'inventory', 'Items handed over again', 'The school says the missing items have now been handed over. Please confirm.', true);
        }

        return $issue->fresh('lines');
    }

    /** Post every pending charge for a term whose invoice now exists. */
    public function postPending(int $schoolId, int $termId, User $by): array
    {
        $posted = 0;
        $waiting = 0;

        DB::transaction(function () use ($schoolId, $termId, &$posted, &$waiting) {
            $charges = InventoryCharge::withoutGlobalScopes()->where('school_id', $schoolId)->where('term_id', $termId)->where('status', 'pending')->get();
            foreach ($charges as $charge) {
                $this->post($charge) ? $posted++ : $waiting++;
            }
        });

        $this->audit($by, $schoolId, 'inventory.charges_posted', 0, ['term_id' => $termId, 'posted' => $posted, 'awaiting_invoice' => $waiting]);

        return ['posted' => $posted, 'awaiting_invoice' => $waiting];
    }

    /** Onto the student's invoice for the charge's term, if that invoice exists yet. */
    private function post(InventoryCharge $charge): bool
    {
        if ($charge->status !== 'pending' || ! $charge->term_id) {
            return false;
        }

        $invoice = Invoice::withoutGlobalScopes()->where('school_id', $charge->school_id)->where('student_id', $charge->student_id)
            ->where('term_id', $charge->term_id)->lockForUpdate()->first();

        if (! $invoice) {
            return false;
        }

        $item = InvoiceItem::withoutGlobalScopes()->create(['school_id' => $charge->school_id, 'invoice_id' => $invoice->id, 'title' => $charge->title, 'amount' => $charge->amount]);
        $invoice->update(['total_amount' => (float) $invoice->total_amount + (float) $charge->amount, 'status' => (float) $invoice->amount_paid > 0 ? 'partial' : 'unpaid']);
        $charge->update(['status' => 'posted', 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id]);

        return true;
    }

    /**
     * Take a line's charge back off. A posted charge comes off the invoice
     * only while what's been paid still fits under the new total; otherwise
     * the money is with the school and Finance has to refund it first.
     */
    private function voidCharge(InventoryIssueLine $line, User $by): void
    {
        $charge = InventoryCharge::withoutGlobalScopes()->where('issue_line_id', $line->id)->lockForUpdate()->first();

        if (! $charge || $charge->status === 'void') {
            return;
        }

        if ($charge->status === 'posted' && $charge->invoice_id) {
            $invoice = Invoice::withoutGlobalScopes()->lockForUpdate()->find($charge->invoice_id);

            if ($invoice) {
                $before = ['total_amount' => (float) $invoice->total_amount, 'status' => $invoice->status];
                $newTotal = round((float) $invoice->total_amount - (float) $charge->amount, 2);
                $paid = (float) $invoice->amount_paid;

                if ($paid > $newTotal + 0.005) {
                    throw new DomainRefusal("{$charge->title} is already paid for. Record the refund in Finance, then cancel it here.");
                }

                InvoiceItem::withoutGlobalScopes()->where('id', $charge->invoice_item_id)->delete();
                $invoice->update([
                    'total_amount' => $newTotal,
                    'status' => $paid <= 0 ? ($newTotal <= 0 ? 'paid' : 'unpaid') : ($paid >= $newTotal ? 'paid' : 'partial'),
                ]);

                AuditLog::create([
                    'school_id' => $invoice->school_id, 'user_id' => $by->id, 'action' => 'invoice.store_item_removed',
                    'auditable_type' => Invoice::class, 'auditable_id' => $invoice->id,
                    'old_values' => $before, 'new_values' => ['total_amount' => $newTotal, 'status' => $invoice->status, 'charge' => $charge->title],
                ]);
            }
        }

        $charge->update(['status' => 'void', 'invoice_item_id' => null]);
    }

    private function move(InventoryItem $item, int $change, string $reason, ?string $note, ?int $issueId, User $by): void
    {
        InventoryStockMovement::withoutGlobalScopes()->create([
            'school_id' => $item->school_id, 'item_id' => $item->id, 'change' => $change, 'reason' => $reason,
            'note' => $note, 'issue_id' => $issueId, 'balance_after' => $item->quantity_on_hand, 'created_by' => $by->id,
        ]);
    }

    /** @return array<int, int> */
    public function guardianUserIds(Student $student): array
    {
        return DB::table('student_guardian')->join('guardians', 'guardians.id', '=', 'student_guardian.guardian_id')
            ->where('student_guardian.student_id', $student->id)->pluck('guardians.user_id')->filter()->all();
    }

    private function audit(User $by, int $schoolId, string $action, int $id, array $values): void
    {
        AuditLog::create(['school_id' => $schoolId, 'user_id' => $by->id, 'action' => $action, 'auditable_type' => InventoryIssue::class, 'auditable_id' => $id, 'new_values' => $values]);
    }

    private function abs(int $n): int
    {
        return $n < 0 ? -$n : $n;
    }
}
