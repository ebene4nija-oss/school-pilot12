<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam timetables: which class sits which paper, when, where, and who watches.
 *
 * Separate from the teaching timetable (timetable_versions), which is a weekly
 * repeating grid of named slots. An exam timetable is a fortnight of dated,
 * one-off sittings, several papers a day, with an invigilator per sitting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_timetables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            // "First Term Examination", "Mid-term CA test", "SS3 Mock".
            $table->string('name');
            // Drafts are for the exams officer; only a published timetable is
            // shown to teachers, students and parents.
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'term_id']);
        });

        Schema::create('exam_sittings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_timetable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            // Null: every arm of the class sits together.
            $table->foreignId('arm_id')->nullable()->constrained('arms')->nullOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            // "Paper 1 (Objective)", "Practical". Optional.
            $table->string('paper')->nullable();
            $table->date('exam_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('venue')->nullable();
            $table->foreignId('invigilator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['exam_timetable_id', 'exam_date']);
            $table->index(['school_id', 'invigilator_id', 'exam_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_sittings');
        Schema::dropIfExists('exam_timetables');
    }
};
