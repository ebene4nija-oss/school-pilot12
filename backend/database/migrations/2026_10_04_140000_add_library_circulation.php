<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Library circulation (doc §9.2): borrowing, due dates and reservations.
 * The catalogue (`books`) and an unused `borrow_records` table already
 * existed; nothing ever lent or returned a book.
 *
 * No new personal data: a loan is a user, a book and dates. No fines —
 * money is the bursar's ledger, not the library's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrow_records', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('renewals')->default(0);
            $table->string('return_condition', 12)->nullable(); // good | damaged | lost
            $table->timestamp('due_reminder_at')->nullable();
            $table->timestamp('overdue_notice_at')->nullable();

            $table->index(['school_id', 'returned_at']);
            $table->index(['user_id', 'returned_at']);
        });

        // Loans written before this migration belong to their book's school.
        foreach (DB::table('borrow_records')->whereNull('school_id')->pluck('book_id', 'id') as $id => $bookId) {
            DB::table('borrow_records')->where('id', $id)->update(['school_id' => DB::table('books')->where('id', $bookId)->value('school_id')]);
        }

        Schema::create('book_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // waiting -> ready (a copy is held) -> fulfilled; or cancelled / expired.
            $table->string('status', 12)->default('waiting');
            $table->date('ready_until')->nullable();
            $table->timestamps();

            $table->index(['book_id', 'status']);
            $table->index(['school_id', 'status']);
        });

        Schema::create('library_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('student_loan_days')->default(14);
            $table->unsignedSmallInteger('staff_loan_days')->default(30);
            $table->unsignedTinyInteger('student_max_loans')->default(2);
            $table->unsignedTinyInteger('staff_max_loans')->default(5);
            $table->unsignedTinyInteger('max_renewals')->default(1);
            $table->unsignedTinyInteger('hold_days')->default(3);
            $table->boolean('students_reserve')->default(true);
            $table->boolean('notify_parents')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_settings');
        Schema::dropIfExists('book_reservations');
        Schema::table('borrow_records', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'returned_at']);
            $table->dropIndex(['user_id', 'returned_at']);
            $table->dropConstrainedForeignId('school_id');
            $table->dropConstrainedForeignId('issued_by');
            $table->dropConstrainedForeignId('returned_to');
            $table->dropColumn(['renewals', 'return_condition', 'due_reminder_at', 'overdue_notice_at']);
        });
    }
};
