<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a teacher dealt with an AI remark draft, and when.
 *
 * `ai_comment_status` alone cannot tell "approved as written" from "rewritten,
 * then approved" — both end as `approved` — and that difference is the
 * measure of whether the drafts are any good. Status and timestamps only; the
 * remark text stays where it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('score_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('score_entries', 'ai_comment_review')) {
                // approved_as_is | edited | rejected. Null: not reviewed yet,
                // or reviewed before this column existed.
                $table->string('ai_comment_review', 20)->nullable();
            }
            if (! Schema::hasColumn('score_entries', 'ai_comment_generated_at')) {
                $table->timestamp('ai_comment_generated_at')->nullable();
            }
            if (! Schema::hasColumn('score_entries', 'ai_comment_reviewed_at')) {
                $table->timestamp('ai_comment_reviewed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('score_entries', function (Blueprint $table) {
            foreach (['ai_comment_review', 'ai_comment_generated_at', 'ai_comment_reviewed_at'] as $column) {
                if (Schema::hasColumn('score_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
