<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Virtual classes and learning materials as one LMS (TASK 14).
 *
 * Builds on what exists rather than beside it: `homework` stays the
 * assignment table and `live_classes` the virtual-class table, each gaining
 * the fields the spec asks for. Quizzes stay in the CBT module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            // Routine classroom material should not queue for a head by default.
            $table->boolean('require_material_approval')->default(false);
            $table->boolean('parents_see_materials')->default(true);
            // Sections where a parent may submit on a young child's behalf.
            $table->json('parent_assist_sections')->nullable();
            $table->unsignedSmallInteger('present_percent')->default(75);
            $table->unsignedSmallInteger('late_grace_minutes')->default(10);
            // Off until the school sets a policy (§16).
            $table->boolean('recording_allowed')->default(false);
            $table->text('recording_notice')->nullable();
            $table->unsignedSmallInteger('max_upload_mb')->default(50);
            $table->timestamps();
        });

        Schema::create('learning_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 20); // pdf | document | presentation | image | video | audio | link
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('url', 2000)->nullable();
            $table->string('section', 20)->nullable();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('status', 20)->default('published'); // draft | pending_approval | published | rejected | archived
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('downloadable')->default(true);
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'class_id', 'subject_id']);
        });

        Schema::create('learning_material_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('learning_materials')->cascadeOnDelete();
            $table->string('target_type', 20); // class | arm | section | student | live_class
            $table->foreignId('class_id')->nullable()->constrained('classes')->cascadeOnDelete();
            $table->foreignId('arm_id')->nullable()->constrained('arms')->cascadeOnDelete();
            $table->string('section', 20)->nullable();
            $table->foreignId('student_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('live_class_id')->nullable()->constrained('live_classes')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['material_id', 'target_type']);
        });

        // One row per person per material: enough for "who has opened it".
        Schema::create('learning_material_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('learning_materials')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('opens')->default(0);
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();

            $table->unique(['material_id', 'user_id']);
        });

        Schema::create('lesson_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users');
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('week')->nullable();
            $table->string('topic');
            $table->string('subtopic')->nullable();
            $table->text('objectives')->nullable();
            $table->text('resources')->nullable();
            $table->text('activities')->nullable();
            $table->text('assessment')->nullable();
            $table->text('outcomes')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('status', 20)->default('draft'); // draft | submitted | approved | returned
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'class_id', 'subject_id']);
        });

        Schema::create('lesson_plan_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('learning_materials')->cascadeOnDelete();
            $table->unique(['lesson_plan_id', 'material_id']);
        });

        Schema::table('homework', function (Blueprint $table) {
            $table->foreignId('arm_id')->nullable()->after('class_id')->constrained('arms')->nullOnDelete();
            $table->foreignId('lesson_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('max_score', 6, 2)->nullable();
            $table->string('submission_type', 20)->default('text'); // text | file | text_or_file
            $table->boolean('allow_late')->default(true);
            $table->boolean('allow_parent_assist')->default(false);
            $table->string('status', 20)->default('published'); // draft | published
            $table->timestamp('published_at')->nullable();
        });

        // Specific students; none means the whole class (or arm).
        Schema::create('homework_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('homework_id')->constrained('homework')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unique(['homework_id', 'student_id']);
        });

        Schema::create('homework_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('homework_id')->constrained('homework')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('learning_materials')->cascadeOnDelete();
            $table->unique(['homework_id', 'material_id']);
        });

        Schema::table('homework_submissions', function (Blueprint $table) {
            $table->unsignedSmallInteger('resubmissions')->default(0);
            $table->timestamp('returned_at')->nullable();
            $table->string('return_note', 2000)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('parent_assisted')->default(false);
        });

        Schema::create('homework_submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained('homework_submissions')->cascadeOnDelete();
            $table->string('path');
            $table->string('name');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();
        });

        Schema::table('live_classes', function (Blueprint $table) {
            $table->foreignId('lesson_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->text('objectives')->nullable();
            $table->string('series_id', 36)->nullable()->index();
            $table->boolean('recording_enabled')->default(false);
            $table->string('cancel_reason', 500)->nullable();
        });

        // Time connected, from SchoolPilot's class page (join, heartbeat, leave).
        Schema::create('live_class_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('live_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_joined_at')->nullable();
            $table->timestamp('last_left_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('connected')->default(false);
            $table->unsignedInteger('seconds_connected')->default(0);
            $table->unsignedSmallInteger('joins')->default(0);
            $table->string('corrected_status', 20)->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correction_reason', 500)->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();

            $table->unique(['live_class_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_class_participants');
        Schema::table('live_classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lesson_plan_id');
            $table->dropColumn(['objectives', 'series_id', 'recording_enabled', 'cancel_reason']);
        });
        Schema::dropIfExists('homework_submission_files');
        Schema::table('homework_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['resubmissions', 'returned_at', 'return_note', 'parent_assisted']);
        });
        Schema::dropIfExists('homework_materials');
        Schema::dropIfExists('homework_targets');
        Schema::table('homework', function (Blueprint $table) {
            $table->dropConstrainedForeignId('arm_id');
            $table->dropConstrainedForeignId('lesson_plan_id');
            $table->dropColumn(['max_score', 'submission_type', 'allow_late', 'allow_parent_assist', 'status', 'published_at']);
        });
        Schema::dropIfExists('lesson_plan_materials');
        Schema::dropIfExists('lesson_plans');
        Schema::dropIfExists('learning_material_views');
        Schema::dropIfExists('learning_material_access');
        Schema::dropIfExists('learning_materials');
        Schema::dropIfExists('lms_settings');
    }
};
