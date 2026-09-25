<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled virtual lessons.
 *
 * SchoolPilot does not host video. A teacher creates the room in Zoom or Meet
 * and pastes the link here; this table is the timetable of those sessions and
 * the record of who was meant to attend. That keeps the feature inside the
 * hardware-free rule (doc §3) and means no school is blocked on SchoolPilot
 * holding a video vendor's credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('class_id')->nullable()->constrained('classes')->onDelete('set null');
            $table->foreignId('arm_id')->nullable()->constrained('arms')->onDelete('set null');
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->onDelete('set null');
            $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');

            $table->string('title');
            $table->text('description')->nullable();

            // Zoom, Google Meet, or anything else the school already uses. The
            // provider is recorded for display only; the link is what matters.
            $table->enum('provider', ['zoom', 'google_meet', 'other'])->default('other');
            $table->text('meeting_url');
            $table->string('meeting_id')->nullable();
            // Nullable on purpose: plenty of teachers run an open room with no
            // passcode, and storing a placeholder would be a lie.
            $table->string('passcode')->nullable();

            $table->timestamp('scheduled_for');
            $table->unsignedSmallInteger('duration_minutes')->default(45);

            $table->enum('status', ['scheduled', 'live', 'ended', 'cancelled'])->default('scheduled');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            // The dominant query is "this school's sessions, soonest first".
            $table->index(['school_id', 'scheduled_for']);
            $table->index(['school_id', 'status']);
            $table->index(['teacher_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_classes');
    }
};
