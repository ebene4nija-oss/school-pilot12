<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a lesson happens (TASK 07): a class's own room, another classroom,
 * the hall, a lab. With venues on the timetable, two classes booked into the
 * same lab at the same time is caught before publishing.
 *
 * Named `venues` because `rooms` is the old hostel table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 20)->default('classroom'); // classroom | lab | hall | library | field | other
            $table->unsignedSmallInteger('capacity')->nullable();
            // A class's home room: its lessons are here unless moved.
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            // A field or hall can take several classes at once (games, assembly).
            $table->boolean('shared')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });

        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->foreignId('venue_id')->nullable()->after('teacher_id')->constrained('venues')->nullOnDelete();
        });

        // A one-day move: "Thursday's Chemistry is in the lab".
        Schema::create('timetable_venue_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('timetable_entries')->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->string('reason', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['entry_id', 'date']);
            $table->index(['school_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_venue_changes');
        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venue_id');
        });
        Schema::dropIfExists('venues');
    }
};
