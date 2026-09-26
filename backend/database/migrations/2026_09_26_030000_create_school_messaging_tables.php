<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-school SMS, and per-school limits on email.
 *
 * SMS used to be one platform-wide Termii key. Schools here commonly send
 * through a phone-based SMS gateway instead: an Android handset on the school's
 * own SIM relays the messages, so parents see the school's own number and the
 * school pays its own airtime. That makes SMS configuration per school by
 * nature — the platform cannot hold one key that sends from forty schools'
 * phones.
 *
 * Email limits live on `schools` because the platform, not the school, sets
 * them: a school admin who can raise their own cap is not a cap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_sms_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->onDelete('cascade');

            // phone_gateway: an Android SMS gateway app relaying through the
            // school's own SIM. termii: a bulk SMS account.
            $table->string('provider', 32);

            // The gateway's own server, e.g. https://sms.example.com. Unused by
            // Termii, whose endpoint is fixed.
            $table->string('server_url')->nullable();

            // Ciphertext — cast `encrypted` on the model.
            $table->text('api_key');

            // Which handset, or handset and SIM slot ("1|0"), sends. Empty
            // means the gateway's primary device.
            $table->string('device', 32)->nullable();

            // Termii's registered sender name. A phone gateway sends from the
            // SIM's own number and ignores it.
            $table->string('sender_id', 11)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 16)->nullable();
            $table->string('last_test_message', 255)->nullable();
            $table->timestamps();
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('email_enabled')->default(true);
            // Null means the platform default applies.
            $table->unsignedInteger('email_daily_limit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['email_enabled', 'email_daily_limit']);
        });

        Schema::dropIfExists('school_sms_gateways');
    }
};
