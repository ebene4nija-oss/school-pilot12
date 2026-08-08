<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\School;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §7.3 leave and employment history — the half of Staff/HR that had columns
 * but no code.
 */
class StaffHrTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $teacherUser;
    private Staff $teacherStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Staff Academy',
            'slug' => 'staffacademy',
            'subdomain' => 'staffacademy',
            'domain' => 'staffacademy.schoolpilot.test',
        ]);

        $this->admin = $this->user('Head', 'head@staff.test', 'school_admin');
        $this->teacherUser = $this->user('Teacher', 'teacher@staff.test', 'teacher');

        $this->teacherStaff = Staff::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacherUser->id,
            'staff_id' => 'STF-001',
            'designation' => 'Mathematics Teacher',
            'employment_date' => now()->subYears(2),
            'leave_allocations' => ['annual' => 20, 'sick' => 10],
        ]);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function url(string $path): string
    {
        return 'http://staffacademy.schoolpilot.test/api/v1' . $path;
    }

    public function test_staff_can_request_leave_and_days_are_computed()
    {
        $this->actingAs($this->teacherUser, 'sanctum')
            ->postJson($this->url('/hr/leave'), [
                'leave_type' => 'annual',
                'start_date' => now()->addWeek()->toDateString(),
                'end_date' => now()->addWeek()->addDays(4)->toDateString(),
                'reason' => 'Family visit',
            ])
            ->assertStatus(201)
            // Inclusive of both ends — five working days, not four.
            ->assertJsonPath('leave_request.days_requested', 5)
            ->assertJsonPath('entitlement.days_allowed', 20);
    }

    public function test_overlapping_request_is_refused()
    {
        $payload = [
            'leave_type' => 'annual',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(4)->toDateString(),
        ];

        $this->actingAs($this->teacherUser, 'sanctum')
            ->postJson($this->url('/hr/leave'), $payload)->assertStatus(201);

        $this->actingAs($this->teacherUser, 'sanctum')
            ->postJson($this->url('/hr/leave'), $payload)->assertStatus(409);

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_admin_can_approve_and_balance_reflects_it()
    {
        $id = $this->actingAs($this->teacherUser, 'sanctum')
            ->postJson($this->url('/hr/leave'), [
                'leave_type' => 'annual',
                'start_date' => now()->addWeek()->toDateString(),
                'end_date' => now()->addWeek()->addDays(4)->toDateString(),
            ])->json('leave_request.id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url("/hr/leave/{$id}/decision"), ['decision' => 'approved'])
            ->assertStatus(200)
            ->assertJsonPath('leave_request.status', 'approved');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url("/hr/staff/{$this->teacherStaff->id}/employment-record"))
            ->assertStatus(200)
            ->assertJsonPath('leave_balances.annual.days_taken', 5)
            ->assertJsonPath('leave_balances.annual.days_remaining', 15);
    }

    /** A settled request must not be silently re-decided. */
    public function test_an_already_decided_request_cannot_be_decided_again()
    {
        $id = $this->actingAs($this->teacherUser, 'sanctum')
            ->postJson($this->url('/hr/leave'), [
                'leave_type' => 'sick',
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(2)->toDateString(),
            ])->json('leave_request.id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url("/hr/leave/{$id}/decision"), ['decision' => 'approved']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url("/hr/leave/{$id}/decision"), ['decision' => 'rejected'])
            ->assertStatus(409);

        $this->assertSame('approved', LeaveRequest::find($id)->status);
    }

    public function test_a_teacher_cannot_approve_their_own_leave()
    {
        $id = $this->actingAs($this->teacherUser, 'sanctum')
            ->postJson($this->url('/hr/leave'), [
                'leave_type' => 'annual',
                'start_date' => now()->addWeek()->toDateString(),
                'end_date' => now()->addWeek()->toDateString(),
            ])->json('leave_request.id');

        $this->actingAs($this->teacherUser, 'sanctum')
            ->postJson($this->url("/hr/leave/{$id}/decision"), ['decision' => 'approved'])
            ->assertStatus(403);
    }

    public function test_staff_only_sees_their_own_requests()
    {
        $otherUser = $this->user('Other Teacher', 'other@staff.test', 'teacher');
        $otherStaff = Staff::create([
            'school_id' => $this->school->id,
            'user_id' => $otherUser->id,
            'staff_id' => 'STF-002',
        ]);

        LeaveRequest::create([
            'school_id' => $this->school->id,
            'staff_id' => $otherStaff->id,
            'leave_type' => 'annual',
            'start_date' => now()->addWeek(),
            'end_date' => now()->addWeek()->addDay(),
            'days_requested' => 2,
            'status' => 'pending',
        ]);

        $this->actingAs($this->teacherUser, 'sanctum')
            ->getJson($this->url('/hr/leave'))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // The admin sees the whole school.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/hr/leave'))
            ->assertJsonCount(1, 'data');
    }

    public function test_leave_calendar_shows_who_is_off_today()
    {
        LeaveRequest::create([
            'school_id' => $this->school->id,
            'staff_id' => $this->teacherStaff->id,
            'leave_type' => 'sick',
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'days_requested' => 3,
            'status' => 'approved',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/hr/leave-calendar'))
            ->assertStatus(200)
            ->assertJsonPath('on_leave.0.name', 'Teacher')
            ->assertJsonPath('on_leave.0.leave_type', 'sick');
    }

    public function test_admin_can_set_allocations_and_add_employment_history()
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->url("/hr/staff/{$this->teacherStaff->id}/leave-allocation"), [
                'allocations' => ['annual' => 25, 'study' => 5],
            ])
            ->assertStatus(200)
            ->assertJsonPath('leave_allocations.annual', 25);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url("/hr/staff/{$this->teacherStaff->id}/employment-history"), [
                'employer' => 'Sunrise College',
                'role' => 'Mathematics Teacher',
                'start_year' => 2018,
                'end_year' => 2023,
            ])
            ->assertStatus(201)
            ->assertJsonPath('employment_history.0.employer', 'Sunrise College');
    }

    public function test_unknown_leave_type_in_allocation_is_rejected()
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->url("/hr/staff/{$this->teacherStaff->id}/leave-allocation"), [
                'allocations' => ['sabbatical_on_the_moon' => 300],
            ])
            ->assertStatus(422);
    }

    public function test_staff_cannot_read_a_colleagues_employment_record()
    {
        $otherUser = $this->user('Colleague', 'colleague@staff.test', 'teacher');
        $otherStaff = Staff::create([
            'school_id' => $this->school->id,
            'user_id' => $otherUser->id,
            'staff_id' => 'STF-003',
        ]);

        $this->actingAs($this->teacherUser, 'sanctum')
            ->getJson($this->url("/hr/staff/{$otherStaff->id}/employment-record"))
            ->assertStatus(403);
    }
}
