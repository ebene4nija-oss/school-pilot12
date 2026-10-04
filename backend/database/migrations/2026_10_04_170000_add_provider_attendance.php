<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Virtual-class attendance from the video provider's own records (TASK 14
 * §3.3): who was in the Zoom or Google Meet call and for how long, rather
 * than only how long the SchoolPilot lesson page was open.
 *
 * Data minimisation (doc §12): participant emails are used to match at sync
 * time and never stored; a display name is kept only for a participant
 * nobody could match, so the teacher can say who it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The school's own Zoom / Google Workspace API access, encrypted at rest.
        Schema::create('video_provider_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20); // zoom | google_meet
            $table->text('credentials');
            $table->string('status', 20)->default('connected'); // connected | failing
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'provider']);
        });

        Schema::table('live_classes', function (Blueprint $table) {
            $table->string('provider_sync_status', 20)->nullable(); // synced | failed | no_report
            $table->timestamp('provider_synced_at')->nullable();
            $table->string('provider_sync_error', 500)->nullable();
            $table->unsignedTinyInteger('provider_sync_attempts')->default(0);
        });

        Schema::table('live_class_participants', function (Blueprint $table) {
            $table->unsignedInteger('provider_seconds')->nullable();
            $table->timestamp('provider_first_joined_at')->nullable();
        });

        // Each stretch someone was in the call, as the provider reported it.
        Schema::create('live_class_provider_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('live_class_id')->constrained()->cascadeOnDelete();
            $table->string('participant_key', 191);
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            // Kept only while unmatched; cleared once a student is assigned.
            $table->string('display_name', 191)->nullable();
            $table->string('match', 12)->nullable(); // email | tag | name | alias | teacher
            $table->timestamp('joined_at');
            $table->timestamp('left_at');
            $table->timestamps();

            $table->index(['live_class_id', 'participant_key']);
        });

        // "Ada's tablet" is Ada: remembered per class for next time.
        Schema::create('video_participant_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->cascadeOnDelete();
            $table->string('alias', 191);
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'class_id', 'alias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_participant_aliases');
        Schema::dropIfExists('live_class_provider_sessions');
        Schema::table('live_class_participants', function (Blueprint $table) {
            $table->dropColumn(['provider_seconds', 'provider_first_joined_at']);
        });
        Schema::table('live_classes', function (Blueprint $table) {
            $table->dropColumn(['provider_sync_status', 'provider_synced_at', 'provider_sync_error', 'provider_sync_attempts']);
        });
        Schema::dropIfExists('video_provider_connections');
    }
};
