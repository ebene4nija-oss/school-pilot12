<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who is the form teacher of JSS 2 Gold?
     *
     * Nothing could answer that before this table. `teacher_subjects` records
     * who teaches Mathematics to a class, which is a different question, and
     * `arms` carried no staff at all — yet the report-card contract already
     * publishes a `comments.class_teacher` slot and the messaging code is
     * written around "a form teacher keeps one thread per family". The
     * placeholder existed with no system of record behind it.
     *
     * Scoped to a session rather than hung off `arms` as a column: last year's
     * form teacher has to stay attributable on last year's report cards, and a
     * column would be overwritten every September.
     */
    public function up(): void
    {
        Schema::create('class_teacher_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();

            /*
             * Null means the class is not streamed — a small school running one
             * undivided JSS 1. The unique index below therefore cannot be the
             * whole story: both SQLite and Postgres treat NULLs as distinct, so
             * a class with no arms could take two rows as far as the database
             * is concerned. The controller holds that invariant instead.
             */
            $table->foreignId('arm_id')->nullable()->constrained('arms')->cascadeOnDelete();

            $table->foreignId('form_teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assistant_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['session_id', 'class_id', 'arm_id'], 'cta_placement_unique');
            $table->index(['school_id', 'session_id']);
            $table->index(['school_id', 'form_teacher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_teacher_assignments');
    }
};
