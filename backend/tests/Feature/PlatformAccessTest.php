<?php

namespace Tests\Feature;

use App\Models\PlatformAnnouncement;
use App\Models\PlatformSchoolAccess;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operator access to a school, trial end dates, and announcements to school
 * administrators.
 *
 * The line the access tests hold: an operator reads a school's records only
 * through a live grant that says who, which school and why. Before this, the
 * console's "Open" was a cookie, and an operator with no school open could
 * list every child on the platform from `/students`.
 */
class PlatformAccessTest extends TestCase
{
    use RefreshDatabase;

    private School $greenfield;

    private School $graceland;

    private User $operator;

    private User $otherOperator;

    private User $greenfieldAdmin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->greenfield = School::create([
            'name' => 'Greenfield Academy', 'slug' => 'greenfield',
            'subdomain' => 'greenfield', 'email' => 'head@greenfield.test', 'status' => 'active',
        ]);
        $this->graceland = School::create([
            'name' => 'Graceland International', 'slug' => 'graceland',
            'subdomain' => 'graceland', 'email' => 'head@graceland.test', 'status' => 'trial',
        ]);

        $this->operator = $this->makeUser('Platform Admin', 'ops@schoolpilot.test', null, 'super_admin');
        $this->otherOperator = $this->makeUser('Second Operator', 'ops2@schoolpilot.test', null, 'super_admin');
        $this->greenfieldAdmin = $this->makeUser('Greenfield Admin', 'admin@greenfield.test', $this->greenfield, 'school_admin');
        $this->teacher = $this->makeUser('Greenfield Teacher', 'teacher@greenfield.test', $this->greenfield, 'teacher');

