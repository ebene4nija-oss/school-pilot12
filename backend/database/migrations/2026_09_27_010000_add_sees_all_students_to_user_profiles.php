<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A teacher sees the students they teach, not the whole school.
 *
 * Every teacher could open every child's record — the policy said "school
 * staff act on the whole roster". Children's records are reached on a need to
 * know (NDPA, doc §12), so a teacher now sees the classes they are assigned to.
 * Some staff genuinely need the whole school — an exams officer, a head of
 * section — and a school admin grants them this flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->boolean('sees_all_students')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('sees_all_students');
        });
    }
};
