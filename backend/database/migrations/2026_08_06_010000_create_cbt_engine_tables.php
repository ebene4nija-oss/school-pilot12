<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CBT engine core.
 *
 * The pre-existing CBT surface was a question_bank table plus an offline-sync
 * endpoint pointed at a `student_attempts` table that no migration ever
 * created. This migration lays the real engine down: exam definitions, the
 * questions bound to them, per-student attempts, per-question answers, an
 * integrity event log, and a school-scoped media library so questions can
 * carry images.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // Media library — shared by question stems, options and explanations.
        // Content-addressed (sha256) so re-uploading the same diagram across
        // twenty questions costs one file. Alt text is NOT nullable: a blind
        // or low-vision candidate must still be able to sit the paper.
        // ------------------------------------------------------------------
        if (!Schema::hasTable('cbt_media_assets')) {
            Schema::create('cbt_media_assets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('disk')->default('public');
                $table->string('path');
                $table->string('thumbnail_path')->nullable();
                $table->string('mime_type');
                $table->string('extension', 10);
                $table->unsignedBigInteger('byte_size');
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                // sha256 of the *stored* (re-encoded, EXIF-stripped) bytes.
                $table->string('checksum', 64);
                $table->string('alt_text');
                $table->string('caption')->nullable();
                $table->timestamps();

                $table->unique(['school_id', 'checksum']);
                $table->index(['school_id']);
            });
        }

        // ------------------------------------------------------------------
        // question_bank extensions — images, LaTeX, structured answers,
        // and the counters that drive item analysis.
        // ------------------------------------------------------------------
        if (Schema::hasTable('question_bank')) {
            Schema::table('question_bank', function (Blueprint $table) {
                if (!Schema::hasColumn('question_bank', 'content_format')) {
                    // 'plain' or 'latex'. 'latex' means the stem/options may
                    // contain $...$, $$...$$ or \(...\) segments that a client
                    // renders with KaTeX. Validated server-side on write.
                    $table->string('content_format')->default('plain');
                }
                if (!Schema::hasColumn('question_bank', 'media')) {
                    // [{ "asset_id": 12, "role": "stem", "position": 0 }, ...]
                    // role: stem | option:<key> | explanation | diagram
                    $table->json('media')->nullable();
                }
                if (!Schema::hasColumn('question_bank', 'answer_schema')) {
                    // Type-specific grading config: numeric tolerance, accepted
                    // fill-blank spellings, matching pairs, diagram label zones.
                    $table->json('answer_schema')->nullable();
                }
                if (!Schema::hasColumn('question_bank', 'explanation')) {
                    $table->text('explanation')->nullable();
                }
                if (!Schema::hasColumn('question_bank', 'marks')) {
                    $table->decimal('marks', 6, 2)->default(1.00);
                }
                if (!Schema::hasColumn('question_bank', 'exam_body')) {
                    $table->string('exam_body')->nullable(); // WAEC | NECO | JAMB | NABTEB | internal
                }
                if (!Schema::hasColumn('question_bank', 'status')) {
                    $table->string('status')->default('approved'); // draft | approved | retired
                }
                if (!Schema::hasColumn('question_bank', 'times_answered')) {
                    $table->unsignedInteger('times_answered')->default(0);
                }
                if (!Schema::hasColumn('question_bank', 'times_correct')) {
                    $table->unsignedInteger('times_correct')->default(0);
                }
                if (!Schema::hasColumn('question_bank', 'discrimination_index')) {
                    $table->decimal('discrimination_index', 5, 4)->nullable();
                }
                if (!Schema::hasColumn('question_bank', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable();
                }
            });
        }

        // ------------------------------------------------------------------
        // Exam definitions.
        // ------------------------------------------------------------------
        if (!Schema::hasTable('cbt_exams')) {
            Schema::create('cbt_exams', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('subject_id')->constrained()->onDelete('cascade');
                $table->foreignId('class_id')->nullable()->constrained('classes')->onDelete('cascade');
                $table->foreignId('term_id')->nullable()->constrained('terms')->onDelete('set null');
                $table->string('title');
                $table->text('instructions')->nullable();
                $table->string('content_format')->default('plain');
                $table->string('exam_body')->nullable();

                $table->unsignedInteger('duration_minutes')->default(45);
                $table->timestamp('opens_at')->nullable();
                $table->timestamp('closes_at')->nullable();
                $table->string('status')->default('draft'); // draft | published | closed

                $table->boolean('shuffle_questions')->default(true);
                $table->boolean('shuffle_options')->default(true);
                // null = serve every attached question; otherwise draw N at random.
                $table->unsignedInteger('questions_per_attempt')->nullable();
                $table->unsignedTinyInteger('max_attempts')->default(1);
                $table->boolean('negative_marking')->default(false);
                $table->decimal('pass_mark', 5, 2)->default(40.00);
                $table->boolean('show_results_immediately')->default(false);
                $table->boolean('allow_offline')->default(false);
                // { "lock_browser": true, "max_focus_losses": 3, "log_paste": true }
                $table->json('integrity_settings')->nullable();
                $table->decimal('total_marks', 8, 2)->default(0);

                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['school_id', 'status']);
                $table->index(['school_id', 'class_id', 'subject_id']);
            });
        }

        // ------------------------------------------------------------------
        // Questions bound to an exam. Marks can be overridden per exam so the
        // same bank item is worth 1 mark in a class test and 4 in a mock.
        // ------------------------------------------------------------------
        if (!Schema::hasTable('cbt_exam_questions')) {
            Schema::create('cbt_exam_questions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('exam_id')->constrained('cbt_exams')->onDelete('cascade');
                $table->foreignId('question_id')->constrained('question_bank')->onDelete('cascade');
                $table->unsignedInteger('order_index')->default(0);
                $table->decimal('marks', 6, 2)->nullable();
                $table->decimal('negative_marks', 6, 2)->nullable();
                $table->string('section')->nullable(); // "Section A — Objectives"
                $table->timestamps();

                $table->unique(['exam_id', 'question_id']);
                $table->index(['exam_id', 'order_index']);
            });
        }

        // ------------------------------------------------------------------
        // Attempts. `seed` drives the deterministic shuffle: a candidate who
        // loses power mid-paper resumes to the same question and option order
        // they had before, without us storing a full snapshot per attempt.
        // `server_deadline_at` is the only clock that counts — a tampered
        // client clock cannot buy extra time.
        // ------------------------------------------------------------------
        if (!Schema::hasTable('cbt_attempts')) {
            Schema::create('cbt_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('exam_id')->constrained('cbt_exams')->onDelete('cascade');
                $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
                $table->unsignedTinyInteger('attempt_number')->default(1);

                $table->unsignedBigInteger('seed');
                $table->json('question_order')->nullable();
                $table->string('status')->default('in_progress'); // in_progress | submitted | graded | expired | voided

                $table->timestamp('started_at')->nullable();
                $table->timestamp('server_deadline_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('graded_at')->nullable();
                $table->unsignedInteger('extra_time_minutes')->default(0);

                $table->decimal('raw_score', 8, 2)->nullable();
                $table->decimal('max_score', 8, 2)->nullable();
                $table->decimal('percentage', 5, 2)->nullable();
                $table->string('grade', 4)->nullable();
                $table->unsignedInteger('time_spent_seconds')->nullable();
                $table->boolean('requires_manual_grading')->default(false);

                $table->json('integrity_flags')->nullable();
                $table->string('device_fingerprint')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamps();

                $table->unique(['exam_id', 'student_id', 'attempt_number']);
                $table->index(['school_id', 'status']);
            });
        }

        // ------------------------------------------------------------------
        // One row per answered question. `response` is structured JSON rather
        // than a bare string so multi-select, matching, numeric, diagram
        // labelling and photo-of-working answers all fit the same table.
        // ------------------------------------------------------------------
        if (!Schema::hasTable('cbt_attempt_answers')) {
            Schema::create('cbt_attempt_answers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('attempt_id')->constrained('cbt_attempts')->onDelete('cascade');
                $table->foreignId('question_id')->constrained('question_bank')->onDelete('cascade');
                $table->json('response')->nullable();
                // Client-supplied ordering metadata, used only to resolve
                // out-of-order offline batches — never to decide grading.
                $table->timestamp('client_timestamp')->nullable();
                $table->unsignedBigInteger('client_sequence')->default(0);

                $table->boolean('is_correct')->nullable();
                $table->decimal('awarded_marks', 6, 2)->nullable();
                $table->string('graded_by')->nullable(); // 'auto' or a user id
                $table->text('feedback')->nullable();
                $table->boolean('flagged_for_review')->default(false);
                $table->unsignedInteger('time_spent_seconds')->nullable();
                $table->timestamps();

                $table->unique(['attempt_id', 'question_id']);
            });
        }

        // ------------------------------------------------------------------
        // Integrity log. Hardware-free invigilation: we record what the
        // browser/app tells us (focus loss, paste, fullscreen exit, offline
        // sync, auto-submit) and let a human decide what it means. No
        // proctoring cameras, no biometrics.
        // ------------------------------------------------------------------
        if (!Schema::hasTable('cbt_attempt_events')) {
            Schema::create('cbt_attempt_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('attempt_id')->constrained('cbt_attempts')->onDelete('cascade');
                $table->string('event_type');
                $table->timestamp('occurred_at');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['attempt_id', 'event_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cbt_attempt_events');
        Schema::dropIfExists('cbt_attempt_answers');
        Schema::dropIfExists('cbt_attempts');
        Schema::dropIfExists('cbt_exam_questions');
        Schema::dropIfExists('cbt_exams');
        Schema::dropIfExists('cbt_media_assets');

        if (Schema::hasTable('question_bank')) {
            Schema::table('question_bank', function (Blueprint $table) {
                foreach ([
                    'content_format', 'media', 'answer_schema', 'explanation', 'marks',
                    'exam_body', 'status', 'times_answered', 'times_correct',
                    'discrimination_index', 'created_by',
                ] as $column) {
                    if (Schema::hasColumn('question_bank', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
