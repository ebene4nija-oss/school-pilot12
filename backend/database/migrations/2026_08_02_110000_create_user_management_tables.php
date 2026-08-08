<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Custom Roles with granular permissions
        Schema::create('custom_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->json('permissions')->nullable();
            $table->boolean('is_system_default')->default(false);
            $table->timestamps();
            $table->unique(['school_id', 'slug']);
        });

        // Pivot: users ↔ custom_roles (multi-role support)
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('custom_role_id')->constrained('custom_roles')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps();
            $table->unique(['user_id', 'custom_role_id']);
        });

        // Login history & session audit
        Schema::create('login_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->string('device_type')->nullable();
            $table->timestamp('login_at')->useCurrent();
            $table->timestamp('logout_at')->nullable();
            $table->string('status')->default('success');
            $table->string('failure_reason')->nullable();
            $table->string('location')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'login_at']);
        });

        // User activity timeline
        Schema::create('user_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'user_id', 'created_at']);
        });

        // Enhance users table
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active')->after('password');
            $table->integer('failed_login_attempts')->default(0)->after('status');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->timestamp('last_login_at')->nullable()->after('locked_until');
            $table->timestamp('password_changed_at')->nullable()->after('last_login_at');
            $table->boolean('must_change_password')->default(false)->after('password_changed_at');
            $table->index('status');
        });

        // Enhance user_profiles table
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->text('bio')->nullable()->after('avatar_url');
            $table->date('date_of_birth')->nullable()->after('bio');
            $table->string('gender')->nullable()->after('date_of_birth');
            $table->string('nationality')->nullable()->after('gender');
            $table->string('state_of_origin')->nullable()->after('nationality');
            $table->string('lga')->nullable()->after('state_of_origin');
            $table->string('emergency_contact_name')->nullable()->after('lga');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->integer('profile_completion_percentage')->default(0)->after('emergency_contact_phone');
            $table->string('two_factor_method')->nullable()->after('two_factor_secret');
            $table->text('recovery_codes')->nullable()->after('two_factor_method');
            $table->string('sso_provider')->nullable()->after('recovery_codes');
            $table->string('sso_provider_id')->nullable()->after('sso_provider');
        });

        // Teacher extended profiles
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('employee_id')->nullable();
            $table->string('qualification')->nullable();
            $table->string('specialization')->nullable();
            $table->integer('years_of_experience')->nullable();
            $table->json('certifications')->nullable();
            $table->json('subjects_taught')->nullable();
            $table->decimal('performance_rating', 3, 2)->nullable();
            $table->date('date_joined')->nullable();
            $table->string('contract_type')->nullable();
            $table->timestamps();
        });

        // Parent extended profiles
        Schema::create('parent_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('occupation')->nullable();
            $table->string('workplace')->nullable();
            $table->string('relationship_to_student')->nullable();
            $table->string('alternate_phone')->nullable();
            $table->string('alternate_email')->nullable();
            $table->string('custody_type')->nullable();
            $table->integer('emergency_priority')->default(1);
            $table->string('preferred_contact_method')->nullable();
            $table->timestamps();
        });

        // Student achievement portfolios
        Schema::create('student_portfolios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('achievement_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('date_achieved')->nullable();
            $table->string('evidence_url')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_portfolios');
        Schema::dropIfExists('parent_profiles');
        Schema::dropIfExists('teacher_profiles');

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn(['bio', 'date_of_birth', 'gender', 'nationality', 'state_of_origin', 'lga', 'emergency_contact_name', 'emergency_contact_phone', 'profile_completion_percentage', 'two_factor_method', 'recovery_codes', 'sso_provider', 'sso_provider_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'failed_login_attempts', 'locked_until', 'last_login_at', 'password_changed_at', 'must_change_password']);
        });

        Schema::dropIfExists('user_activity_logs');
        Schema::dropIfExists('login_histories');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('custom_roles');
    }
};
