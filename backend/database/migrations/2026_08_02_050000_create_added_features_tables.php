<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. NDPA Parental Consents Table
        Schema::create('parental_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('guardian_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->boolean('consent_given')->default(true);
            $table->boolean('ai_cross_border_consent_given')->default(true);
            $table->string('ip_address')->nullable();
            $table->timestamp('consented_at')->useCurrent();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'guardian_id', 'student_id']);
        });

        // 2. Staff HR Enhancements (Qualifications, Salary, Leave Allocation)
        Schema::table('staff', function (Blueprint $table) {
            if (!Schema::hasColumn('staff', 'qualifications')) {
                $table->json('qualifications')->nullable()->after('qualification');
            }
            if (!Schema::hasColumn('staff', 'employment_history')) {
                $table->json('employment_history')->nullable()->after('employment_date');
            }
            if (!Schema::hasColumn('staff', 'leave_allocations')) {
                $table->json('leave_allocations')->nullable()->after('salary');
            }
        });

        // 3. Teacher-Parent In-App Messaging
        Schema::create('message_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('parent_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('student_id')->nullable()->constrained()->onDelete('set null');
            $table->string('subject')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'teacher_id', 'parent_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('message_threads')->onDelete('cascade');
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
        
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['qualifications', 'employment_history', 'leave_allocations']);
        });

        Schema::dropIfExists('parental_consents');
    }
};
