<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a student sees at the end of a CBT paper, chosen when it is set.
 *
 * `show_results_immediately` already decided whether the score shows on
 * submit. Two more choices sit beside it:
 *
 *  - review_answers: each question with the student's own answer, marked
 *    right or wrong. On by default — what a released result showed before.
 *  - review_correct_answers: the correct option too. Off by default, and
 *    shown only once the paper has closed, so the first candidate out of the
 *    hall cannot pass the answers to the next sitting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_exams', function (Blueprint $table) {
            $table->boolean('review_answers')->default(true)->after('show_results_immediately');
            $table->boolean('review_correct_answers')->default(false)->after('review_answers');
        });
    }

    public function down(): void
    {
        Schema::table('cbt_exams', function (Blueprint $table) {
            $table->dropColumn(['review_answers', 'review_correct_answers']);
        });
    }
};
