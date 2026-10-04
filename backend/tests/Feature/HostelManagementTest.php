<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Guardian;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelEnrollment;
use App\Models\HostelStaffAssignment;
use App\Models\HostelWelfareRecord;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Hostel\HostelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Hostel management: the business rules in TASK 17 §26 and its acceptance criteria. */
class HostelManagementTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $warden;
    private User $matron;
    private User $parent;
    private Hostel $unity;
    private Hostel $peace;
    private int $roomId;
    private Student $ada;
    private Student $bayo;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $this->admin = $this->user('Admin', 'school_admin');
        $this->warden = $this->user('Warden', 'teacher');
        $this->matron = $this->user('Matron', 'nurse');
        $this->parent = $this->user('Mrs Obi', 'parent');
        AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addYear(), 'is_current' => true]);

        $this->unity = Hostel::create(['school_id' => $this->school->id, 'name' => 'Unity House', 'status' => 'active']);
        $this->peace = Hostel::create(['school_id' => $this->school->id, 'name' => 'Peace House', 'status' => 'active']);
        $this->roomId = app(HostelService::class)->createRoom($this->unity, ['name' => 'U1', 'capacity' => 2])->id;
        app(HostelService::class)->createRoom($this->peace, ['name' => 'P1', 'capacity' => 2]);

        HostelStaffAssignment::create(['school_id' => $this->school->id, 'hostel_id' => $this->unity->id, 'user_id' => $this->warden->id, 'role' => 'warden', 'starts_on' => now()->subDay()->toDateString()]);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $this->ada = $this->student('Ada Obi', $class);
        $this->bayo = $this->student('Bayo Ade', $class);
        Guardian::create(['school_id' => $this->school->id, 'user_id' => $this->parent->id])->students()->attach($this->ada->id);
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function student(string $name, SchoolClass $class): Student
    {
        return Student::create(['school_id' => $this->school->id, 'user_id' => $this->user($name, 'student')->id, 'class_id' => $class->id, 'admission_number' => strtoupper(substr($name, 0, 3)), 'status' => 'active']);
    }

    private function bed(string $label = 'B1', ?int $roomId = null): HostelBed
    {
        return HostelBed::where('room_id', $roomId ?? $this->roomId)->where('label', $label)->firstOrFail();
    }

    private function board(Student $s, string $label = 'B1'): int
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/enrollments", ['student_id' => $s->id, 'boarding_category' => 'full'])->assertCreated()->json('enrollment.id');
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/enrollments/{$id}/decision", ['decision' => 'approve'])->assertOk();

        return $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/allocations", ['student_id' => $s->id, 'bed_id' => $this->bed($label)->id])->assertCreated()->json('allocation.id');
    }

    public function test_a_bed_needs_an_approved_enrolment_and_a_waitlist_holds_no_bed(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/allocations", ['student_id' => $this->ada->id, 'bed_id' => $this->bed()->id])->assertStatus(409);

        $id = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/enrollments", ['student_id' => $this->ada->id, 'boarding_category' => 'full'])->json('enrollment.id');
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/enrollments/{$id}/decision", ['decision' => 'waitlist'])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/allocations", ['student_id' => $this->ada->id, 'bed_id' => $this->bed()->id])->assertStatus(409);

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/enrollments/{$id}/decision", ['decision' => 'approve'])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/allocations", ['student_id' => $this->ada->id, 'bed_id' => $this->bed()->id])->assertCreated();
        $this->assertSame('active', HostelEnrollment::find($id)->status);
    }

    public function test_no_double_booking_no_out_of_service_bed_and_capacity_needs_an_admin_override(): void
    {
        $this->board($this->ada);

        $id = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/enrollments", ['student_id' => $this->bayo->id, 'boarding_category' => 'full'])->json('enrollment.id');
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/enrollments/{$id}/decision", ['decision' => 'approve']);

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/allocations", ['student_id' => $this->bayo->id, 'bed_id' => $this->bed('B1')->id])->assertStatus(422)->assertJsonValidationErrors('bed_number');

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/beds/{$this->bed('B2')->id}/status", ['status' => 'out_of_service', 'note' => 'Broken frame'])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/allocations", ['student_id' => $this->bayo->id, 'bed_id' => $this->bed('B2')->id])->assertStatus(409);

        $rooms = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/hostel/structure?hostel_id={$this->unity->id}")->json('rooms');
        $this->assertSame(2, $rooms[0]['physical_beds']);
        $this->assertSame(1, $rooms[0]['usable_beds'], 'Out-of-service beds are not usable capacity.');
    }

    public function test_a_room_change_ends_the_old_allocation_and_keeps_it(): void
    {
        $allocationId = $this->board($this->ada);
        $peaceBed = HostelBed::whereIn('room_id', DB::table('hostel_rooms')->where('hostel_id', $this->peace->id)->pluck('id'))->first();

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/allocations/{$allocationId}/move", ['bed_id' => $peaceBed->id, 'reason' => 'Requested by house parent'])->assertOk();

        $history = HostelAllocation::where('student_id', $this->ada->id)->orderBy('id')->get();
        $this->assertSame(['ended', 'active'], $history->pluck('status')->all());
        $this->assertNotNull($history[0]->ends_on);
    }

    public function test_a_warden_works_only_in_their_own_hostel(): void
    {
        $this->board($this->ada);
        $session = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/rollcall-sessions", ['name' => 'Night', 'time' => '21:00', 'days' => [1, 2, 3, 4, 5, 6, 7]])->json('session.id');

        $this->actingAs($this->warden, 'sanctum')->postJson("{$this->host}/hostel/rollcalls/open", ['session_id' => $session, 'hostel_id' => $this->peace->id])->assertForbidden();
        $this->actingAs($this->warden, 'sanctum')->postJson("{$this->host}/hostel/rollcalls/open", ['session_id' => $session, 'hostel_id' => $this->unity->id])->assertOk();

        $this->assertSame(['Unity House'], array_column($this->actingAs($this->warden, 'sanctum')->getJson("{$this->host}/hostel/hostels")->json('data'), 'name'));
    }

    /** Rules 8–12: approval is not departure; roll call presets leave; unconfirmed is not presence. */
    public function test_leave_movement_overdue_and_roll_call(): void
    {
        $this->board($this->ada);
        $this->board($this->bayo, 'B2');
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/check-in", ['student_ids' => [$this->ada->id, $this->bayo->id]])->assertJsonPath('checked_in', 2);

        $leave = $this->actingAs($this->parent, 'sanctum')->postJson("{$this->host}/parent/hostel/leave", [
            'student_id' => $this->ada->id, 'leave_type' => 'weekend', 'reason' => 'Family wedding',
            'depart_at' => now()->subDays(2)->toDateTimeString(), 'return_at' => now()->subHours(3)->toDateTimeString(),
        ])->assertCreated()->json('leave');
        $this->assertSame('given', $leave['guardian_consent'], 'A parent\'s own request carries their consent.');

        $this->actingAs($this->parent, 'sanctum')->postJson("{$this->host}/parent/hostel/leave", ['student_id' => $this->bayo->id, 'leave_type' => 'weekend', 'reason' => 'x', 'depart_at' => now()->toDateTimeString(), 'return_at' => now()->addDay()->toDateTimeString()])->assertNotFound();

        $this->actingAs($this->warden, 'sanctum')->postJson("{$this->host}/hostel/leave/{$leave['id']}/decision", ['approve' => true])->assertOk();
        $this->assertSame('in_hostel', app(HostelService::class)->presence($this->ada->id), 'Approval does not mark a departure.');

        $this->actingAs($this->warden, 'sanctum')->postJson("{$this->host}/hostel/leave/{$leave['id']}/depart", ['collected_by' => 'Mrs Obi'])->assertOk();
        $this->assertSame('on_leave', app(HostelService::class)->presence($this->ada->id));
        $this->assertSame(1, $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/hostel/dashboard")->json('cards.overdue_returns'));

        $session = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/rollcall-sessions", ['name' => 'Night', 'time' => '21:00', 'days' => [1, 2, 3, 4, 5, 6, 7]])->json('session.id');
        $rc = $this->actingAs($this->warden, 'sanctum')->postJson("{$this->host}/hostel/rollcalls/open", ['session_id' => $session, 'hostel_id' => $this->unity->id])->json();
        $statuses = collect($rc['students'])->pluck('status', 'student_id');
        $this->assertSame('on_leave', $statuses[$this->ada->id]);
        $this->assertSame('unconfirmed', $statuses[$this->bayo->id]);

        $submitted = $this->actingAs($this->warden, 'sanctum')->postJson("{$this->host}/hostel/rollcalls/{$rc['rollcall']['id']}/submit")->assertOk()->json();
        $this->assertSame(1, $submitted['not_accounted_for']);

        // After submission, only a manager corrects, with a reason.
        $this->actingAs($this->warden, 'sanctum')->putJson("{$this->host}/hostel/rollcalls/{$rc['rollcall']['id']}/marks", ['marks' => [['student_id' => $this->bayo->id, 'status' => 'present']], 'correction_reason' => 'Was in prep'])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/hostel/rollcalls/{$rc['rollcall']['id']}/marks", ['marks' => [['student_id' => $this->bayo->id, 'status' => 'present']]])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/hostel/rollcalls/{$rc['rollcall']['id']}/marks", ['marks' => [['student_id' => $this->bayo->id, 'status' => 'present']], 'correction_reason' => 'Was in prep'])->assertOk();

        $this->actingAs($this->warden, 'sanctum')->postJson("{$this->host}/hostel/leave/{$leave['id']}/return")->assertOk();
        $this->assertSame('in_hostel', app(HostelService::class)->presence($this->ada->id));
    }

    public function test_welfare_records_are_restricted_and_encrypted(): void
    {
        $this->board($this->ada);

        $id = $this->actingAs($this->warden, 'sanctum')->postJson("{$this->host}/hostel/welfare", ['student_id' => $this->ada->id, 'category' => 'student_concern', 'description' => 'Homesick and not eating well'])->assertCreated()->json('id');

        $this->actingAs($this->warden, 'sanctum')->getJson("{$this->host}/hostel/welfare")->assertForbidden();
        $this->assertSame('Homesick and not eating well', $this->actingAs($this->matron, 'sanctum')->getJson("{$this->host}/hostel/welfare")->assertOk()->json('data.0.description'));

        $this->assertStringNotContainsString('Homesick', (string) DB::table('hostel_welfare_records')->where('id', $id)->value('description'));

        $this->actingAs($this->warden, 'sanctum')->postJson("{$this->host}/hostel/incidents", ['occurred_at' => now()->toDateTimeString(), 'category' => 'conflict', 'description' => 'Argument in the dormitory after lights out'])->assertCreated();
        $this->actingAs($this->warden, 'sanctum')->getJson("{$this->host}/hostel/incidents")->assertForbidden();
    }

    public function test_parents_see_only_their_own_child_and_the_bed_only_if_allowed(): void
    {
        $this->board($this->ada);
        $this->board($this->bayo, 'B2');

        $children = $this->actingAs($this->parent, 'sanctum')->getJson("{$this->host}/parent/hostel")->assertOk()->json('children');
        $this->assertCount(1, $children);
        $this->assertSame('Unity House', $children[0]['hostel']);
        $this->assertSame('B1', $children[0]['bed']);

        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/hostel/settings", ['parents_see_bed' => false])->assertOk();
        $this->assertNull($this->actingAs($this->parent, 'sanctum')->getJson("{$this->host}/parent/hostel")->json('children.0.bed'));

        $this->actingAs($this->parent, 'sanctum')->getJson("{$this->host}/hostel/residents")->assertForbidden();
    }

    public function test_charges_post_to_finance_once(): void
    {
        $this->board($this->ada);
        $term = Term::create(['school_id' => $this->school->id, 'session_id' => AcademicSession::first()->id, 'name' => 'First Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonth()]);
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/fee-rules", ['boarding_category' => 'full', 'charge_type' => 'boarding', 'title' => 'Boarding fee', 'amount' => 150000])->assertCreated();

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/bill", ['term_id' => $term->id])->assertJsonPath('awaiting_invoice', 1);

        $invoice = Invoice::create(['school_id' => $this->school->id, 'term_id' => $term->id, 'student_id' => $this->ada->id, 'invoice_number' => 'INV-1', 'total_amount' => 200000, 'amount_paid' => 0, 'status' => 'unpaid']);

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/bill", ['term_id' => $term->id])->assertJsonPath('posted', 1);
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/bill", ['term_id' => $term->id])->assertJsonPath('posted', 0);

        $this->assertSame(1, InvoiceItem::where('invoice_id', $invoice->id)->count());
        $this->assertSame(350000.0, (float) $invoice->fresh()->total_amount);
        $this->assertSame(0, DB::table('payments')->count(), 'Hostel never records payments.');
    }

    public function test_maintenance_takes_a_bed_out_only_when_told_and_verification_returns_it(): void
    {
        $id = $this->actingAs($this->warden, 'sanctum')->postJson("{$this->host}/hostel/maintenance", ['hostel_id' => $this->unity->id, 'bed_id' => $this->bed('B2')->id, 'category' => 'furniture', 'description' => 'Bed slats broken'])->assertCreated()->json('request.id');
        $this->assertSame('available', $this->bed('B2')->status, 'A report does not take the bed out by itself.');

        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/hostel/maintenance/{$id}", ['take_out_of_service' => true, 'status' => 'in_progress'])->assertOk();
        $this->assertSame('out_of_service', $this->bed('B2')->status);

        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/hostel/maintenance/{$id}", ['status' => 'completed'])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/maintenance/{$id}/verify")->assertOk();
        $this->assertSame('available', $this->bed('B2')->status);
    }

    public function test_a_temporary_checkout_keeps_the_bed_and_ending_the_stay_frees_it(): void
    {
        $this->board($this->ada);

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/check-out", ['student_id' => $this->ada->id, 'ends_stay' => false])->assertOk();
        $this->assertTrue(HostelAllocation::where('student_id', $this->ada->id)->where('status', 'active')->exists());

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/hostel/check-out", ['student_id' => $this->ada->id, 'ends_stay' => true, 'collected_by' => 'Mrs Obi'])->assertOk();
        $this->assertFalse(HostelAllocation::where('student_id', $this->ada->id)->where('status', 'active')->exists());
        $this->assertSame(1, HostelAllocation::where('student_id', $this->ada->id)->count(), 'History kept.');
    }
}
