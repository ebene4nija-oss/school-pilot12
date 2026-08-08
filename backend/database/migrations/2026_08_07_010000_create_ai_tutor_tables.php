<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Learning Hub — the student tutor (doc §7.11).
 *
 * The tutor endpoint previously returned a hardcoded sentence ("Great
 * question! Let's break down key concepts together.") for every message from
 * every student, and kept no record of anything. §7.11 asks for a tutor "tied
 * to what the student's own teacher actually assigned", with topic mastery
 * tracking — neither is possible without somewhere to keep the conversation.
 *
 * Conversations are per-student and school-scoped. They are also the record a
 * school can inspect if a parent asks what the AI told their child, which is
 * not optional for a product aimed at minors.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tutor_conversations')) {
            Schema::create('tutor_conversations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
                // Optional anchor: a conversation started from a subject or a
                // specific homework gets that context injected automatically.
                $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
                $table->foreignId('homework_id')->nullable()->constrained('homework')->nullOnDelete();
                $table->string('title');
                $table->timestamp('last_message_at')->nullable();
                $table->unsignedInteger('message_count')->default(0);
                $table->timestamps();

                $table->index(['school_id', 'student_id', 'last_message_at']);
            });
        }

        if (!Schema::hasTable('tutor_messages')) {
            Schema::create('tutor_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('tutor_conversations')->onDelete('cascade');
                $table->string('role', 16); // 'student' | 'tutor'
                $table->text('body');
                // Set when the guardrail redirected a "just give me the answer"
                // request. Lets a school see how often it fires without reading
                // every conversation.
                $table->boolean('was_redirected')->default(false);
                $table->string('topic')->nullable();
                $table->timestamps();

                $table->index(['conversation_id', 'id']);
            });
        }

        /*
         * Topic mastery (§7.11). Fed from CBT item results and from tutor
         * sessions, so "areas to work on" reflects what the child actually got
         * wrong rather than a self-report.
         */
        if (!Schema::hasTable('student_topic_mastery')) {
            Schema::create('student_topic_mastery', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
                $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
                $table->string('topic');
                $table->unsignedInteger('questions_attempted')->default(0);
                $table->unsignedInteger('questions_correct')->default(0);
                $table->unsignedInteger('tutor_sessions')->default(0);
                $table->decimal('mastery_percentage', 5, 2)->default(0);
                $table->timestamp('last_practised_at')->nullable();
                $table->timestamps();

                $table->unique(['student_id', 'subject_id', 'topic'], 'student_topic_unique');
                $table->index(['school_id', 'student_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_topic_mastery');
        Schema::dropIfExists('tutor_messages');
        Schema::dropIfExists('tutor_conversations');
    }
};