        $this->enrol($this->greenfield, 3);
        $this->enrol($this->graceland, 2);
    }

    private function makeUser(string $name, string $email, ?School $school, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $school?->id, 'role' => $role]);

        return $user;
    }

    private function enrol(School $school, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $user = User::create([
                'name' => "Pupil {$school->subdomain} {$i}",
                'email' => "pupil{$i}@{$school->subdomain}.test",
                'password' => bcrypt('password'),
            ]);

            Student::create([
                'school_id' => $school->id,
                'user_id' => $user->id,
                'admission_number' => strtoupper($school->subdomain)."-{$i}",
                'status' => 'active',
            ]);
        }
    }

    private function open(School $school, ?User $as = null, string $reason = 'Proprietor called about missing results'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->operator, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$school->id}/access", ['reason' => $reason]);
    }

    private function header(School $school): array
    {
        return ['X-Platform-School' => (string) $school->id];
    }

    // --- Opening a school -------------------------------------------------

    public function test_opening_a_school_records_the_reason_and_audits_it(): void
    {
        $this->open($this->greenfield)
            ->assertCreated()
            ->assertJsonPath('access.school.name', 'Greenfield Academy')
            ->assertJsonPath('access.reason', 'Proprietor called about missing results')
            ->assertJsonPath('access.is_live', true);

        $this->assertDatabaseHas('platform_school_access', [
            'user_id' => $this->operator->id,
            'school_id' => $this->greenfield->id,
            'closed_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'platform.school.opened',
            'school_id' => $this->greenfield->id,
            'user_id' => $this->operator->id,
        ]);
    }

    public function test_a_reason_is_required(): void
    {
        $this->open($this->greenfield, null, '')->assertStatus(422);
        $this->open($this->greenfield, null, 'check')->assertStatus(422);

        $this->assertDatabaseCount('platform_school_access', 0);
    }

    public function test_the_platform_row_and_unknown_schools_cannot_be_opened(): void
    {
        $hq = School::create([
            'name' => 'SchoolPilot HQ', 'slug' => 'admin', 'subdomain' => 'admin',
            'status' => 'active', 'is_platform' => true,
        ]);

        $this->open($hq)->assertNotFound();
        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/platform/schools/999999/access', ['reason' => 'Looking into a ticket'])
            ->assertNotFound();
    }

    public function test_an_open_school_answers_for_that_school_only(): void
    {
        $this->open($this->greenfield)->assertCreated();

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/students', $this->header($this->greenfield))
            ->assertOk()
            ->assertJsonCount(3, 'data');

        // A grant for Greenfield is not a grant for Graceland.
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/students', $this->header($this->graceland))
            ->assertForbidden()
            ->assertJsonPath('code', 'school_access_required');
    }

    public function test_one_operators_grant_does_not_cover_another_operator(): void
    {
        $this->open($this->greenfield)->assertCreated();

        $this->actingAs($this->otherOperator, 'sanctum')
            ->getJson('/api/v1/students', $this->header($this->greenfield))
            ->assertForbidden();
    }

    public function test_an_expired_grant_is_refused_and_the_client_is_told_there_is_no_acting_school(): void
    {
        $this->open($this->greenfield)->assertCreated();
        $this->travel(3)->hours();

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/students', $this->header($this->greenfield))
            ->assertForbidden()
            ->assertJsonPath('code', 'school_access_required');

        // The chrome reads this: the banner and the school's menu go away.
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/user', $this->header($this->greenfield))
            ->assertOk()
            ->assertJsonPath('school', null)
            ->assertJsonPath('platform_access', null);
    }

    public function test_user_reports_when_the_grant_ends(): void
    {
        $this->open($this->greenfield)->assertCreated();

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/user', $this->header($this->greenfield))
            ->assertOk()
            ->assertJsonPath('school.name', 'Greenfield Academy')
            ->assertJsonPath('platform_access.reason', 'Proprietor called about missing results');
    }

    public function test_opening_another_school_ends_the_first_grant(): void
    {
        $this->open($this->greenfield)->assertCreated();
        $this->open($this->graceland)->assertCreated();

        $this->assertSame(1, PlatformSchoolAccess::query()->live()->where('user_id', $this->operator->id)->count());

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/students', $this->header($this->greenfield))
            ->assertForbidden();
    }

    public function test_leaving_closes_the_grant_and_audits_it(): void
    {
        $this->open($this->greenfield)->assertCreated();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/platform/access/close')
            ->assertOk()
            ->assertJsonPath('closed', 1);

        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.school.closed', 'school_id' => $this->greenfield->id]);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/students', $this->header($this->greenfield))
            ->assertForbidden();

        // Nothing open is not an error.
        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/platform/access/close')
            ->assertOk()
            ->assertJsonPath('closed', 0);
    }

    public function test_an_operator_with_no_school_open_cannot_read_school_records_across_tenants(): void
    {
        // This returned all five children, from both schools, before.
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/students')
            ->assertForbidden()
            ->assertJsonPath('code', 'school_access_required');
    }

    public function test_the_console_and_account_routes_still_answer_with_no_school_open(): void
    {
        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/overview')->assertOk();
        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/user')->assertOk();
        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/auth/2fa')->assertOk();

        // Even with a stale header from a school that has since closed.
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/platform/schools', $this->header($this->greenfield))
            ->assertOk();
    }

    public function test_the_access_log_lists_who_opened_which_school_and_why(): void
    {
        $this->open($this->greenfield)->assertCreated();
        $this->open($this->graceland, $this->otherOperator, 'Migration follow-up for Graceland')->assertCreated();

        $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/platform/access-log?school_id={$this->graceland->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.operator.email', 'ops2@schoolpilot.test')
            ->assertJsonPath('data.0.reason', 'Migration follow-up for Graceland');
    }

    public function test_school_users_are_unaffected_and_cannot_open_schools(): void
    {
        $this->actingAs($this->greenfieldAdmin, 'sanctum')
            ->getJson('/api/v1/students')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->open($this->graceland, $this->greenfieldAdmin)->assertForbidden();
    }

    // --- Trials -----------------------------------------------------------

    public function test_a_new_trial_school_gets_an_end_date(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/platform/schools', [
                'name' => 'Sunrise Academy',
                'subdomain' => 'sunrise',
                'email' => 'head@sunrise.test',
                'admin_name' => 'Sunrise Admin',
                'admin_email' => 'admin@sunrise.test',
            ])
            ->assertCreated()
            ->assertJsonPath('school.trial_ends_at', now()->addDays(30)->toDateString());
    }

    public function test_a_trial_can_be_extended_and_is_audited(): void
    {
        $date = now()->addDays(45)->toDateString();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->graceland->id}/trial", ['trial_ends_at' => $date])
            ->assertOk()
            ->assertJsonPath('school.trial_ends_at', $date);

        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.school.trial_changed', 'school_id' => $this->graceland->id]);
    }

    public function test_trial_dates_are_refused_for_paying_schools_and_the_past(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->greenfield->id}/trial", ['trial_ends_at' => now()->addDays(10)->toDateString()])
            ->assertStatus(422);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->graceland->id}/trial", ['trial_ends_at' => now()->subDay()->toDateString()])
            ->assertStatus(422);
    }

    public function test_converting_a_trial_to_active_clears_its_end_date(): void
    {
        $this->graceland->update(['trial_ends_at' => now()->addDays(5)->toDateString()]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->graceland->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('school.trial_ends_at', null);
    }

    public function test_the_overview_flags_trials_ending_soon_and_ended(): void
    {
        $this->graceland->update(['trial_ends_at' => now()->addDays(3)->toDateString()]);
        School::create([
            'name' => 'Lapsed College', 'slug' => 'lapsed', 'subdomain' => 'lapsed',
            'status' => 'trial', 'trial_ends_at' => now()->subDays(2)->toDateString(),
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/platform/overview')
            ->assertOk()
            ->assertJsonPath('trials.ending_within_7_days', 1)
            ->assertJsonPath('trials.ended', 1);
    }

    // --- Announcements ----------------------------------------------------

    private function publish(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/platform/announcements', $overrides + [
                'title' => 'Maintenance on Saturday',
                'body' => 'SchoolPilot will be unavailable from 10pm to midnight.',
                'shows_until' => now()->addDays(3)->toIso8601String(),
            ]);
    }

    public function test_an_announcement_to_every_school_reaches_a_school_admin(): void
    {
        $this->publish()->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.announcement.published']);

        $this->actingAs($this->greenfieldAdmin, 'sanctum')
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Maintenance on Saturday');
    }

    public function test_an_announcement_to_one_school_is_not_shown_to_another(): void
    {
        $this->publish(['school_id' => $this->graceland->id, 'title' => 'For Graceland only'])->assertCreated();

        $this->actingAs($this->greenfieldAdmin, 'sanctum')
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_withdrawn_and_expired_announcements_are_not_shown(): void
    {
        $id = $this->publish()->json('data.id');
        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/platform/announcements/{$id}/withdraw")
            ->assertOk();

        PlatformAnnouncement::create([
            'title' => 'Old news', 'body' => 'Past', 'tone' => 'info',
            'shows_until' => now()->subDay(),
        ]);

        $this->actingAs($this->greenfieldAdmin, 'sanctum')
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_announcements_are_for_administrators_and_published_only_by_operators(): void
    {
        $this->publish()->assertCreated();

        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/v1/announcements')->assertForbidden();

        $this->actingAs($this->greenfieldAdmin, 'sanctum')
            ->postJson('/api/v1/platform/announcements', [
                'title' => 'x', 'body' => 'y', 'shows_until' => now()->addDay()->toIso8601String(),
            ])
            ->assertForbidden();
    }
}
