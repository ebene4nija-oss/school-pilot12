<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student progress and analysis (TASK: Student Progress and Analysis
 * instructions and PRD).
 *
 * The page is read-only on academic data: these tables hold only what it
 * creates — the school's thresholds, teacher remarks, intervention plans and
 * their reviews, the insights the rules produced (with the inputs that
 * produced them) and a log of report downloads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progress_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            // The metrics dictionary's school-configured numbers (instructions §4).
            $table->unsignedTinyInteger('trend_points')->default(5);
            $table->unsignedTinyInteger('steady_range')->default(5);
            $table->unsignedTinyInteger('sharp_decline_points')->default(5);
            $table->unsignedTinyInteger('low_attendance_rate')->default(75);
            $table->unsignedTinyInteger('low_completion_rate')->default(80);
            $table->unsignedTinyInteger('multiple_failed_subjects')->default(3);
            $table->unsignedTinyInteger('topic_strong')->default(80);
            $table->unsignedTinyInteger('topic_developing')->default(60);
            $table->unsignedTinyInteger('topic_min_questions')->default(5);
            $table->json('core_subject_ids')->nullable();
            $table->unsignedTinyInteger('max_flags_shown')->default(5);
            // Who sees early-warning flags beyond admins.
            $table->boolean('teachers_see_flags')->default(true);
            $table->boolean('students_see_position')->default(false);
            $table->boolean('students_see_interventions')->default(false);
            $table->timestamps();
        });

        Schema::create('student_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            // academic | subject_progress | difficulty | participation | homework | improvement | support
            $table->string('category', 30)->default('academic');
            $table->text('body');
            // staff_only | student_visible
            $table->string('visibility', 20)->default('staff_only');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'student_id']);
        });

        Schema::create('interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic', 120)->nullable();
            $table->text('issue');
            $table->string('baseline', 120);
            $table->string('target', 120);
            $table->text('action');
            $table->foreignId('responsible_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date');
            $table->date('review_date');
            // planned | ongoing | completed | cancelled
            $table->string('status', 12)->default('planned');
            $table->text('outcome')->nullable();
            // What a student (where allowed) sees: never raw teacher notes.
            $table->text('plain_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'student_id']);
        });

        Schema::create('intervention_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intervention_id')->constrained()->cascadeOnDelete();
            $table->text('progress');
            $table->string('result', 120)->nullable();
            $table->date('next_review_date')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('student_progress_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule', 50);
            // strength | weakness | trend | risk
            $table->string('category', 12);
            // info | low | medium | high
            $table->string('severity', 8)->default('info');
            $table->text('text');
            $table->json('inputs');
            $table->json('evidence')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['school_id', 'student_id', 'term_id']);
        });

        Schema::create('progress_report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('detailed');
            $table->string('data_hash', 64);
            $table->boolean('outdated')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_report_exports');
        Schema::dropIfExists('student_progress_insights');
        Schema::dropIfExists('intervention_reviews');
        Schema::dropIfExists('interventions');
        Schema::dropIfExists('student_remarks');
        Schema::dropIfExists('progress_settings');
    }
};
