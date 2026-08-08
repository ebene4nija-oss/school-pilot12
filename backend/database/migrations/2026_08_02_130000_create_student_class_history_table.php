<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_class_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->foreignId('from_class_id')->nullable()->constrained('classes')->onDelete('set null');
            $table->foreignId('from_arm_id')->nullable()->constrained('arms')->onDelete('set null');
            $table->foreignId('to_class_id')->constrained('classes')->onDelete('cascade');
            $table->foreignId('to_arm_id')->nullable()->constrained('arms')->onDelete('set null');
            $table->foreignId('session_id')->constrained('academic_sessions')->onDelete('cascade');
            $table->enum('action', ['promote', 'repeat', 'transfer'])->default('promote');
            $table->text('remarks')->nullable();
            $table->foreignId('performed_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->index(['school_id', 'student_id']);
            $table->index(['school_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_class_history');
    }
};
