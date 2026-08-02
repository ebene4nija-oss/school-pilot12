<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Scholarships & Fee Discounts/Installments
        if (!Schema::hasTable('scholarships')) {
            Schema::create('scholarships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->string('name');
                $table->enum('type', ['percentage', 'fixed'])->default('percentage');
                $table->decimal('value', 10, 2);
                $table->text('description')->nullable();
                $table->timestamps();
                $table->index(['school_id']);
            });
        }

        if (!Schema::hasTable('student_scholarships')) {
            Schema::create('student_scholarships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained()->onDelete('cascade');
                $table->foreignId('scholarship_id')->constrained()->onDelete('cascade');
                $table->foreignId('term_id')->nullable()->constrained()->onDelete('cascade');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('payment_installments')) {
            Schema::create('payment_installments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
                $table->decimal('amount', 12, 2);
                $table->date('due_date');
                $table->enum('status', ['pending', 'paid', 'overdue'])->default('pending');
                $table->timestamps();
            });
        }

        // 2. CBT Extended Question Types & Rubrics
        if (!Schema::hasTable('question_bank')) {
            Schema::create('question_bank', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('subject_id')->constrained()->onDelete('cascade');
                $table->string('topic')->nullable();
                $table->text('question');
                $table->json('options')->nullable();
                $table->text('correct_answer')->nullable();
                $table->string('difficulty')->default('medium');
                $table->string('question_type')->default('multiple_choice');
                $table->json('metadata')->nullable();
                $table->decimal('negative_marks', 5, 2)->default(0.00);
                $table->timestamps();
            });
        } else {
            Schema::table('question_bank', function (Blueprint $table) {
                if (!Schema::hasColumn('question_bank', 'question_type')) {
                    $table->string('question_type')->default('multiple_choice')->after('difficulty');
                }
                if (!Schema::hasColumn('question_bank', 'metadata')) {
                    $table->json('metadata')->nullable()->after('question_type');
                }
                if (!Schema::hasColumn('question_bank', 'negative_marks')) {
                    $table->decimal('negative_marks', 5, 2)->default(0.00)->after('metadata');
                }
            });
        }

        if (!Schema::hasTable('rubrics')) {
            Schema::create('rubrics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->string('title');
                $table->json('criteria'); // Criteria breakdown with points
                $table->timestamps();
            });
        }

        // 3. Gamification Engine (Points, Badges, Certificates)

        if (!Schema::hasTable('certificates')) {
            Schema::create('certificates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('student_id')->constrained()->onDelete('cascade');
                $table->string('title');
                $table->string('certificate_number')->unique();
                $table->date('issued_at');
                $table->string('pdf_url')->nullable();
                $table->timestamps();
            });
        }

        // 4. Course Resource Uploads
        if (!Schema::hasTable('course_resources')) {
            Schema::create('course_resources', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('subject_id')->constrained()->onDelete('cascade');
                $table->foreignId('class_id')->nullable()->constrained()->onDelete('cascade');
                $table->string('title');
                $table->enum('type', ['pdf', 'audio', 'video', 'document'])->default('pdf');
                $table->string('file_url');
                $table->timestamps();
            });
        }

        // 5. Transportation (Hardware-free GPS Bus Tracking)
        if (!Schema::hasTable('buses')) {
            Schema::create('buses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->string('bus_number');
                $table->string('driver_name');
                $table->string('driver_phone');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('bus_routes')) {
            Schema::create('bus_routes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('bus_id')->constrained()->onDelete('cascade');
                $table->string('route_name');
                $table->boolean('is_active')->default(false);
                $table->decimal('current_lat', 10, 7)->nullable();
                $table->decimal('current_lng', 10, 7)->nullable();
                $table->timestamp('last_ping_at')->nullable();
                $table->timestamps();
            });
        }

        // 6. Hardware-free Digital Library
        if (!Schema::hasTable('books')) {
            Schema::create('books', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->string('title');
                $table->string('isbn')->nullable();
                $table->string('author');
                $table->string('barcode');
                $table->integer('total_copies')->default(1);
                $table->integer('available_copies')->default(1);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('borrow_records')) {
            Schema::create('borrow_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('book_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->date('borrowed_at');
                $table->date('due_date');
                $table->date('returned_at')->nullable();
                $table->timestamps();
            });
        }

        // 7. Hostel / Boarding Management
        if (!Schema::hasTable('rooms')) {
            Schema::create('rooms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->string('hostel_name');
                $table->string('room_number');
                $table->integer('capacity')->default(4);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('bed_assignments')) {
            Schema::create('bed_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('room_id')->constrained()->onDelete('cascade');
                $table->foreignId('student_id')->constrained()->onDelete('cascade');
                $table->string('bed_number');
                $table->timestamps();
            });
        }

        // 8. Health & School Clinic Log
        if (!Schema::hasTable('clinic_visits')) {
            Schema::create('clinic_visits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('student_id')->constrained()->onDelete('cascade');
                $table->text('symptoms');
                $table->text('treatment');
                $table->string('attending_nurse');
                $table->timestamp('visited_at')->useCurrent();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_visits');
        Schema::dropIfExists('bed_assignments');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('borrow_records');
        Schema::dropIfExists('books');
        Schema::dropIfExists('bus_routes');
        Schema::dropIfExists('buses');
        Schema::dropIfExists('course_resources');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('student_gamification');
        Schema::dropIfExists('rubrics');
        Schema::dropIfExists('payment_installments');
        Schema::dropIfExists('student_scholarships');
        Schema::dropIfExists('scholarships');
    }
};
