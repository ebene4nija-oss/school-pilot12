<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** School control over the student AI tutor (TASK 15). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            // null = every section; otherwise e.g. ["primary", "secondary"].
            $table->json('sections')->nullable();
            // null = every subject; otherwise subject ids the tutor may help with.
            $table->json('subject_ids')->nullable();
            $table->unsignedSmallInteger('daily_message_limit')->nullable()->default(60);
            // No tutor while the student is sitting a CBT exam.
            $table->boolean('exam_lock')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_settings');
    }
};
