<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-school, per-day AI spend.
 *
 * LAUNCH.md §1 lists a daily AI budget cap with an alert at 80% as a pre-flight
 * item. Nothing tracked spend at all, so the cap could not be enforced and the
 * alert could not fire: a runaway tutor-chat loop could bill the platform for a
 * school's entire term in an afternoon.
 *
 * A table rather than a cache counter, for three reasons: the figures are
 * billing evidence and must survive a `cache:clear`, a proprietor querying
 * their own AI spend is a reasonable request, and the 80% alert needs yesterday
 * to compare against.
 *
 * Cost is stored in kobo. Anthropic bills in USD, but every other money column
 * in this product is Naira minor units, and a school's budget conversation
 * happens in Naira — the USD→NGN rate used for the conversion is configurable
 * (services.anthropic.usd_to_ngn) and stamped on each row so a historical
 * figure stays explainable after the rate moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->date('usage_date');

            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);

            // Cache reads are billed at a tenth of input; kept separate so the
            // cost line stays auditable against the Anthropic invoice.
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);

            $table->unsignedBigInteger('cost_kobo')->default(0);
            $table->unsignedInteger('calls')->default(0);

            // The rate this row's kobo figures were computed at.
            $table->decimal('usd_to_ngn', 10, 2);

            $table->timestamp('alerted_at')->nullable();

            $table->timestamps();

            // One row per school per day; the ledger upserts against this.
            $table->unique(['school_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_daily');
    }
};
