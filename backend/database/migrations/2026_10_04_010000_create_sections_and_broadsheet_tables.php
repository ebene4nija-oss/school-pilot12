<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sections foundation and the broadsheet (TASK/SCHOOLPILOT_SECTIONS_DECISIONS_BASELINE,
 * TASK/SchoolPilot Comprehensive Broadsheet Management).
 *
 * Four ideas, each its own table so that none of them can quietly overwrite
 * another:
 *
 *  - Who heads what. Stored per level group (D3), so a school moving from one
 *    principal to separate JSS and SS heads adds rows; nothing migrates.
 *  - Which steps a class's results pass through (D2), per section, by role.
 *  - Which CA scheme applies from which term (D1). A scheme never reaches a
 *    term that already has scores.
 *  - The broadsheet itself: a workflow record per class and term, an
 *    append-only event log, frozen versions of what was approved, and
 *    corrections that wait for approval before they touch a score.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_section_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            // D3 option A (one principal) or B (separate JSS and SS heads).
            $table->string('secondary_heads_mode')->default('single_principal');
            // Per section: [{role, required, label}]. Null = baseline default.
            $table->json('approval_chains')->nullable();
            // Users the owner has delegated scheme approval to (D1).
            $table->json('scheme_approval_delegates')->nullable();
            // Whether a class's results must clear the chain before release.
            $table->boolean('result_workflow_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('section_head_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // principal | level_head | hod
            $table->string('position', 20);
            // nursery | primary | junior_secondary | senior_secondary | secondary; null for the principal
            $table->string('level_group', 30)->nullable();
            // For HODs: the subject department (subjects.department)
            $table->string('department', 100)->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'position', 'level_group']);
            $table->index(['school_id', 'user_id']);
        });

        Schema::table('ca_schemes', function (Blueprint $table) {
            // Legacy schemes have no status and keep working as before.
            $table->string('status', 20)->nullable()->after('is_default');
            $table->unsignedInteger('version')->default(1)->after('status');
            // null = every section
            $table->string('level_group', 30)->nullable()->after('version');
            $table->foreignId('effective_term_id')->nullable()->after('level_group')->constrained('terms')->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()->after('effective_term_id')->constrained('ca_schemes')->nullOnDelete();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('proposed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable();
        });

        Schema::table('score_entries', function (Blueprint $table) {
            // scored (default) | absent | exempt. A missing row is "not entered".
            $table->string('result_state', 12)->nullable()->after('grade');
        });

        Schema::create('broadsheet_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // null = school default
            $table->string('level_group', 30)->nullable();
            // total | breakdown | grade | score_grade | detailed
            $table->string('display_mode', 20)->default('score_grade');
            // average | total | none
            $table->string('ranking_basis', 10)->default('average');
            // competition (1,2,2,4) | dense (1,2,2,3)
            $table->string('ranking_method', 12)->default('competition');
            // all | complete_only
            $table->string('ranking_scope', 15)->default('all');
            // exclude | zero
            $table->string('absence_policy', 10)->default('exclude');
            $table->unsignedTinyInteger('pass_mark')->default(40);
            $table->boolean('show_gender')->default(false);
            $table->boolean('show_attendance')->default(true);
            $table->boolean('show_remarks')->default(false);
            $table->boolean('numeric_averages')->default(true);
            $table->json('subject_order')->nullable();
            $table->string('confidentiality_footer')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'level_group']);
        });

        Schema::create('broadsheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('level_group', 30)->nullable();
            // draft | in_review | returned | approved | published | rejected
            $table->string('status', 15)->default('draft');
            // The chain as it stood when this broadsheet started, so editing the
            // school's chain mid-term does not reroute results already moving.
            $table->json('steps');
            $table->unsignedTinyInteger('current_step')->default(0);
            $table->unsignedInteger('current_version')->default(0);
            $table->boolean('report_cards_stale')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'term_id', 'class_id']);
        });

        Schema::create('broadsheet_subject_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadsheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            // draft | submitted | returned
            $table->string('status', 12)->default('draft');
            // On a return, the students reopened; null = the whole subject.
            $table->json('returned_student_ids')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['broadsheet_id', 'subject_id']);
        });

        // Append-only: nothing updates or deletes these rows.
        Schema::create('broadsheet_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadsheet_id')->constrained()->cascadeOnDelete();
            // submit_subject | compile | approve | return | reject | publish | override | correction_* | export
            $table->string('action', 30);
            $table->unsignedTinyInteger('step')->nullable();
            $table->string('step_role', 20)->nullable();
            $table->string('department', 100)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['broadsheet_id', 'id']);
        });

        Schema::create('broadsheet_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadsheet_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            // Everything the approved figures were computed from, and the figures.
            $table->longText('snapshot');
            $table->string('snapshot_hash', 64);
            $table->json('validation')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->string('superseded_reason')->nullable();
            $table->timestamps();

            $table->unique(['broadsheet_id', 'version']);
        });

        Schema::create('broadsheet_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadsheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('score_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->json('old_values')->nullable();
            $table->json('new_values');
            $table->text('reason');
            // pending | applied | rejected
            $table->string('status', 10)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedInteger('applied_in_version')->nullable();
            $table->timestamps();

            $table->index(['broadsheet_id', 'status']);
        });

        Schema::create('broadsheet_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadsheet_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('format', 10);
            $table->string('kind', 20)->default('class');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // A warning someone with authority accepted, with why.
        Schema::create('broadsheet_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadsheet_id')->constrained()->cascadeOnDelete();
            $table->string('issue_code', 60);
            $table->string('issue_key', 120);
            $table->text('reason');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['broadsheet_id', 'issue_code', 'issue_key']);
        });

        // Nursery developmental ratings: indicators per school, a rating per child.
        Schema::create('developmental_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('developmental_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('indicator_id')->constrained('developmental_indicators')->cascadeOnDelete();
            // exceeding | meeting | developing | needs_support
            $table->string('rating', 20);
            $table->text('observation')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['term_id', 'student_id', 'indicator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('developmental_ratings');
        Schema::dropIfExists('developmental_indicators');
        Schema::dropIfExists('broadsheet_overrides');
        Schema::dropIfExists('broadsheet_exports');
        Schema::dropIfExists('broadsheet_corrections');
        Schema::dropIfExists('broadsheet_versions');
        Schema::dropIfExists('broadsheet_events');
        Schema::dropIfExists('broadsheet_subject_submissions');
        Schema::dropIfExists('broadsheets');
        Schema::dropIfExists('broadsheet_configurations');

        Schema::table('score_entries', function (Blueprint $table) {
            $table->dropColumn('result_state');
        });

        Schema::table('ca_schemes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('effective_term_id');
            $table->dropConstrainedForeignId('supersedes_id');
            $table->dropConstrainedForeignId('proposed_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['status', 'version', 'level_group', 'proposed_at', 'approved_at', 'decision_note']);
        });

        Schema::dropIfExists('section_head_assignments');
        Schema::dropIfExists('school_section_settings');
    }
};
