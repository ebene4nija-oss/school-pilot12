<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SchoolPilot telling its customers something: maintenance windows, releases,
 * policy changes.
 *
 * To school administrators only. Parents are the school's contacts, not
 * SchoolPilot's; messaging them directly would make the platform the data
 * controller for those numbers (NDPA, doc §12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_announcements', function (Blueprint $table) {
            $table->id();
            // Null means every school.
            $table->foreignId('school_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('title', 150);
            $table->text('body');
            $table->string('tone', 16)->default('info'); // info | warning
            $table->timestamp('shows_until');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['shows_until', 'withdrawn_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_announcements');
    }
};
