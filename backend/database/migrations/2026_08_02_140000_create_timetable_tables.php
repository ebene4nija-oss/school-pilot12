<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->foreignId('academic_session_id')->nullable();
            $table->foreignId('term_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
        });

        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_version_id')->constrained('timetable_versions')->cascadeOnDelete();
            $table->foreignId('school_class_id');
            $table->foreignId('subject_id')->nullable();
            $table->string('subject_name')->nullable();
            $table->foreignId('teacher_id')->nullable();
            $table->string('day_of_week');
            $table->string('slot_name');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('is_break')->default(false);
            $table->timestamps();

            $table->index(['timetable_version_id', 'school_class_id']);
            $table->index(['timetable_version_id', 'teacher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_entries');
        Schema::dropIfExists('timetable_versions');
    }
};
