<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff leave (§7.3).
 *
 * `staff.leave_allocations` has existed as a JSON column since the Phase 1
 * migration with no code reading or writing it, and there was no way to
 * request or approve leave at all. Payroll worked; the people-management half
 * of §7.3 did not exist.
 *
 * Requests are their own table rather than more JSON on `staff`: an approval
 * workflow needs rows you can query ("who is off next Tuesday"), and a nested
 * JSON array cannot answer that without loading every staff record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_requests')) {
            return;
        }

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('staff_id')->constrained('staff')->onDelete('cascade');
            $table->string('leave_type', 32); // annual | sick | maternity | compassionate | study
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('days_requested');
            $table->text('reason')->nullable();

            $table->string('status', 16)->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
