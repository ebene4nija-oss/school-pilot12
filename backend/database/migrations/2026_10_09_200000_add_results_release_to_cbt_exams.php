<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Releasing one CBT paper's results to students and their parents, on the
 * teacher's word, for a paper that did not show the score on submit.
 *
 * Separate from the term's result release (result_releases): a released CBT
 * score is that paper's score only, and says nothing about the report card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_exams', function (Blueprint $table) {
            $table->timestamp('results_released_at')->nullable()->after('review_correct_answers');
            $table->foreignId('results_released_by')->nullable()->after('results_released_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cbt_exams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('results_released_by');
            $table->dropColumn('results_released_at');
        });
    }
};
