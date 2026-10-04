<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student academic history and school transfer
 * (TASK: Student Academic History and School Transfer build instructions).
 *
 * The rules these tables exist to keep:
 *  - A term's results are locked as issued, in a snapshot that carries the
 *    grading scale and scheme it was issued under. History is built from
 *    snapshots, never recomputed from today's settings.
 *  - A correction is a new snapshot version; the old one stays.
 *  - Every record says where it came from (provenance).
 *  - A transfer closes one enrolment and opens another. It deletes nothing.
 *  - Another school's records arrive read-only and are never merged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            // Names as issued, so the record reads correctly even if the class or term is renamed.
            $table->string('session_name', 50)->nullable();
            $table->string('term_name', 50)->nullable();
            $table->string('class_name', 100)->nullable();
            $table->unsignedInteger('version')->default(1);
            // broadsheet_version | live_at_close | legacy_import | manual_entry
            $table->string('source', 30);
            // system_generated | manually_entered | imported_verified_electronic
            // | imported_verified_upload | uploaded_unverified | discrepancy
            $table->string('provenance', 40)->default('system_generated');
            $table->unsignedInteger('broadsheet_version')->nullable();
            $table->boolean('partial_term')->default(false);
            $table->longText('data');
            $table->string('data_hash', 64);
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->text('correction_reason')->nullable();
            $table->foreignId('correction_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'student_id', 'superseded_at']);
            $table->index(['school_id', 'term_id', 'class_id']);
        });

        Schema::create('term_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('students')->default(0);
            $table->boolean('from_approved_broadsheet')->default(false);
            $table->timestamps();

            $table->unique(['school_id', 'term_id', 'class_id']);
        });

        Schema::create('history_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('filename')->nullable();
            // previewed | committed
            $table->string('status', 15)->default('previewed');
            $table->json('rows');
            $table->json('errors')->nullable();
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('academic_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transfer_id')->nullable();
            // transcript | transfer_certificate | transfer_summary | attendance_summary
            // | conduct_reference | clearance_certificate | subject_history
            $table->string('type', 30);
            $table->string('reference', 40)->unique();
            $table->longText('payload');
            $table->string('payload_hash', 64);
            $table->string('signature', 64);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signatory_name')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->foreignId('replaced_by_id')->nullable()->constrained('academic_documents')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'student_id']);
        });

        Schema::create('transfer_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            // Ordered steps, each a role: registrar | principal | admin_release
            $table->json('approval_steps')->nullable();
            $table->boolean('fees_block_release')->default(false);
            $table->unsignedSmallInteger('package_expiry_days')->default(14);
            $table->unsignedSmallInteger('code_expiry_days')->default(14);
            // guardian_consent (default) | legal_obligation | contract
            $table->string('lawful_basis', 30)->default('guardian_consent');
            $table->boolean('students_may_request')->default(false);
            $table->boolean('end_student_access_on_completion')->default(true);
            $table->json('clearance_items')->nullable();
            $table->unsignedSmallInteger('applicant_retention_days')->default(365);
            $table->timestamps();
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30);
            // outgoing | incoming
            $table->string('direction', 10);
            $table->string('status', 30)->default('draft');
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('student_enrollments')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->date('requested_transfer_date')->nullable();
            // Outgoing: where to. Incoming: where from.
            $table->string('other_school_name')->nullable();
            $table->foreignId('other_school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('linked_transfer_id')->nullable();
            // parent | student | registrar | admin | receiving_school
            $table->string('requested_by_type', 20);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('partial_term')->default(false);
            // Incoming: the applicant as the previous school knew them.
            $table->json('applicant')->nullable();
            // Which record categories the package carries.
            $table->json('package_options')->nullable();
            $table->unsignedTinyInteger('current_step')->default(0);
            $table->json('steps')->nullable();
            $table->string('transfer_code_hash', 64)->nullable();
            $table->timestamp('transfer_code_expires_at')->nullable();
            $table->foreignId('placement_class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('new_student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'reference']);
            $table->index(['school_id', 'direction', 'status']);
        });

        // Append-only status history.
        Schema::create('transfer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('transfer_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            // transfer | health | special_needs | discipline
            $table->string('category', 20);
            $table->boolean('given');
            $table->foreignId('given_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        Schema::create('transfer_clearance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            $table->string('item', 40);
            // outstanding | cleared | overridden
            $table->string('status', 15)->default('outstanding');
            $table->text('note')->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('transfer_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            $table->longText('payload');
            $table->string('payload_hash', 64);
            $table->string('signature', 64);
            // Secure-link delivery: a random token, stored hashed.
            $table->string('link_token_hash', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('superseded_at')->nullable();
            $table->string('superseded_reason')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // The receiving school's read-only copy of another school's record.
        Schema::create('previous_school_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transfer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_school_name');
            $table->foreignId('source_school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('source_package_hash', 64)->nullable();
            // imported_verified_electronic | imported_verified_upload | uploaded_unverified | discrepancy
            $table->string('provenance', 40);
            // pending | verified | discrepancy | additional_info_required
            $table->string('verification_status', 30)->default('pending');
            $table->longText('data');
            $table->string('data_hash', 64);
            // A local correction is a new row pointing at the one it amends.
            $table->foreignId('amends_id')->nullable()->constrained('previous_school_records')->nullOnDelete();
            $table->text('amendment_reason')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('school_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // class | subject
            $table->string('kind', 10);
            $table->string('external_name', 120);
            $table->unsignedBigInteger('local_id')->nullable();
            // exact | equivalent | different_name | not_offered | needs_assessment
            $table->string('mapping_type', 20);
            $table->timestamps();

            $table->unique(['school_id', 'kind', 'external_name']);
        });

        // Two schools' records of one child, joined only by a person.
        Schema::create('student_identity_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('to_student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('transfer_id')->nullable()->constrained()->nullOnDelete();
            $table->json('matched_on');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['from_student_id', 'to_student_id']);
        });

        // A student or parent saying the record is wrong (§4.6).
        Schema::create('record_correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            // open | resolved | declined
            $table->string('status', 10)->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'record_correction_requests', 'student_identity_links', 'school_mappings', 'previous_school_records',
            'transfer_packages', 'transfer_clearance_items', 'transfer_consents', 'transfer_events', 'transfers',
            'transfer_settings', 'academic_documents', 'history_import_batches', 'term_closures', 'term_snapshots',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
