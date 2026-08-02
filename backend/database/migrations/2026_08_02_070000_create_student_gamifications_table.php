<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_gamifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->integer('points')->default(0);
            $table->integer('current_streak')->default(0);
            $table->date('last_activity_date')->nullable();
            $table->json('badges')->nullable(); // Array of earned badge strings e.g. ["Quiz Master", "7-Day Streak"]
            $table->timestamps();

            $table->unique(['school_id', 'student_id']);
            $table->index(['school_id', 'points']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_gamifications');
    }
};
