<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice line items — what an invoice is actually made of.
 *
 * `invoices` carried a single `total_amount` and no record of how it was
 * arrived at, so nothing connected a bill to the fee structures that produced
 * it. Three consequences, all of which schools hit immediately:
 *
 *  - A parent querying a bill got one number and no breakdown. The answer to
 *    "why is it ₦66,000?" lived in the bursar's head.
 *  - Generation could not be re-run. A school that adds a mid-term ICT levy
 *    after invoicing had no way to bill it without either double-charging
 *    everyone or issuing a second invoice per child.
 *  - Deleting a fee structure left already-issued bills unexplainable.
 *
 * The unique index on (invoice_id, fee_structure_id) is the idempotency
 * guarantee: a given fee lands on a given invoice exactly once, no matter how
 * many times the bursar hits Generate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');

            /*
             * Nullable and set-null on delete: an issued invoice is a financial
             * record. If the school retires the "Lab / ICT" fee next term, the
             * line stays on the bills that already carried it — the title and
             * amount below are denormalised precisely so the history survives
             * the structure being deleted or repriced.
             */
            $table->foreignId('fee_structure_id')->nullable()->constrained()->onDelete('set null');

            $table->string('title');
            $table->decimal('amount', 12, 2)->default(0.00);
            $table->timestamps();

            $table->unique(['invoice_id', 'fee_structure_id']);
            $table->index(['school_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
