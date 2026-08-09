<?php

namespace App\Services;

use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turning a fee structure into money someone owes (§7.12, "automated
 * invoicing").
 *
 * This was the hole in the finance module. An admin could define a fee
 * structure and the platform could take payments against an invoice, but
 * nothing in the codebase ever created an invoice — `Invoice::create` appeared
 * in no controller, service or job. Fees were defined into a void and no parent
 * was ever billed.
 *
 * The design constraint that shapes everything below is that a bursar will
 * press Generate more than once: after admitting three new students in week
 * two, after adding a mid-term levy, or simply because they are not sure it
 * worked the first time. Every one of those has to be safe.
 */
class InvoiceGenerationService
{
    /**
     * Bill every eligible student for a term.
     *
     * Re-runnable by construction: a fee already billed to a student is skipped
     * (enforced by a unique index, not just by this check), a newly added fee is
     * appended to the invoice the student already has, and a student admitted
     * after the first run gets a fresh invoice on the next one.
     *
     * @param  int|null  $classId  Restrict the run to one class; null bills the whole school.
     */
    public function generateForTerm(
        int $schoolId,
        Term $term,
        ?int $classId = null,
        ?Carbon $dueDate = null
    ): array {
        $fees = FeeStructure::where('school_id', $schoolId)
            ->where('term_id', $term->id)
            /*
             * Optional fees (uniform, excursion, after-school club) are opt-in
             * purchases, not liabilities. Auto-billing them would put every
             * family into the defaulter list for a trip they never agreed to.
             */
            ->where('is_mandatory', true)
            ->get();

        if ($fees->isEmpty()) {
            return $this->emptyResult('No mandatory fee structures are defined for this term.');
        }

        $students = Student::where('school_id', $schoolId)
            // Graduated, transferred and suspended students are not billed.
            ->where('status', 'active')
            ->when($classId !== null, fn ($q) => $q->where('class_id', $classId))
            ->get();

        if ($students->isEmpty()) {
            return $this->emptyResult('No active students match this selection.');
        }

        $summary = [
            'invoices_created' => 0,
            'invoices_updated' => 0,
            'students_unchanged' => 0,
            'lines_added' => 0,
            'total_billed' => 0.0,
        ];

        DB::transaction(function () use ($students, $fees, $schoolId, $term, $dueDate, &$summary) {
            foreach ($students as $student) {
                $applicable = $fees->filter(
                    fn (FeeStructure $fee) => $fee->appliesToClass($student->class_id ? (int) $student->class_id : null)
                );

                if ($applicable->isEmpty()) {
                    $summary['students_unchanged']++;

                    continue;
                }

                $this->billStudent($student, $applicable, $schoolId, $term, $dueDate, $summary);
            }
        });

        $summary['total_billed'] = round($summary['total_billed'], 2);

        return [
            'message' => $this->describe($summary),
            'currency' => 'NGN',
            'term_id' => $term->id,
            'summary' => $summary,
        ];
    }

    /**
     * Add whatever this student is not yet billed for to their term invoice.
     */
    private function billStudent(
        Student $student,
        Collection $applicable,
        int $schoolId,
        Term $term,
        ?Carbon $dueDate,
        array &$summary
    ): void {
        $invoice = Invoice::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->first();

        $isNew = $invoice === null;

        if ($isNew) {
            $invoice = Invoice::create([
                'school_id' => $schoolId,
                'term_id' => $term->id,
                'student_id' => $student->id,
                'invoice_number' => $this->invoiceNumber($term, $student),
                'total_amount' => 0,
                'amount_paid' => 0,
                'status' => 'unpaid',
                'due_date' => $dueDate,
            ]);
        } elseif ($dueDate !== null) {
            // A re-run with a new deadline moves the existing bill's due date;
            // otherwise the ageing buckets keep reporting against a date the
            // school has already extended.
            $invoice->due_date = $dueDate;
        }

        $alreadyBilled = InvoiceItem::where('invoice_id', $invoice->id)
            ->pluck('fee_structure_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $newFees = $applicable->reject(
            fn (FeeStructure $fee) => in_array((int) $fee->id, $alreadyBilled, true)
        );

        if ($newFees->isEmpty()) {
            // Idempotent path: nothing owing that we have not already raised.
            if ($invoice->isDirty()) {
                $invoice->save();
            }
            $summary['students_unchanged']++;

            return;
        }

        $added = 0.0;

        foreach ($newFees as $fee) {
            InvoiceItem::create([
                'school_id' => $schoolId,
                'invoice_id' => $invoice->id,
                'fee_structure_id' => $fee->id,
                'title' => $fee->title,
                'amount' => $fee->amount,
            ]);

            $added += (float) $fee->amount;
            $summary['lines_added']++;
        }

        /*
         * Incremented, not recomputed from the line items.
         *
         * A bursary applied through FeeCollectionController::applyDiscount
         * writes the reduced figure straight onto `total_amount` and leaves the
         * lines alone. Re-deriving the total from its items here would quietly
         * reinstate every discount the school had granted — the kind of bug a
         * parent discovers at the school gate.
         */
        $invoice->total_amount = round((float) $invoice->total_amount + $added, 2);
        $invoice->status = $this->deriveStatus((float) $invoice->total_amount, (float) $invoice->amount_paid);
        $invoice->save();

        $summary[$isNew ? 'invoices_created' : 'invoices_updated']++;
        $summary['total_billed'] += $added;
    }

    /**
     * Deterministic, so a concurrent double-submit collides on the unique index
     * rather than minting a second invoice number for the same child and term.
     */
    private function invoiceNumber(Term $term, Student $student): string
    {
        return sprintf('INV-%d-%05d', $term->id, $student->id);
    }

    private function deriveStatus(float $total, float $paid): string
    {
        if ($paid <= 0) {
            return $total <= 0 ? 'paid' : 'unpaid';
        }

        return $paid >= $total ? 'paid' : 'partial';
    }

    private function describe(array $summary): string
    {
        if ($summary['lines_added'] === 0) {
            return 'Every eligible student is already invoiced for this term. Nothing was double-charged.';
        }

        return sprintf(
            '%d invoice(s) raised, %d updated with newly added fees.',
            $summary['invoices_created'],
            $summary['invoices_updated']
        );
    }

    private function emptyResult(string $message): array
    {
        return [
            'message' => $message,
            'currency' => 'NGN',
            'summary' => [
                'invoices_created' => 0,
                'invoices_updated' => 0,
                'students_unchanged' => 0,
                'lines_added' => 0,
                'total_billed' => 0.0,
            ],
        ];
    }
}
