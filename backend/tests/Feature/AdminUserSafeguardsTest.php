<?php

namespace Tests\Feature;

use App\Models\CustomRole;
use App\Models\School;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * A school admin manages their own school's accounts — and cannot mint a
 * platform operator, or borrow another school's roles, while doing it.
 */
class AdminUserSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $this->admin = User::create(['name' => 'Mrs Admin', 'email' => 'admin@hilltop.test', 'password' => bcrypt('password123')]);
        $this->admin->userProfile()->create(['school_id' => $this->school->id, 'role' => 'school_admin']);
    }

    public function test_a_bulk_import_cannot_create_a_platform_operator(): void
    {
        $csv = "name,email,role,phone\nMr Teacher,teacher@hilltop.test,teacher,0803\nSneaky,sneaky@hilltop.test,super_admin,0804\nBad Email,not-an-email,teacher,0805\n";
        $file = UploadedFile::fake()->createWithContent('users.csv', $csv);

        $res = $this->actingAs($this->admin, 'sanctum')->post("{$this->host}/users/bulk-import", ['file' => $file], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(1, $res->json('imported_count'));
        $this->assertCount(2, $res->json('errors'));
        $this->assertFalse(User::where('email', 'sneaky@hilltop.test')->exists());
        $this->assertSame(0, UserProfile::where('role', 'super_admin')->count());
        $this->assertSame('teacher', User::where('email', 'teacher@hilltop.test')->first()->userProfile->role);
    }

    public function test_only_this_schools_custom_roles_can_be_assigned(): void
    {
        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other', 'domain' => 'other.schoolpilot.test']);
        $foreign = CustomRole::create(['school_id' => $other->id, 'name' => 'Their role', 'slug' => 'theirs', 'permissions' => ['finance.view']]);
        $mine = CustomRole::create(['school_id' => $this->school->id, 'name' => 'Exams officer', 'slug' => 'exams', 'permissions' => ['results.view']]);
        $teacher = User::create(['name' => 'Mr T', 'email' => 't@hilltop.test', 'password' => bcrypt('password123')]);
        $teacher->userProfile()->create(['school_id' => $this->school->id, 'role' => 'teacher']);

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/users/{$teacher->id}/roles", ['custom_role_id' => $foreign->id])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/users/{$teacher->id}/roles", ['custom_role_id' => $mine->id])->assertOk();
    }

    public function test_a_custom_role_is_a_label_and_needs_no_permissions(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/roles", ['name' => 'Exams officer', 'slug' => 'exams-officer', 'permissions' => []])
            ->assertCreated()->assertJsonPath('data.name', 'Exams officer');
    }

    public function test_the_admin_keeps_the_school_calendar(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/calendar/events", ['title' => 'Mid-term break', 'category' => 'holiday', 'start_date' => '2026-10-26', 'end_date' => '2026-10-30', 'is_public' => true])
            ->assertSuccessful()->json('event.id') ?? \App\Models\SchoolCalendarEvent::first()->id;

        $this->assertSame('Mid-term break', $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/calendar/events")->assertOk()->json('data.0.title'));
        $this->actingAs($this->admin, 'sanctum')->deleteJson("{$this->host}/calendar/events/{$id}")->assertOk();
        $this->assertSame(0, \App\Models\SchoolCalendarEvent::count());
    }
}
