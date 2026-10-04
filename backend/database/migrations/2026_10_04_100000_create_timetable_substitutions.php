<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cover for absent teachers (TASK 07). One row per lesson per date: the
 * published timetable is never edited for a day's absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A teacher marked absent for a day without a leave request (sudden illness).
        Schema::create('teacher_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->string('reason', 30)->default('other'); // sick | training | official_duty | other
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['teacher_id', 'date']);
        });

        Schema::create('timetable_substitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('timetable_entries')->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('absent_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('substitute_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('covered'); // covered | cancelled
            $table->string('note', 255)->nullable();
            $table->foreignId('arranged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'date']);
            $table->index(['substitute_teacher_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_substitutions');
        Schema::dropIfExists('teacher_absences');
    }
};
