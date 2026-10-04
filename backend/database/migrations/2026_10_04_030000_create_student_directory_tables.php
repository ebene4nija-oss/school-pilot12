<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The View Students page (TASK: View Students page instructions).
 *
 *  - Per-school directory choices: mandatory fields, name order, who sees
 *    guardian phones.
 *  - Saved views per user.
 *  - A history of changes to the fields that matter (class, arm, status, date
 *    of birth, name), each with who, when and why. Recorded by an observer, so
 *    every path that changes them is covered, not just this page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_directory_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('mandatory_fields')->nullable();
            // surname_first | given_first
            $table->string('name_order', 15)->default('given_first');
            $table->boolean('subject_teachers_see_guardian_phone')->default(false);
            $table->boolean('show_gender_counts')->default(true);
            $table->timestamps();
        });

        Schema::create('student_saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('filters');
            $table->json('columns')->nullable();
            $table->timestamps();
        });

        Schema::create('student_record_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('field', 40);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['school_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_record_changes');
        Schema::dropIfExists('student_saved_views');
        Schema::dropIfExists('student_directory_settings');
    }
};
