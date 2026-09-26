<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a school rates on its report cards, and the ratings and comments
 * themselves.
 *
 * Report-card templates have had slots for traits and for the form teacher's
 * and principal's comments, but nothing let anyone enter them, so they always
 * printed empty. A school chooses its lists once (onboarding); teachers fill
 * them per pupil per term.
 *
 * Ratings are school judgements about conduct, not personal data beyond the
 * record the school already keeps (doc §12): no new identifying fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_report_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            // ["Punctuality", "Neatness", …]
            $table->json('affective_traits')->nullable();
            // ["Handwriting", "Sports", …]
            $table->json('psychomotor_skills')->nullable();
            // Best first: [{value: "5", label: "Excellent"}, …, {value: "1", label: "Poor"}]
            $table->json('rating_scale')->nullable();
            $table->boolean('form_teacher_comment')->default(true);
            $table->boolean('principal_comment')->default(true);
            // "Principal", "Head Teacher", "Proprietress" — as printed.
            $table->string('principal_title', 40)->default('Principal');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('student_term_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            // { "Punctuality": "5", "Handwriting": "4" }
            $table->json('ratings')->nullable();
            $table->text('form_teacher_comment')->nullable();
            $table->text('principal_comment')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'term_id']);
            $table->index(['school_id', 'term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_term_reports');
        Schema::dropIfExists('school_report_settings');
    }
};
