<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A negotiated wholesale rate for one school.
 *
 * `result_pin_price_tiers` is the public rate card every school buys against.
 * This table is the exception to it: the group that brought eight campuses over,
 * the state association that signed in bulk, the pilot school that got a launch
 * price. Deals like these are agreed on the phone and, until now, could only be
 * honoured by a SchoolPilot operator hand-typing a unit price on every manual
 * grant — which meant a school on a negotiated rate could never buy its own PINs
 * online without silently losing the discount.
 *
 * A flat price per PIN, not a discount off the card: the number here is exactly
 * what was agreed with the school, so raising the public card later cannot
 * quietly move a price somebody shook hands on. The trade-off is that it does
 * not band by quantity — a school on a negotiated rate pays the same per PIN for
 * 50 as for 5,000 — so set it at the volume the deal actually assumed.
 *
 * Platform-level and deliberately not tenant-scoped: this is SchoolPilot's
 * commercial terms with a school, not the school's own data. It is kept out of
 * the `schools` table for the same reason — the school's own dashboard reads
 * that row, and what SchoolPilot charges is not a setting a school administers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('result_pin_school_rates')) {
            return;
        }

        Schema::create('result_pin_school_rates', function (Blueprint $table) {
            $table->id();
            // One live rate per school: a deal is a deal, not a stack of them.
            $table->foreignId('school_id')->unique()->constrained()->onDelete('cascade');
            $table->decimal('unit_price', 12, 2);
            /*
             * Lifting a rate deactivates the row instead of deleting it, so a
             * school that comes off a discount leaves the terms and the reason
             * behind. Resolution ignores inactive rows entirely.
             */
            $table->boolean('is_active')->default(true);
            // Why this school pays a different price. Required at the API, so a
            // rate never turns up in the ledger with nobody able to explain it.
            $table->text('notes')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The lookup on every quote and every purchase.
            $table->index(['school_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_pin_school_rates');
    }
};
