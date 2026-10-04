<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ID card production and issuance (TASK 18).
 *
 * `status` stays what it was, the card's validity (active, revoked, expired,
 * replaced). Production is a separate axis, because "a card is not issued
 * merely because it was generated or printed" (§22.5): generated → a print
 * file exists → physically printed → handed over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('id_cards', function (Blueprint $table) {
            $table->string('production_status', 20)->default('generated')->index(); // generated | print_ready | printed | issued
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->nullOnDelete();
            // "Self", or the name of whoever collected it (a parent, a driver).
            $table->string('collected_by', 120)->nullable();
            $table->unsignedSmallInteger('misprints')->default(0);
        });

        // Cards already in circulation were handed out under the old flow.
        DB::table('id_cards')->update(['production_status' => 'issued']);

        Schema::table('id_card_print_runs', function (Blueprint $table) {
            // "Print file generated" and "physically printed" are different (§11).
            $table->string('physical_status', 20)->default('not_confirmed'); // not_confirmed | printed
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->json('misprinted_card_ids')->nullable();
        });

        Schema::create('id_card_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('enabled_types')->nullable(); // student, staff
            // Off by default so cards already in pockets keep verifying; on,
            // a card verifies only once it has been handed over.
            $table->boolean('valid_only_when_issued')->default(false);
            $table->unsignedSmallInteger('staff_validity_months')->nullable();
            $table->unsignedSmallInteger('expiry_warning_days')->default(30);
            $table->boolean('digital_cards_enabled')->default(false);
            $table->boolean('parents_see_digital_card')->default(false);
            $table->boolean('log_verifications')->default(true);
            $table->timestamps();
        });

        // Every production and validity change, append-only.
        Schema::create('id_card_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained('id_cards')->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('from', 20)->nullable();
            $table->string('to', 20)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['card_id', 'id']);
        });

        // Who checked which card, and what they were told. No IP, no device:
        // the card and the outcome are what an audit needs (NDPA, doc §12).
        Schema::create('id_card_verification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->nullable()->constrained('id_cards')->cascadeOnDelete();
            $table->string('outcome', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_card_verification_logs');
        Schema::dropIfExists('id_card_status_events');
        Schema::dropIfExists('id_card_settings');
        Schema::table('id_card_print_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['physical_status', 'confirmed_at', 'misprinted_card_ids']);
        });
        Schema::table('id_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handed_over_by');
            $table->dropColumn(['production_status', 'printed_at', 'handed_over_at', 'collected_by', 'misprints']);
        });
    }
};
