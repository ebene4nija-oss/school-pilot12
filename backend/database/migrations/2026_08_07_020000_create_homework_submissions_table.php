<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Homework submissions.
 *
 * Homework was one-directional: a teacher could set it, and students and
 * parents could read it, but there was no way to hand anything back and no way
 * to mark it. A teacher could assign work and never receive it.
 *
 * `course_resources` (attachments) already exists unused; file upload is
 * deliberately not built here — on the connectivity these schools have, typed
 * answers and a photo reference are what actually get submitted, and an upload
 * pipeline is its own piece of work.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('homework_submissions')) {
            return;
        }

        Schema::create('homework_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('homework_id')->constrained('homework')->onDelete('cascade');
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');

            $table->text('body')->nullable();
            // Path to a photo of written work, once uploads land. Kept nullable
            // so the column does not have to be added later under load.
            $table->string('attachment_path')->nullable();

            $table->timestamp('submitted_at')->nullable();
            // Recorded at submit time by comparing against the homework's own
            // due date, so a later change to that date cannot retrospectively
            // make a punctual child late.
            $table->boolean('is_late')->default(false);

            $table->decimal('marks_awarded', 6, 2)->nullable();
            $table->decimal('marks_available', 6, 2)->nullable();
            $table->text('teacher_feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();

            $table->string('status')->default('submitted'); // submitted | graded | returned
            $table->timestamps();

            // One submission per student per assignment; re-submitting updates
            // it rather than stacking duplicates a teacher has to reconcile.
            $table->unique(['homework_id', 'student_id']);
            $table->index(['school_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_submissions');
    }
};
