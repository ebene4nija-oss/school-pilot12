<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Result-checker PINs — the Nigerian scratch-card model, two tiers.
 *
 * Tier 1 (school -> SchoolPilot): a school buys a batch of N PINs at the
 * wholesale rate SchoolPilot publishes in `result_pin_price_tiers`. Paid by
 * card through the existing gateway, or granted by a SchoolPilot operator for
 * a school that paid by bank transfer or arranged it over the phone.
 *
 * Tier 2 (guardian -> school): a guardian spends one PIN from that school's
 * inventory to open a child's report card, at whatever retail price the school
 * set. Either online from the dashboard, or over the counter at the school —
 * in which case a bursar sells them the printed code and they redeem it.
 *
 * The money in tier 2 is the school's, not SchoolPilot's. SchoolPilot's revenue
 * is the wholesale batch sale only, which is why `result_pin_sales.amount` is
 * recorded per school and never reconciled against the platform rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * SchoolPilot's published wholesale rate card. Quantity-banded: a
         * school buying 1,000 PINs pays less per PIN than one buying 50.
         * Platform-level, so deliberately no school_id and no tenant scope.
         */
        if (!Schema::hasTable('result_pin_price_tiers')) {
            Schema::create('result_pin_price_tiers', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('min_quantity');
                $table->decimal('unit_price', 12, 2);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique('min_quantity');
                $table->index('is_active');
            });
        }

        /*
         * One wholesale purchase of PIN inventory by a school.
         *
         * `status` starts 'pending' for an online purchase and only becomes
         * 'active' when the gateway webhook confirms payment — the PINs
         * themselves are not minted until then, so an abandoned checkout
         * cannot leave sellable stock behind. A manual grant is born 'active'
         * because a SchoolPilot operator has already seen the bank alert.
         */
        if (!Schema::hasTable('result_pin_batches')) {
            Schema::create('result_pin_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->string('reference')->unique();
                $table->unsignedInteger('quantity');
                // Wholesale rate locked at purchase time. Stored per batch, not
                // read back from the tier table, so a later price change does
                // not rewrite the history of what a school actually paid.
                $table->decimal('unit_price', 12, 2);
                $table->decimal('total_amount', 12, 2);
                $table->enum('source', ['online', 'manual_grant'])->default('online');
                $table->enum('gateway', ['paystack', 'flutterwave', 'bank_transfer', 'cash'])->nullable();
                $table->enum('status', ['pending', 'active', 'cancelled'])->default('pending');
                // The SchoolPilot operator who granted an unpaid batch. Null
                // for online purchases, where the gateway is the authority.
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'status']);
            });
        }

        /*
         * The PINs themselves.
         *
         * Two representations of the code, for two different jobs:
         *
         *  - `pin_hash` is HMAC-SHA256(code, APP_KEY), uniquely indexed. Redeem
         *    is a single indexed lookup. Bcrypt cannot be looked up, and would
         *    turn redemption into a full-table scan of every unsold PIN.
         *  - `pin_secret` is the Crypt-encrypted code. A bursar selling a PIN
         *    over the counter has to be able to read it off the screen, so this
         *    genuinely cannot be one-way. Reveal is admin-only and audited.
         *
         * `serial` is the non-secret handle — safe in receipts, lists, support
         * tickets and logs, so nothing has to echo the code to identify a PIN.
         */
        if (!Schema::hasTable('result_pins')) {
            Schema::create('result_pins', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('batch_id')->nullable()->constrained('result_pin_batches')->nullOnDelete();

                $table->string('serial')->unique();
                $table->string('pin_hash', 64)->unique();
                $table->text('pin_secret');

                /*
                 * 'batch'     — normal paid stock.
                 * 'waiver'    — school exempted this child (scholarship, hardship).
                 *               No batch, never came from paid stock.
                 * 'overdraft' — a guardian's payment settled after the school's
                 *               stock ran out. The guardian paid, so they get
                 *               their result; the school owes SchoolPilot for
                 *               the PIN. Recorded as its own origin so the debt
                 *               is visible rather than absorbed silently.
                 */
                $table->enum('origin', ['batch', 'waiver', 'overdraft'])->default('batch');
                $table->enum('status', ['available', 'sold', 'used', 'void'])->default('available');

                /*
                 * Null until first use. A PIN is stock, not an entitlement,
                 * until someone spends it — at which point it binds to exactly
                 * one child and one term and can never be moved to another.
                 */
                $table->foreignId('student_id')->nullable()->constrained()->onDelete('cascade');
                $table->foreignId('term_id')->nullable()->constrained()->onDelete('cascade');

                // Views, not a single use: a guardian who checks on their phone
                // and again on a laptop has not bought two results.
                $table->unsignedInteger('views_used')->default(0);
                $table->unsignedInteger('max_views')->default(5);

                $table->foreignId('sold_to')->nullable()->constrained('users')->nullOnDelete();
                $table->enum('sold_channel', ['online', 'counter', 'waiver'])->nullable();
                $table->decimal('sold_amount', 12, 2)->nullable();
                $table->timestamp('sold_at')->nullable();

                $table->timestamp('first_used_at')->nullable();
                $table->timestamp('last_used_at')->nullable();

                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('void_reason')->nullable();
                // Why a waiver or overdraft PIN exists. Kept apart from
                // void_reason so "granted because scholarship" and "cancelled
                // because printed in error" cannot be confused in an audit.
                $table->string('grant_reason')->nullable();

                $table->timestamps();

                // Allocating stock: the hot path when a purchase settles.
                $table->index(['school_id', 'status']);
                // The access check on every gated report-card read.
                $table->index(['school_id', 'student_id', 'term_id']);
            });
        }

        /*
         * A guardian's online PIN purchase, pending until the webhook lands.
         *
         * Exists separately from `payments` because that table's `invoice_id`
         * is a non-nullable FK to a student fee invoice. A result PIN is not a
         * fee, has no invoice, and must not appear in fee collection totals or
         * the defaulter report.
         */
        if (!Schema::hasTable('result_pin_sales')) {
            Schema::create('result_pin_sales', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('student_id')->constrained()->onDelete('cascade');
                $table->foreignId('term_id')->constrained()->onDelete('cascade');
                $table->foreignId('purchased_by')->constrained('users')->onDelete('cascade');

                $table->string('reference')->unique();
                $table->decimal('amount', 12, 2);
                $table->enum('gateway', ['paystack', 'flutterwave'])->default('paystack');
                $table->enum('status', ['pending', 'successful', 'failed'])->default('pending');

                // Set when the webhook confirms and stock is allocated.
                $table->foreignId('result_pin_id')->nullable()->constrained('result_pins')->nullOnDelete();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'status']);
                $table->index(['purchased_by', 'status']);
            });
        }

        /*
         * "The result is ready."
         *
         * Nothing in the schema previously expressed this: scores trickle in
         * subject by subject over the marking period, and a report card built
         * halfway through is simply wrong. Charging a guardian to look at one
         * would be charging them for a mistake, so the paywall must sit behind
         * an explicit release.
         *
         * A null class_id releases the whole school for that term; a row with a
         * class_id releases just that class, because JSS3 marking finishing two
         * weeks before SS2 is the normal case, not the exception.
         */
        if (!Schema::hasTable('result_releases')) {
            Schema::create('result_releases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('term_id')->constrained()->onDelete('cascade');
                $table->foreignId('class_id')->nullable()->constrained('classes')->onDelete('cascade');
                // Kept as a flag rather than deleting the row, so pulling a
                // result back after a marking error leaves a trace.
                $table->boolean('is_released')->default(true);
                $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('released_at')->nullable();
                $table->timestamps();

                $table->unique(['school_id', 'term_id', 'class_id']);
                $table->index(['school_id', 'term_id', 'is_released']);
            });
        }

        /*
         * Per-school result-checker settings.
         *
         * Off by default: switching on a paywall for every existing school on
         * deploy would be a change none of them agreed to.
         */
        Schema::table('schools', function (Blueprint $table) {
            if (!Schema::hasColumn('schools', 'result_checker_enabled')) {
                $table->boolean('result_checker_enabled')->default(false);
            }
            if (!Schema::hasColumn('schools', 'result_pin_retail_price')) {
                $table->decimal('result_pin_retail_price', 12, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            if (Schema::hasColumn('schools', 'result_checker_enabled')) {
                $table->dropColumn('result_checker_enabled');
            }
            if (Schema::hasColumn('schools', 'result_pin_retail_price')) {
                $table->dropColumn('result_pin_retail_price');
            }
        });

        Schema::dropIfExists('result_releases');
        Schema::dropIfExists('result_pin_sales');
        Schema::dropIfExists('result_pins');
        Schema::dropIfExists('result_pin_batches');
        Schema::dropIfExists('result_pin_price_tiers');
    }
};
