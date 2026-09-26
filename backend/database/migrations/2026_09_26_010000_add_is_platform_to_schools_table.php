<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mark the platform's own row in `schools` as not being a school.
 *
 * Every login resolves a tenant from the host, including the platform
 * operator's — so SchoolPilot has a row in `schools` purely to give the
 * operator a subdomain to authenticate through. It has no pupils, pays no
 * fees and belongs to no proprietor.
 *
 * Without a way to tell it apart it was counted and listed as a customer: the
 * operator console reported "Schools 1" for a platform with no schools on it,
 * and offered a Suspend button that would have suspended the tenant the
 * operator signs in through — locking them out of the console that has the
 * button.
 *
 * A column rather than a hardcoded subdomain check, because "admin" is a
 * deployment choice: a second environment, or a rename, should not silently
 * turn platform infrastructure back into a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('is_platform')->default(false)->after('status');
            // The console filters on this on every load.
            $table->index(['is_platform', 'status']);
        });

        /*
         * Mark the existing internal school.
         *
         * Matched on the configured platform subdomain and only where the
         * school has no students, so a real school that happens to use this
         * subdomain is never quietly removed from billing and reporting.
         */
        $subdomain = config('schoolpilot.platform_subdomain', 'admin');

        DB::table('schools')
            ->where('subdomain', $subdomain)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('students')
                    ->whereColumn('students.school_id', 'schools.id');
            })
            ->update(['is_platform' => true]);
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropIndex(['is_platform', 'status']);
            $table->dropColumn('is_platform');
        });
    }
};
