<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Take trial end dates back out (added in 2026_09_26_070000).
 *
 * SchoolPilot's core is free for good and schools pay per use at result time
 * (doc §13), so there is nothing for a trial to switch off when it ends. The
 * date was a countdown to nothing that flagged healthy schools as lapsed.
 * `status = 'trial'` stays, as an onboarding label with no clock: the value is
 * part of API responses installed mobile apps already read.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('schools', 'trial_ends_at')) {
            Schema::table('schools', function (Blueprint $table) {
                $table->dropColumn('trial_ends_at');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('schools', 'trial_ends_at')) {
            Schema::table('schools', function (Blueprint $table) {
                $table->date('trial_ends_at')->nullable()->after('status');
            });
        }
    }
};
