<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each school's own merchant account (gap G8).
 *
 * Until now the only gateway credentials in the product were a single
 * platform-wide pair in `config/services.php`, which meant every school's fees
 * settled into one SchoolPilot merchant account. Schools hold their own
 * Paystack / Flutterwave accounts, so the credentials belong per tenant.
 *
 * A separate table rather than columns on `schools` for three reasons: a school
 * can hold both gateways at once, the row carries its own audit fields, and
 * secrets stay out of the model that is loaded on every single request by the
 * tenant middleware.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->string('gateway'); // paystack | flutterwave

            /*
             * `text`, not `string`. These hold Laravel-encrypted ciphertext —
             * a base64 envelope several times the length of the key itself, so
             * a 255-char column silently truncates and the key decrypts to
             * garbage at the worst possible moment.
             */
            $table->text('public_key')->nullable();
            $table->text('secret_key')->nullable();

            /*
             * Flutterwave verifies webhooks against a static hash the merchant
             * sets in their own dashboard, so it is a third value the school
             * must give us. Paystack signs with the secret key and leaves this
             * null.
             */
            $table->text('webhook_secret')->nullable();

            /*
             * The last four characters of the secret, in clear. This is what
             * the settings screen shows so an admin can tell which key is
             * loaded without the server ever handing the key back.
             */
            $table->string('secret_last4', 8)->nullable();

            // Live vs test keys, taken from the key itself rather than asked
            // for — a school that pastes test keys into production should see
            // that on the settings screen, not discover it at first payment.
            $table->string('mode')->default('test'); // test | live

            $table->boolean('is_active')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One row per gateway per school; the settings endpoint upserts.
            $table->unique(['school_id', 'gateway']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_payment_gateways');
    }
};
