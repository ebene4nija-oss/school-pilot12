<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a student sat, session by session.
     *
     * `students.class_id` answers "where is this child now" and nothing else,
     * and `student_class_history` is an audit trail — it records the moment a
     * move happened, not the state that resulted. Neither can answer "who was
     * in JSS 2 Gold in 2024/2025", which is the question a broadsheet, a
     * transcript, and a re-print of last year's report card all start from.
     *
     * This table is that state. `students.class_id`/`arm_id` stay as a cached
     * pointer to the current row, so every existing read path keeps working.
     */
    public function up(): void
    {
        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('arm_id')->nullable()->constrained('arms')->nullOnDelete();

            // Open, or closed by a rollover decision.
            $table->string('status')->default('active'); // active | closed

            /*
             * How the session ended for this child. Null while the session is
             * running — the distinction between "still in JSS 2" and "finished
             * JSS 2 and moved up" is exactly what was missing before.
             */
            $table->string('outcome')->nullable(); // promoted|repeated|graduated|transferred_out|withdrawn

            $table->date('enrolled_on')->nullable();
            $table->date('closed_on')->nullable();

            // Exit detail. Only ever populated on a transfer_out / withdrawn.
            $table->string('destination_school')->nullable();
            $table->text('outcome_remarks')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'session_id']);
            $table->index(['school_id', 'session_id', 'class_id']);
            $table->index(['school_id', 'status']);
        });

        Schema::table('classes', function (Blueprint $table) {
            /*
             * The terminal class — SS 3 in a secondary school, Primary 6 in a
             * primary one. Promotion out of it graduates a student instead of
             * failing for want of a destination, which is why a whole cohort
             * could never be moved on before.
             */
            $table->boolean('is_exit_class')->default(false)->after('order_index');
        });

        /*
         * Backfill: every active student gets an enrolment in the current
         * session at their present placement. Without this the first rollover
         * preview a school runs would find nobody.
         */
        if (Schema::hasTable('students') && Schema::hasTable('academic_sessions')) {
            $sessions = DB::table('academic_sessions')
                ->where('is_current', true)
                ->get(['id', 'school_id', 'start_date']);

            foreach ($sessions as $session) {
                $students = DB::table('students')
                    ->where('school_id', $session->school_id)
                    ->where('status', 'active')
                    ->whereNotNull('class_id')
                    ->whereNull('deleted_at')
                    ->get(['id', 'class_id', 'arm_id']);

                $now = now();

                foreach ($students->chunk(500) as $chunk) {
                    DB::table('student_enrollments')->insert(
                        $chunk->map(fn ($student) => [
                            'school_id' => $session->school_id,
                            'student_id' => $student->id,
                            'session_id' => $session->id,
                            'class_id' => $student->class_id,
                            'arm_id' => $student->arm_id,
                            'status' => 'active',
                            'outcome' => null,
                            'enrolled_on' => $session->start_date,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all()
                    );
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('is_exit_class');
        });

        Schema::dropIfExists('student_enrollments');
    }
};
