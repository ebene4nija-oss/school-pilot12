<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timetables per arm.
 *
 * SS 2A and SS 2B can have different Physics teachers, and a science arm
 * takes subjects an arts arm does not, so each arm gets its own week. A
 * lesson with no arm belongs to a class taught as one group — every
 * timetable generated before this, which therefore still reads correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->foreignId('arm_id')->nullable()->after('school_class_id')->constrained('arms')->nullOnDelete();
            $table->index(['timetable_version_id', 'school_class_id', 'arm_id'], 'te_version_class_arm');
        });
    }

    public function down(): void
    {
        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->dropIndex('te_version_class_arm');
            $table->dropConstrainedForeignId('arm_id');
        });
    }
};
