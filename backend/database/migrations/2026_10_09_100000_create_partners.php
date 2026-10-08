<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The partner programme: people who bring schools to SchoolPilot and earn a
 * lifetime commission on every term bill those schools pay.
 *
 * The public rate is 30% (config `services.partners.default_rate_bps`).
 * `commission_rate_override` is a rate negotiated with one partner; it is
 * never shown to partners or the public, so the model hides it by default.
 *
 * A commission freezes the rate it was earned at, so raising a partner's rate
 * later changes future bills only. Amounts are kobo; rates are basis points
 * (3000 = 30%). Payouts are made outside the product and recorded by hand,
 * like school payments. Personal data is the partner's own contact and bank
 * details only: no student data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 12)->unique();
            $table->string('name', 120);
            $table->string('email', 150)->unique();
            $table->string('phone', 24)->nullable();
            $table->string('status', 12)->default('pending'); // pending | active | suspended
            // Private. Null means the public default applies.
            $table->unsignedSmallInteger('commission_rate_override')->nullable();
            // Encrypted at rest (cast on the model).
            $table->text('bank_name')->nullable();
            $table->text('account_number')->nullable();
            $table->text('account_name')->nullable();
            $table->text('pitch')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
        });

        Schema::table('marketing_leads', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
        });

        Schema::create('partner_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('partner_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // One commission per bill: a bill can never pay out twice.
            $table->foreignId('term_bill_id')->unique()->constrained('term_bills')->cascadeOnDelete();
            $table->unsignedSmallInteger('rate_bps');
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status', 10)->default('accrued'); // accrued | paid | void
            $table->foreignId('payout_id')->nullable()->constrained('partner_payouts')->nullOnDelete();
            $table->timestamps();

            $table->index(['partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_commissions');
        Schema::dropIfExists('partner_payouts');

        Schema::table('marketing_leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });

        Schema::dropIfExists('partners');
    }
};
