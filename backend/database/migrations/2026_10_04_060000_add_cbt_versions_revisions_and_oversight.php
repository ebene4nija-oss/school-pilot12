<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CBT: the data rules the module's instructions require from day one (§3)
 * that the engine did not yet have.
 *
 *  - Question versions: an edit to a question already answered keeps the
 *    version that was answered (rule 2).
 *  - Result revisions: a result is a series, each with the rules used
 *    (rule 4). A regrade adds a revision and keeps the old.
 *  - Regrade jobs: deliberate, logged, previewed, reversible (§10.6).
 *  - Exam approval, per school (§5.6), and school CBT settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_bank', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
        });

        Schema::table('cbt_attempt_answers', function (Blueprint $table) {
            // The question version this answer was marked against.
            $table->unsignedInteger('question_version')->nullable();
        });

        Schema::create('cbt_question_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('question_bank')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('payload');
            $table->string('change_summary')->nullable();
            $table->boolean('affects_scoring')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['question_id', 'version']);
        });

        Schema::create('cbt_regrade_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained('cbt_exams')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('question_bank')->nullOnDelete();
            // change_key | accept_answers | credit_all | drop | recalculate
            $table->string('action', 20);
            $table->json('params')->nullable();
            // exam | all_exams
            $table->string('scope', 10)->default('exam');
            $table->text('reason');
            // Repeating the same regrade gives the same result (rule 8).
            $table->string('params_hash', 64);
            // applied | rolled_back
            $table->string('status', 15)->default('applied');
            $table->json('summary')->nullable();
            // Before-values, so a rollback restores exactly what was there.
            $table->longText('before')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('rolled_back_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->index(['exam_id', 'params_hash']);
        });

        Schema::create('cbt_result_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attempt_id')->constrained('cbt_attempts')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->decimal('raw_score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->string('grade', 4)->nullable();
            // The rules used: pass mark, negative marking, grading scale, regrade action.
            $table->json('rules');
            $table->string('reason', 255);
            $table->foreignId('regrade_job_id')->nullable()->constrained('cbt_regrade_jobs')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['attempt_id', 'revision']);
        });

        Schema::table('cbt_exams', function (Blueprint $table) {
            // null (not needed) | pending | approved | rejected
            $table->string('approval_status', 10)->nullable();
            $table->text('approval_note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // class_test | ca | weekly | midterm | terminal | mock | practice | entrance | promotional
            $table->string('exam_type', 20)->nullable();
        });

        Schema::create('cbt_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('exam_approval_required')->default(false);
            // Teachers trusted to publish without approval.
            $table->json('trusted_teacher_ids')->nullable();
            $table->unsignedSmallInteger('min_preparation_days')->default(0);
            $table->unsignedSmallInteger('disconnect_after_seconds')->default(120);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbt_settings');
        Schema::table('cbt_exams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_status', 'approval_note', 'approved_at', 'exam_type']);
        });
        Schema::dropIfExists('cbt_result_revisions');
        Schema::dropIfExists('cbt_regrade_jobs');
        Schema::dropIfExists('cbt_question_versions');
        Schema::table('cbt_attempt_answers', fn (Blueprint $table) => $table->dropColumn('question_version'));
        Schema::table('question_bank', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
