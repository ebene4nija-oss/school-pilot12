<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A CA filled from homework and quizzes (TASK 08, 21). The teacher chooses
 * the work and adjusts each student's mark; this keeps what the system
 * suggested beside what the teacher entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ca_coursework_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('component', 20);
            // {homework_ids: [], exam_ids: [], missing: zero|skip}
            $table->json('sources');
            // [{student_id, suggested, final}]
            $table->json('rows');
            $table->unsignedSmallInteger('adjusted_count')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'term_id', 'class_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ca_coursework_imports');
    }
};
