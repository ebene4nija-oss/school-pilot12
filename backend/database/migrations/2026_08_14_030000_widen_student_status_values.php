<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `students.status` was an enum of four values fixed at first migration —
     * active, graduated, transferred, suspended — with no room for a student
     * who simply left. A rollover has to be able to record `withdrawn`, and a
     * check constraint that has to be rebuilt for every new outcome is a
     * migration per outcome forever.
     *
     * Widened to a plain string. The allowed set now lives in
     * Student::STATUSES. No endpoint takes a status directly — it is always a
     * consequence of an action (promote, graduate, transfer out, withdraw) —
     * so the constraint that matters is the one on those actions, which name
     * the outcome explicitly rather than accepting a free-text state.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });

        /*
         * Postgres does not implement `enum` as a type here — Laravel writes a
         * varchar with a CHECK constraint, and changing the column's type
         * leaves that constraint in place. Without this the migration appears
         * to succeed and then the first `withdrawn` write fails in production,
         * which is exactly the sort of difference that only shows up after
         * SQLite tests have gone green.
         */
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE students DROP CONSTRAINT IF EXISTS students_status_check');
        }
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->enum('status', ['active', 'graduated', 'transferred', 'suspended'])
                ->default('active')
                ->change();
        });
    }
};
