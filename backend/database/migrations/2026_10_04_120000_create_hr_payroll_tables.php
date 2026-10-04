<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff employment lifecycle and payroll (TASK 19, 20).
 *
 * Money is Naira, decimal(12,2). Statutory deductions are school-configured
 * components, never hard-coded rates. No bank or tax identifiers are stored
 * (NDPA minimisation, doc §12): pay leaves through the school's own bank and
 * SchoolPilot records the reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('employment_status', 20)->default('active'); // active | on_leave | suspended | separated
            $table->string('employment_type', 20)->default('full_time'); // full_time | part_time | contract
            $table->string('department', 100)->nullable();
            $table->date('separated_on')->nullable();
            $table->string('separation_reason', 30)->nullable(); // resigned | retired | dismissed | contract_ended | deceased | other
        });

        Schema::create('staff_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('from', 20)->nullable();
            $table->string('to', 20);
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('pay_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 12); // earning | deduction
            $table->string('calc', 20)->default('fixed'); // fixed | percent_of_basic | percent_of_gross
            $table->decimal('default_value', 12, 2)->default(0); // Naira, or a percentage
            $table->boolean('statutory')->default(false); // e.g. PAYE, pension; rates set by the school
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });

        // A new row per change; history is never overwritten.
        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->decimal('basic_monthly', 12, 2);
            $table->json('components')->nullable(); // [{component_id, value}]
            $table->date('effective_from');
            $table->string('note', 255)->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['staff_id', 'effective_from']);
        });

        Schema::create('staff_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('kind', 12)->default('loan'); // loan | advance
            $table->decimal('principal', 12, 2);
            $table->decimal('monthly_deduction', 12, 2);
            $table->decimal('balance', 12, 2);
            $table->string('status', 12)->default('active'); // active | settled | written_off
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 12)->default('draft'); // draft | approved | paid | superseded
            $table->decimal('total_gross', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->unsignedSmallInteger('staff_count')->default(0);
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('self_approval_reason', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->string('reopen_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'period', 'version']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->decimal('basic', 12, 2);
            $table->json('earnings');
            $table->json('deductions');
            $table->decimal('gross', 12, 2);
            $table->decimal('total_deductions', 12, 2);
            $table->decimal('net', 12, 2);
            $table->unsignedSmallInteger('leave_days')->default(0);
            $table->timestamps();

            $table->unique(['run_id', 'staff_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('staff_loans');
        Schema::dropIfExists('salary_structures');
        Schema::dropIfExists('pay_components');
        Schema::dropIfExists('staff_status_events');
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['employment_status', 'employment_type', 'department', 'separated_on', 'separation_reason']);
        });
    }
};
