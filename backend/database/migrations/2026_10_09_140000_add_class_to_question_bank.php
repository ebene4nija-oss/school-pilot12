<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which class a bank question was written for.
 *
 * A school keeps one Mathematics for Primary 1 to SS 3, so a bank filed only
 * by subject mixes "count the apples" with calculus, and a Primary 4 paper's
 * picker offered SS 3 questions. The API requires a class on every new
 * question. Questions written before this stay null — "unsorted" — until
 * someone files them with the bulk tools.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_bank', function (Blueprint $table) {
            $table->foreignId('class_id')->nullable()->after('subject_id')->constrained('classes')->nullOnDelete();
            $table->index(['school_id', 'class_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('question_bank', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'class_id', 'subject_id']);
            $table->dropConstrainedForeignId('class_id');
        });
    }
};
