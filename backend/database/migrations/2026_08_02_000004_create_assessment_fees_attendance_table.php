<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ca_schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->string('name'); // e.g. "Standard 30/70"
            $table->integer('first_ca_weight')->default(15);
            $table->integer('second_ca_weight')->default(15);
            $table->integer('exam_weight')->default(70);
            $table->boolean('is_default')->default(true);
            $table->timestamps();
        });

        Schema::create('score_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('term_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->foreignId('subject_id')->constrained()->onDelete('cascade');
            $table->decimal('first_ca', 5, 2)->default(0);
            $table->decimal('second_ca', 5, 2)->default(0);
            $table->decimal('exam', 5, 2)->default(0);
            $table->decimal('total_score', 5, 2)->default(0);
            $table->string('grade', 5)->nullable();
            $table->integer('position_in_class')->nullable();
            $table->text('teacher_comment')->nullable();
            $table->enum('ai_comment_status', ['none', 'pending_approval', 'approved', 'rejected'])->default('none');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'student_id', 'term_id']);
            $table->index(['school_id', 'term_id', 'subject_id']);
        });

        Schema::create('report_card_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->foreignId('term_id')->constrained()->onDelete('cascade');
            $table->string('qr_token')->unique();
            $table->boolean('is_valid')->default(true);
            $table->timestamps();
        });

        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('term_id')->constrained()->onDelete('cascade');
            $table->foreignId('class_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('title'); // e.g. "JSS 1 Tuition & Tech Fee"
            $table->decimal('amount', 12, 2)->default(0.00);
            $table->boolean('is_mandatory')->default(true);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('term_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->string('invoice_number')->unique();
            $table->decimal('total_amount', 12, 2)->default(0.00);
            $table->decimal('amount_paid', 12, 2)->default(0.00);
            $table->enum('status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->date('due_date')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'student_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            $table->string('reference')->unique();
            $table->decimal('amount', 12, 2);
            $table->enum('gateway', ['paystack', 'flutterwave', 'bank_transfer', 'cash'])->default('bank_transfer');
            $table->enum('status', ['pending', 'successful', 'failed'])->default('successful');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'reference']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('term_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('staff_id')->nullable()->constrained()->onDelete('cascade');
            $table->date('date');
            $table->enum('status', ['present', 'absent', 'late', 'excused'])->default('present');
            $table->decimal('latitude', 10, 7)->nullable(); // Software GPS check-in for staff
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'date']);
            $table->index(['school_id', 'student_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('fee_structures');
        Schema::dropIfExists('report_card_tokens');
        Schema::dropIfExists('score_entries');
        Schema::dropIfExists('ca_schemes');
    }
};
