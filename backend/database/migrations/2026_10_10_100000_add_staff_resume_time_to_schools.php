<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The time staff are expected at school. A GPS clock-in after it is recorded
 * as late, so the admin's staff attendance can say more than "came / didn't".
 * Stored as Nigerian local time (WAT); the app clock is UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->time('staff_resume_time')->default('07:30:00')->after('geofence_radius_meters');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('staff_resume_time');
        });
    }
};
