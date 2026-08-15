<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Question groups and the offline exam bundle (docs/offline-cbt-client.md §6.2,
 * §9.1).
 *
 * Two things land here that look unrelated and are not. Groups are a
 * platform-wide question-model change the web runner wants on its own. Bundles
 * exist because the offline client cannot ship without a way to put a paper on
 * a lab relay before exam day — and the only reason grouping is being done now
 * is that a flat shuffle scatters a comprehension passage across the paper, so
 * a bundle built from grouped questions would be unanswerable.
 *
 * Everything is additive. A question with a null `group_id` behaves exactly as
 * it did before this migration, which matters because every question that
 * exists today has one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // Question groups — one shared stimulus, many sub-questions. A
        // comprehension passage, a data table, a labelled diagram.
        //
        // The group carries no marks of its own: `total_marks` still sums the
        // sub-questions, so attaching a group to an exam cannot quietly change
        // what the paper is worth.
        // ------------------------------------------------------------------
        if (! Schema::hasTable('cbt_question_groups')) {
            Schema::create('cbt_question_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('subject_id')->nullable()->constrained()->onDelete('set null');
                // "Passage 2 — The Harmattan". Shown to the candidate.
                $table->string('title');
                // The passage, table or scenario itself.
                $table->text('stimulus')->nullable();
                // Same enum as questions, so a stimulus can carry LaTeX.
                $table->string('content_format')->default('plain');
                // [{ "asset_id": 12, "role": "stimulus", "position": 0 }, ...]
                $table->json('media')->nullable();
                $table->text('instructions')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['school_id', 'subject_id']);
            });
        }

        // ------------------------------------------------------------------
        // The link from a question to its group. Nullable on purpose: a
        // standalone question is the normal case and must stay untouched.
        // ------------------------------------------------------------------
        if (Schema::hasTable('question_bank')) {
            Schema::table('question_bank', function (Blueprint $table) {
                if (! Schema::hasColumn('question_bank', 'group_id')) {
                    $table->foreignId('group_id')
                        ->nullable()
                        ->constrained('cbt_question_groups')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('question_bank', 'group_sequence')) {
                    // Position within the group — (a), (b), (c). Ordering
                    // inside a group is authored, not incidental, because
                    // sub-questions routinely build on each other.
                    $table->unsignedInteger('group_sequence')->nullable();
                }
            });
        }

        // ------------------------------------------------------------------
        // Per-exam grouping behaviour. Defaults to false because shuffling
        // (a), (b) and (c) of a structured question is usually wrong.
        // ------------------------------------------------------------------
        if (Schema::hasTable('cbt_exams') && ! Schema::hasColumn('cbt_exams', 'shuffle_within_group')) {
            Schema::table('cbt_exams', function (Blueprint $table) {
                $table->boolean('shuffle_within_group')->default(false);
            });
        }

        // ------------------------------------------------------------------
        // Offline bundles.
        //
        // `content_key` is the 256-bit AES-GCM key the relay needs to read the
        // bundle it downloaded the day before. It is encrypted at rest with
        // the app key and released only through the audited key endpoint,
        // never in the bundle response itself — that separation is the whole
        // security model (§8.1). The bundle sits on a lab laptop overnight as
        // ciphertext whose key does not exist on that machine yet.
        //
        // `attempt_ids` is the roster. Batch sync authorises every incoming
        // attempt against it, so a relay cannot post answers for a candidate
        // who was never on the paper it was issued.
        // ------------------------------------------------------------------
        if (! Schema::hasTable('cbt_offline_bundles')) {
            Schema::create('cbt_offline_bundles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('exam_id')->constrained('cbt_exams')->onDelete('cascade');
                $table->uuid('bundle_id')->unique();
                $table->uuid('key_id');
                // Bumped when the bundle's shape changes. A client that meets a
                // newer version must refuse the paper and say so, rather than
                // failing obscurely at unlock (§17).
                $table->unsignedSmallInteger('format_version')->default(1);
                $table->text('content_key'); // encrypted cast
                $table->json('attempt_ids');
                $table->unsignedInteger('question_count')->default(0);
                $table->unsignedInteger('attempt_count')->default(0);
                $table->unsignedBigInteger('ciphertext_bytes')->default(0);
                // Free-text label for the machine that took delivery, so a
                // second issuance for the same exam is visibly a second relay.
                $table->string('relay_identity')->nullable();
                $table->foreignId('built_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('built_at');
                $table->timestamp('unlocked_at')->nullable();
                $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'exam_id']);
            });
        }

        // ------------------------------------------------------------------
        // Attempt-side additions for pre-issued papers.
        //
        // `order_is_final` is the compatibility hinge. Attempts created before
        // this migration store an *unordered* question_order and have their
        // shuffle applied at render time; re-deriving that order differently
        // would hand a resumed candidate a different paper than they sat.
        // New attempts store the flat, already-ordered list and set this true,
        // so the renderer serves what it is given (§10).
        // ------------------------------------------------------------------
        if (Schema::hasTable('cbt_attempts')) {
            Schema::table('cbt_attempts', function (Blueprint $table) {
                if (! Schema::hasColumn('cbt_attempts', 'offline_bundle_id')) {
                    $table->foreignId('offline_bundle_id')
                        ->nullable()
                        ->constrained('cbt_offline_bundles')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('cbt_attempts', 'order_is_final')) {
                    $table->boolean('order_is_final')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cbt_attempts')) {
            Schema::table('cbt_attempts', function (Blueprint $table) {
                if (Schema::hasColumn('cbt_attempts', 'offline_bundle_id')) {
                    $table->dropConstrainedForeignId('offline_bundle_id');
                }
                if (Schema::hasColumn('cbt_attempts', 'order_is_final')) {
                    $table->dropColumn('order_is_final');
                }
            });
        }

        Schema::dropIfExists('cbt_offline_bundles');

        if (Schema::hasTable('cbt_exams') && Schema::hasColumn('cbt_exams', 'shuffle_within_group')) {
            Schema::table('cbt_exams', function (Blueprint $table) {
                $table->dropColumn('shuffle_within_group');
            });
        }

        if (Schema::hasTable('question_bank')) {
            Schema::table('question_bank', function (Blueprint $table) {
                if (Schema::hasColumn('question_bank', 'group_id')) {
                    $table->dropConstrainedForeignId('group_id');
                }
                if (Schema::hasColumn('question_bank', 'group_sequence')) {
                    $table->dropColumn('group_sequence');
                }
            });
        }

        Schema::dropIfExists('cbt_question_groups');
    }
};
