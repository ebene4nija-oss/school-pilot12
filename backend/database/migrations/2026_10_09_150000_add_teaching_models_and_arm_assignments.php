<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each section is taught, and subject teachers per arm.
 *
 * In nursery and primary the class teacher usually teaches everything, with
 * the odd specialist (French, Computer, PHE). In secondary each subject has
 * its own teacher, and two arms of one class can have different teachers for
 * the same subject. Before this, only subject-by-subject assignments to a
 * whole class counted, so a Primary 3 class teacher could not hand in scores
 * and "Physics in SS 2A is Mr Bello's, in SS 2B Mrs Okon's" had nowhere to go.
 *
 *  - school_section_settings.teaching_models: per section, class_teacher or
 *    subject_teacher; null = nursery and primary class_teacher, secondary
 *    subject_teacher.
 *  - teacher_subjects.arm_id: the arm the assignment covers; null = every
 *    arm of the class, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_section_settings', function (Blueprint $table) {
            $table->json('teaching_models')->nullable()->after('approval_chains');
        });

        Schema::table('teacher_subjects', function (Blueprint $table) {
            $table->foreignId('arm_id')->nullable()->after('class_id')->constrained('arms')->cascadeOnDelete();
            $table->dropUnique('ts_unique');
            $table->unique(['school_id', 'teacher_id', 'subject_id', 'class_id', 'arm_id'], 'ts_unique');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_subjects', function (Blueprint $table) {
            $table->dropUnique('ts_unique');
            $table->dropConstrainedForeignId('arm_id');
            $table->unique(['school_id', 'teacher_id', 'subject_id', 'class_id'], 'ts_unique');
        });

        Schema::table('school_section_settings', function (Blueprint $table) {
            $table->dropColumn('teaching_models');
        });
    }
};
