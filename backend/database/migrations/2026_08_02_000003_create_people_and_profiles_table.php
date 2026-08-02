<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('role', ['super_admin', 'school_admin', 'teacher', 'student', 'parent'])->default('student');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('avatar_url')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->string('two_factor_secret')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'role']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('class_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('arm_id')->nullable()->constrained()->onDelete('set null');
            $table->string('admission_number')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->default('male');
            $table->string('state_of_origin')->nullable();
            $table->string('lga')->nullable();
            $table->string('religion')->nullable();
            
            // NDPA Application-Level Encrypted Fields
            $table->text('blood_group')->nullable();
            $table->text('allergies')->nullable(); // Encrypted JSON array
            $table->text('medical_notes')->nullable();
            $table->string('previous_school')->nullable();

            $table->enum('status', ['active', 'graduated', 'transferred', 'suspended'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'created_at']);
            $table->index(['school_id', 'class_id', 'arm_id']);
        });

        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('occupation')->nullable();
            $table->string('relationship')->default('parent');
            $table->timestamps();
        });

        Schema::create('student_guardian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->foreignId('guardian_id')->constrained()->onDelete('cascade');
            $table->boolean('is_primary')->default(true);
            $table->timestamps();
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('staff_id')->nullable();
            $table->string('designation')->nullable();
            $table->string('qualification')->nullable();
            $table->decimal('salary', 12, 2)->default(0.00);
            $table->date('employment_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'staff_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
        Schema::dropIfExists('student_guardian');
        Schema::dropIfExists('guardians');
        Schema::dropIfExists('students');
        Schema::dropIfExists('user_profiles');
    }
};
