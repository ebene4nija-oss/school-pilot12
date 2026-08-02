<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Homework & Assignments
        if (!Schema::hasTable('homework')) {
            Schema::create('homework', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('class_id')->constrained('classes')->onDelete('cascade');
                $table->foreignId('subject_id')->constrained()->onDelete('cascade');
                $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
                $table->string('title');
                $table->text('description');
                $table->date('due_date');
                $table->timestamps();

                $table->index(['school_id', 'class_id']);
            });
        }

        // 2. Child Pickup Authorizations
        if (!Schema::hasTable('pickup_authorizations')) {
            Schema::create('pickup_authorizations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('student_id')->constrained()->onDelete('cascade');
                $table->foreignId('parent_id')->constrained('users')->onDelete('cascade');
                $table->string('authorized_person_name');
                $table->string('authorized_person_phone');
                $table->string('relationship');
                $table->string('photo_path')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['school_id', 'student_id']);
            });
        }

        // 3. Behavior & Conduct Log
        if (!Schema::hasTable('behavior_reports')) {
            Schema::create('behavior_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('student_id')->constrained()->onDelete('cascade');
                $table->foreignId('recorded_by')->constrained('users')->onDelete('cascade');
                $table->enum('type', ['positive', 'warning', 'incident'])->default('positive');
                $table->string('title');
                $table->text('description');
                $table->date('incident_date');
                $table->timestamps();

                $table->index(['school_id', 'student_id']);
            });
        }

        // 4. School Calendar Events
        if (!Schema::hasTable('school_calendar_events')) {
            Schema::create('school_calendar_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->string('title');
                $table->text('description')->nullable();
                $table->enum('category', ['academic', 'exam', 'holiday', 'sports', 'ptm'])->default('academic');
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->boolean('is_public')->default(true);
                $table->timestamps();

                $table->index(['school_id', 'start_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_calendar_events');
        Schema::dropIfExists('behavior_reports');
        Schema::dropIfExists('pickup_authorizations');
        Schema::dropIfExists('homework');
    }
};
