<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hostel management (TASK 17).
 *
 * Kept as separate responsibilities that share student and school records
 * (spec, final recommendation): enrolment, bed allocation, roll call, leave
 * approval, movement events, welfare, incidents, maintenance and charges.
 *
 *  - Hostel → room (with optional block and floor) → bed. A bed is the unit
 *    of allocation and has its own service status.
 *  - Allocations carry effective dates; a room change ends one and starts
 *    another, so history is never overwritten.
 *  - Check-in, check-out, departure and return are timestamped events.
 *    Approving leave does not mark anyone as gone.
 *  - Welfare and incident details are encrypted and restricted.
 *  - Charges go to Finance as invoice items. Payments stay in Finance.
 *
 * The legacy `rooms` and `bed_assignments` are copied in so there is one
 * source of truth; the old endpoints now read and write the new tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30)->nullable();
            $table->string('campus')->nullable();
            // Who the hostel houses, as the school describes it (e.g. "Boys, JSS").
            $table->string('placement_policy')->nullable();
            // active | inactive | closed
            $table->string('status', 10)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('hostel_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hostel_id')->constrained()->cascadeOnDelete();
            $table->string('block', 60)->nullable();
            $table->string('floor', 30)->nullable();
            $table->string('name', 60);
            $table->string('room_type', 30)->nullable();
            // Approved capacity; usable capacity excludes beds out of service.
            $table->unsignedSmallInteger('capacity');
            // available | out_of_service
            $table->string('status', 15)->default('available');
            $table->json('facilities')->nullable();
            $table->string('restrictions')->nullable();
            $table->timestamp('last_inspected_at')->nullable();
            $table->timestamps();

            $table->unique(['hostel_id', 'block', 'name']);
        });

        Schema::create('hostel_beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('hostel_rooms')->cascadeOnDelete();
            $table->string('label', 30);
            // available | out_of_service (occupied and reserved come from allocations)
            $table->string('status', 15)->default('available');
            $table->string('status_note')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'label']);
        });

        Schema::create('hostel_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('parents_see_bed')->default(true);
            $table->boolean('parents_request_leave')->default(true);
            $table->boolean('students_request_leave')->default(false);
            $table->boolean('students_report_maintenance')->default(true);
            $table->json('consent_leave_types')->nullable();
            $table->unsignedSmallInteger('return_grace_minutes')->default(30);
            $table->timestamps();
        });

        Schema::create('hostel_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            // full | weekly | flexible | day_services
            $table->string('boarding_category', 15)->default('full');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->date('expected_arrival')->nullable();
            // draft | submitted | under_review | approved | rejected | waitlisted | active | suspended | completed | cancelled
            $table->string('status', 15)->default('submitted');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
        });

        Schema::create('hostel_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('hostel_enrollments')->nullOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bed_id')->constrained('hostel_beds')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            // reserved | active | ended
            $table->string('status', 10)->default('active');
            $table->string('reason')->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason')->nullable();
            $table->timestamps();

            $table->index(['bed_id', 'status']);
            $table->index(['student_id', 'status']);
        });

        Schema::create('hostel_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            // check_in | check_out | departure | return
            $table->string('type', 12);
            $table->timestamp('occurred_at');
            $table->foreignId('leave_request_id')->nullable();
            $table->string('collected_by')->nullable();
            $table->json('checklist')->nullable();
            $table->boolean('ends_stay')->default(false);
            $table->text('notes')->nullable();
            // A correction is a new row pointing at the one it corrects.
            $table->foreignId('corrects_id')->nullable()->constrained('hostel_movements')->nullOnDelete();
            $table->text('correction_reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['school_id', 'student_id', 'occurred_at']);
        });

        Schema::create('hostel_rollcall_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hostel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('time', 5);
            // ISO weekdays 1–7
            $table->json('days');
            $table->unsignedSmallInteger('grace_minutes')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hostel_rollcalls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('hostel_rollcall_sessions')->cascadeOnDelete();
            $table->foreignId('hostel_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // open | submitted
            $table->string('status', 10)->default('open');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'hostel_id', 'date']);
        });

        Schema::create('hostel_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rollcall_id')->constrained('hostel_rollcalls')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            // present | absent | on_leave | school_activity | medical | not_expected | unconfirmed
            $table->string('status', 16)->default('unconfirmed');
            $table->string('note')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('correction_reason')->nullable();
            $table->timestamps();

            $table->unique(['rollcall_id', 'student_id']);
        });

        Schema::create('hostel_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            // weekend | medical | outing | family | other
            $table->string('leave_type', 15);
            $table->string('reason');
            $table->timestamp('depart_at');
            $table->timestamp('return_at');
            $table->string('destination')->nullable();
            $table->string('pickup_person')->nullable();
            // not_required | pending | given | refused
            $table->string('guardian_consent', 12)->default('not_required');
            // parent | student | staff
            $table->string('requested_by_type', 8);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // submitted | awaiting_consent | approved | rejected | departed | returned | cancelled
            $table->string('status', 16)->default('submitted');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
        });

        Schema::create('hostel_welfare_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            // welfare_check | student_concern | guardian_contact | referral | follow_up
            $table->string('category', 20);
            $table->text('description');
            $table->text('action_taken')->nullable();
            $table->string('priority', 8)->default('normal');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('follow_up_on')->nullable();
            // open | closed
            $table->string('status', 8)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hostel_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30);
            $table->foreignId('hostel_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->string('location')->nullable();
            // safety | property_damage | conflict | rule_breach | missing_property | welfare | unauthorised_absence | other
            $table->string('category', 25);
            // A report is not a finding: what was reported is kept apart from the outcome.
            $table->text('description');
            $table->text('immediate_action')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('guardian_notified_at')->nullable();
            // submitted | under_review | follow_up_required | resolved | closed | reopened
            $table->string('status', 20)->default('submitted');
            $table->text('outcome')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'reference']);
        });

        Schema::create('hostel_incident_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('hostel_incidents')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // involved | affected | witness
            $table->string('role', 10);
        });

        Schema::create('hostel_incident_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('hostel_incidents')->cascadeOnDelete();
            $table->string('action', 30);
            $table->text('reason')->nullable();
            $table->json('changes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('hostel_staff_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hostel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // manager | warden | house_parent | matron
            $table->string('role', 15);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();
        });

        Schema::create('hostel_duty_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hostel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // morning | afternoon | evening | night
            $table->string('shift', 10);
            $table->foreignId('substitute_for')->nullable()->constrained('users')->nullOnDelete();
            $table->text('handover_notes')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps();

            $table->unique(['hostel_id', 'user_id', 'date', 'shift']);
        });

        Schema::create('hostel_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hostel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('hostel_rooms')->nullOnDelete();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            // good | fair | poor | damaged
            $table->string('condition', 10)->default('good');
            $table->boolean('issuable')->default(false);
            $table->timestamps();
        });

        Schema::create('hostel_asset_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('hostel_assets')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('issued_at');
            $table->timestamp('returned_at')->nullable();
            $table->string('condition_out', 10)->default('good');
            $table->string('condition_in', 10)->nullable();
            // issued | returned | lost | damaged
            $table->string('status', 10)->default('issued');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hostel_maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30);
            $table->foreignId('hostel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('hostel_rooms')->nullOnDelete();
            $table->foreignId('bed_id')->nullable()->constrained('hostel_beds')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('hostel_assets')->nullOnDelete();
            $table->string('category', 30);
            $table->text('description');
            // low | normal | high | urgent
            $table->string('priority', 8)->default('normal');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            // reported | acknowledged | assigned | in_progress | awaiting_parts | completed | verified | cancelled
            $table->string('status', 15)->default('reported');
            $table->text('work_notes')->nullable();
            $table->boolean('took_out_of_service')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'reference']);
        });

        Schema::create('hostel_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('hostel_rooms')->cascadeOnDelete();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at');
            $table->json('checklist')->nullable();
            // pass | attention | fail
            $table->string('result', 10);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('hostel_fee_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('boarding_category', 15);
            // boarding | meals | deposit | laundry | other
            $table->string('charge_type', 12);
            $table->string('title');
            // Naira.
            $table->decimal('amount', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hostel_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('charge_type', 12);
            $table->string('title');
            $table->decimal('amount', 12, 2);
            // Prevents billing the same thing twice.
            $table->string('dedupe_key', 120);
            // pending | posted | void
            $table->string('status', 8)->default('pending');
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained('invoice_items')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'dedupe_key']);
        });

        $this->copyLegacy();
    }

    /** Bring the old rooms and bed assignments into the new structure. */
    private function copyLegacy(): void
    {
        if (! Schema::hasTable('rooms')) {
            return;
        }

        $now = now();

        foreach (DB::table('rooms')->orderBy('id')->get() as $room) {
            $hostelId = DB::table('hostels')->where('school_id', $room->school_id)->where('name', $room->hostel_name)->value('id')
                ?? DB::table('hostels')->insertGetId(['school_id' => $room->school_id, 'name' => $room->hostel_name, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

            $roomId = DB::table('hostel_rooms')->insertGetId([
                'school_id' => $room->school_id, 'hostel_id' => $hostelId, 'name' => $room->room_number,
                'capacity' => $room->capacity, 'status' => 'available', 'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach (DB::table('bed_assignments')->where('room_id', $room->id)->get() as $a) {
                $bedId = DB::table('hostel_beds')->where('room_id', $roomId)->where('label', $a->bed_number)->value('id')
                    ?? DB::table('hostel_beds')->insertGetId(['school_id' => $room->school_id, 'room_id' => $roomId, 'label' => $a->bed_number, 'status' => 'available', 'created_at' => $now, 'updated_at' => $now]);

                DB::table('hostel_allocations')->insert([
                    'school_id' => $room->school_id, 'student_id' => $a->student_id, 'bed_id' => $bedId,
                    'starts_on' => substr((string) $a->created_at, 0, 10) ?: $now->toDateString(), 'status' => 'active',
                    'reason' => 'Carried over from the earlier hostel list', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'hostel_charges', 'hostel_fee_rules', 'hostel_inspections', 'hostel_maintenance_requests', 'hostel_asset_issues',
            'hostel_assets', 'hostel_duty_shifts', 'hostel_staff_assignments', 'hostel_incident_events', 'hostel_incident_people',
            'hostel_incidents', 'hostel_welfare_records', 'hostel_leave_requests', 'hostel_attendance', 'hostel_rollcalls',
            'hostel_rollcall_sessions', 'hostel_movements', 'hostel_allocations', 'hostel_enrollments', 'hostel_settings',
            'hostel_beds', 'hostel_rooms', 'hostels',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
