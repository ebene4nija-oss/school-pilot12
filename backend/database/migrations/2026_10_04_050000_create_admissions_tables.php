<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions (sections baseline D4; TASK 22 Admissions and Enrollment).
 *
 * Applicants are not students. They live here until enrolment creates a
 * student through the same path every other student takes, and an
 * application that never reaches acceptance has its personal details
 * removed once the school's retention period ends.
 *
 * Data collected is the minimum a decision needs (NDPA, doc §12): name, date
 * of birth, gender, previous school and class, and how to reach a guardian.
 * Health information is collected at enrolment, not at application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            // Off until the school decides (baseline "still to lock" #2).
            $table->boolean('public_form_enabled')->default(false);
            $table->json('open_sections')->nullable();
            $table->json('required_documents')->nullable();
            $table->unsignedSmallInteger('retention_days')->default(365);
            $table->unsignedSmallInteger('offer_validity_days')->default(14);
            $table->text('offer_letter_note')->nullable();
            $table->timestamps();
        });

        Schema::create('admission_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30);
            $table->string('section', 20);
            $table->foreignId('session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('intended_class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->string('applicant_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('previous_school')->nullable();
            $table->string('previous_class', 100)->nullable();
            // [{name, phone, email, relationship}] — encrypted: contact details of a family.
            $table->text('guardians')->nullable();
            // staff | online
            $table->string('source', 10)->default('staff');
            $table->string('status', 25)->default('submitted');
            $table->text('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('offered_class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->timestamp('offer_expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'reference']);
            $table->index(['school_id', 'status']);
        });

        Schema::create('admission_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('admission_applications')->cascadeOnDelete();
            $table->string('type', 40);
            // pending | received | verified | rejected
            $table->string('status', 10)->default('pending');
            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['application_id', 'type']);
        });

        // Entrance tests and interviews (baseline phase 3), optional per section.
        Schema::create('admission_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('admission_applications')->cascadeOnDelete();
            // entrance_test | interview
            $table->string('kind', 15);
            $table->timestamp('scheduled_at')->nullable();
            $table->string('location')->nullable();
            $table->foreignId('cbt_exam_id')->nullable()->constrained('cbt_exams')->nullOnDelete();
            $table->decimal('score', 6, 2)->nullable();
            $table->decimal('max_score', 6, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Append-only decision history.
        Schema::create('admission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('admission_applications')->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 25)->nullable();
            $table->string('to_status', 25)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_events');
        Schema::dropIfExists('admission_assessments');
        Schema::dropIfExists('admission_documents');
        Schema::dropIfExists('admission_applications');
        Schema::dropIfExists('admission_settings');
    }
};
