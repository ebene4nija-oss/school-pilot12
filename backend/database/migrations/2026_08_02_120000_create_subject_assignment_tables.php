<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Teacher ↔ Subject assignment (which teacher teaches which subject in which class)
        Schema::create('teacher_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->boolean('is_primary')->default(true); // primary vs assistant teacher
            $table->timestamps();
            $table->unique(['school_id', 'teacher_id', 'subject_id', 'class_id'], 'ts_unique');
        });

        // Class ↔ Subject mapping (which subjects are offered per class, compulsory or optional)
        Schema::create('class_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->boolean('is_compulsory')->default(true);
            $table->integer('max_students')->nullable(); // cap for electives
            $table->timestamps();
            $table->unique(['school_id', 'class_id', 'subject_id']);
        });

        // Student ↔ Subject enrollment (student's chosen subjects for their class)
        Schema::create('student_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained('terms')->nullOnDelete();
            $table->string('enrollment_type')->default('compulsory'); // compulsory or elective
            $table->string('status')->default('active'); // active, dropped, completed
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('dropped_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'subject_id', 'term_id']);
        });

        // Add department/level scoping to subjects table
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('department')->nullable()->after('category'); // Science, Arts, Commercial
            $table->text('description')->nullable()->after('department');
            $table->integer('credit_units')->default(1)->after('description');
            $table->boolean('is_active')->default(true)->after('credit_units');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn(['department', 'description', 'credit_units', 'is_active']);
        });

        Schema::dropIfExists('student_subjects');
        Schema::dropIfExists('class_subjects');
        Schema::dropIfExists('teacher_subjects');
    }
};
