<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Payroll Management
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('staff_id')->constrained('users')->onDelete('cascade');
            $table->string('month_year'); // e.g. "August 2026"
            $table->decimal('basic_salary', 12, 2);
            $table->decimal('allowances', 12, 2)->default(0.00);
            $table->decimal('deductions', 12, 2)->default(0.00);
            $table->decimal('net_salary', 12, 2);
            $table->enum('status', ['draft', 'approved', 'paid'])->default('draft');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'month_year']);
        });

        // 2. Vendors
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->timestamps();

            $table->index(['school_id']);
        });

        // 3. Expenses & Vendor Payments
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('vendor_id')->nullable()->constrained()->onDelete('set null');
            $table->string('category'); // e.g. "Utilities", "Maintenance", "Supplies", "Petty Cash", "Vendor Payment"
            $table->string('title');
            $table->decimal('amount', 12, 2);
            $table->date('expense_date');
            $table->enum('payment_method', ['cash', 'bank_transfer', 'cheque', 'petty_cash'])->default('bank_transfer');
            $table->enum('status', ['pending', 'approved', 'paid', 'rejected'])->default('paid');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'expense_date']);
            $table->index(['school_id', 'category']);
        });

        // 4. Petty Cash Log
        Schema::create('petty_cashes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('custodian_id')->constrained('users')->onDelete('cascade');
            $table->enum('type', ['disbursement', 'replenishment']);
            $table->decimal('amount', 12, 2);
            $table->string('purpose');
            $table->date('entry_date');
            $table->timestamps();

            $table->index(['school_id', 'entry_date']);
        });

        // 5. School Budgeting
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('term_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('category'); // e.g. "Academic Supplies", "Facilities", "Staff Salaries"
            $table->decimal('allocated_amount', 12, 2);
            $table->string('fiscal_year'); // e.g. "2026/2027"
            $table->timestamps();

            $table->index(['school_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('petty_cashes');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('payrolls');
    }
};
