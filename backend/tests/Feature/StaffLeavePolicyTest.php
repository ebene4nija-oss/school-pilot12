<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bursars and nurses take leave too, on the terms the school sets in
 * Settings > Staff leave: who requests it here, default days per type, and
 * whether going over the allowance is refused.
 */
class StaffLeavePolicyTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $bursar;
    private User $nurse;
    private string $host = 'http://staffacademy.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Staff Academy', 'slug' => 'staffacademy', 'subdomain' => 'staffacademy', 'domain' => 'staffacademy.schoolpilot.test']);
        $this->admin = $this->staff('Head', 'school_admin');
        $this->bursar = $this->staff('Mrs Bello', 'bursar');
        $this->nurse = $this->staff('Nurse Ada', 'nurse');
    }

    private function staff(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@staff.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);
        Staff::create(['school_id' => $this->school->id, 'user_id' => $u->id]);

        return $u;
    }

    private function request(User $u, string $type, string $from, string $to)
    {
        return $this->actingAs($u, 'sanctum')->postJson("{$this->host}/hr/leave", ['leave_type' => $type, 'start_date' => $from, 'end_date' => $to]);
    }

    public function test_bursars_and_nurses_request_and_see_their_own_leave(): void
    {
        $this->request($this->bursar, 'annual', '2026-11-02', '2026-11-06')->assertCreated();
        $this->request($this->nurse, 'sick', '2026-10-12', '2026-10-12')->assertCreated();

        $mine = $this->actingAs($this->nurse, 'sanctum')->getJson("{$this->host}/hr/leave")->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame('sick', $mine[0]['leave_type']);

        // Still not an approver.
        $this->actingAs($this->bursar, 'sanctum')->postJson("{$this->host}/hr/leave/{$mine[0]['id']}/decision", ['decision' => 'approved'])->assertForbidden();
    }

    public function test_the_school_decides_which_roles_request_leave_here(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/hr/leave-settings", ['leave_roles' => ['teacher', 'bursar']])->assertOk();

        $this->request($this->nurse, 'annual', '2026-11-02', '2026-11-03')
            ->assertForbidden()
            ->assertJsonPath('error', 'Your school does not take leave requests from Nurses in SchoolPilot. Ask the school office.');
        $this->request($this->bursar, 'annual', '2026-11-02', '2026-11-03')->assertCreated();
    }

    public function test_default_days_apply_and_are_enforced_only_when_the_school_says_so(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/hr/leave-settings", ['days_annual' => 3])
            ->assertOk()->assertJsonPath('days_annual', 3)->assertJsonPath('enforce_allowance', false);

        // Over the default, but not enforced: the request goes to the approver
        // with the figures.
        $this->request($this->bursar, 'annual', '2026-11-02', '2026-11-06')
            ->assertCreated()
            ->assertJsonPath('entitlement.days_allowed', 3);

        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/hr/leave-settings", ['enforce_allowance' => true])->assertOk();
        $this->request($this->nurse, 'annual', '2026-11-09', '2026-11-13')
            ->assertStatus(422)
            ->assertJsonPath('error', 'That is more than your annual leave allows: 3 day(s) left this year.');
        $this->request($this->nurse, 'annual', '2026-11-09', '2026-11-11')->assertCreated();
    }

    public function test_only_the_admin_changes_the_policy(): void
    {
        $this->actingAs($this->bursar, 'sanctum')->putJson("{$this->host}/hr/leave-settings", ['enforce_allowance' => true])->assertForbidden();
        $this->actingAs($this->bursar, 'sanctum')->getJson("{$this->host}/hr/leave-settings")->assertOk()->assertJsonPath('enforce_allowance', false);
        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/hr/leave-settings", ['leave_roles' => ['librarian']])->assertStatus(422);
    }
}
