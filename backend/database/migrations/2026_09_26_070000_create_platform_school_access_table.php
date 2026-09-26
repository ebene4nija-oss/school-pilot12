<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An operator's time-limited, stated-reason access to one school.
 *
 * "Open" in the operator console used to set a cookie and nothing else: the
 * API honoured the matching header for any super admin, forever, with no
 * record of who looked at which school's children or why. Each grant is now a
 * row, and the header is honoured only while one is live.
 *
 * Also: when a trial ends. Nothing recorded it, so "trial" was a status with
 * no end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_school_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->string('reason', 500);
            $table->timestamp('expires_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'school_id', 'expires_at']);
            $table->index(['school_id', 'created_at']);
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->date('trial_ends_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });

        Schema::dropIfExists('platform_school_access');
    }
};
