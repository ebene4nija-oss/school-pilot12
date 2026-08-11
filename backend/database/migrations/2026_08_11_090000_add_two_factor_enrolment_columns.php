<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the two-factor enrolment flow needs.
 *
 * `two_factor_enabled` and `two_factor_secret` already existed, and login
 * already verified against them — but nothing could ever write them, because
 * there was no enrolment endpoint. These three complete the flow:
 *
 *  - confirmed_at   distinguishes "a secret has been issued and shown as a QR"
 *                   from "the admin proved they can generate codes from it".
 *                   Without it, a half-finished enrolment locks the account:
 *                   the secret is stored, the phone never scanned it.
 *  - recovery_codes single-use fallbacks. A proprietor who drops their phone in
 *                   the middle of term must not lose the school.
 *  - enforced_at    when this account was told 2FA is mandatory, for the audit
 *                   trail the NDPA review asks for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');

            // Encrypted JSON of hashed codes — long, so text rather than string.
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_confirmed_at');

            $table->timestamp('two_factor_enforced_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_confirmed_at',
                'two_factor_recovery_codes',
                'two_factor_enforced_at',
            ]);
        });
    }
};
