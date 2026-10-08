<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each school pays SchoolPilot (doc §13, priced 2026-10-08).
 *
 *  - `results`: ₦500 per active student per term — the whole platform plus
 *    result computation, printing and PINs.
 *  - `subscription`: ₦300 per active student per term after a 30-day free
 *    trial — the platform without SchoolPilot's result services.
 *  - Boarders, on either plan: ₦1,000 per boarding student per term in place
 *    of the plan rate.
 *
 * A term bill freezes the student count and rate when it is issued, so the
 * amount a school was told does not move if the roll changes afterwards.
 * Payment is collected outside the product and recorded here by hand.
 * School-level figures only: no personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('billing_plan', 20)->nullable();
            $table->date('trial_ends_on')->nullable();
        });

        Schema::create('term_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 20);
            // Everyone enrolled in the term, and how many of them board.
            // Boarders are charged the boarding rate; the rest the plan rate.
            $table->unsignedInteger('active_students');
            $table->unsignedBigInteger('rate_kobo');
            $table->unsignedInteger('boarding_students')->default(0);
            $table->unsignedBigInteger('boarding_rate_kobo')->default(0);
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status', 10)->default('issued'); // issued | paid | waived
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'term_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_bills');

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['billing_plan', 'trial_ends_on']);
        });
    }
};
