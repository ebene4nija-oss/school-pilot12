<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolMigrationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Assisted data migration.
 *
 * The portal advertised a staffed 24-hour migration behind a form whose submit
 * handler resolved a `setTimeout` and told the school "Successfully queued".
 * Nothing was recorded and nobody was notified, so a proprietor who accepted the
 * offer believed it was in hand while it had reached no one.
 */
class SchoolMigrationRequestTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Greenfield Academy',
            'slug' => 'greenfield',
            'subdomain' => 'greenfield',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'name' => 'Greenfield Admin',
            'email' => 'admin@greenfield.test',
            'password' => bcrypt('password'),
        ]);
        $this->admin->userProfile()->create([
            'school_id' => $this->school->id,
            'role' => 'school_admin',
        ]);

        $this->superAdmin = User::create([
            'name' => 'Platform Admin',
            'email' => 'ops@schoolpilot.test',
            'password' => bcrypt('password'),
        ]);
        $this->superAdmin->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'previous_system' => 'Legacy Excel workbooks',
            'source_format' => 'excel',
            'notes' => 'About 900 students across three sessions.',
        ], $overrides);
    }

    public function test_a_school_can_request_migration_and_it_is_recorded(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.previous_system', 'Legacy Excel workbooks')
            ->assertJsonPath('data.status', 'submitted');

        // The whole point: it exists afterwards.
        $this->assertDatabaseHas('school_migration_requests', [
            'school_id' => $this->school->id,
            'requested_by' => $this->admin->id,
            'status' => 'submitted',
        ]);
    }

    /** The promised date is recorded, not merely claimed in the interface. */
    public function test_a_request_carries_its_promised_completion_date(): void
    {
        $due = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload())
            ->assertCreated()
            ->json('data.due_at');

        $this->assertNotNull($due);
        $this->assertTrue(
            now()->addHours(23)->lt($due) && now()->addHours(25)->gt($due),
            'The due date should be about 24 hours out.',
        );
    }

    public function test_a_school_sees_its_own_request_back(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload())
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/school-migration')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'submitted')
            ->assertJsonPath('turnaround_hours', 24);
    }

    /**
     * A proprietor who sees no response clicks again; three duplicate jobs for
     * one school is how an operator loses track of which upload is real.
     */
    public function test_a_second_open_request_is_refused(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload())
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('data.status', 'submitted');

        $this->assertSame(1, SchoolMigrationRequest::withoutGlobalScope('school_id')->count());
    }

    public function test_a_new_request_is_allowed_once_the_last_one_closed(): void
    {
        $first = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/school-migration/{$first}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload())
            ->assertCreated();
    }

    public function test_a_closed_request_cannot_be_cancelled_again(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/school-migration/{$id}/cancel")
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/school-migration/{$id}/cancel")
            ->assertStatus(422);
    }

    public function test_source_format_is_validated(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload(['source_format' => 'telepathy']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_format');
    }

    public function test_the_operator_queue_spans_schools_and_leads_with_the_oldest_promise(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload(['previous_system' => 'Greenfield legacy']))
            ->assertCreated();

        $rival = School::create([
            'name' => 'Rival Academy', 'slug' => 'rival', 'subdomain' => 'rival', 'status' => 'active',
        ]);
        $rivalAdmin = User::create([
            'name' => 'Rival Admin', 'email' => 'admin@rival.test', 'password' => bcrypt('password'),
        ]);
        $rivalAdmin->userProfile()->create(['school_id' => $rival->id, 'role' => 'school_admin']);

        $this->actingAs($rivalAdmin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload(['previous_system' => 'Rival legacy']))
            ->assertCreated();

        // Make Greenfield's the overdue one, so it must come first.
        SchoolMigrationRequest::withoutGlobalScope('school_id')
            ->where('school_id', $this->school->id)
            ->update(['due_at' => now()->subDay()]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/school-migrations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.previous_system', 'Greenfield legacy')
            ->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('data.0.school.subdomain', 'greenfield');
    }

    public function test_an_operator_can_work_a_request_and_the_school_sees_the_update(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/platform/school-migrations/{$id}", [
                'status' => 'in_progress',
                'operator_notes' => 'Received the workbook; rebuilding the 2024/2025 roll first.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/school-migration')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'in_progress')
            ->assertJsonPath(
                'data.0.operator_notes',
                'Received the workbook; rebuilding the 2024/2025 roll first.',
            );
    }

    public function test_completing_a_request_stamps_when(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/platform/school-migrations/{$id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.is_overdue', false);

        $this->assertNotNull(
            SchoolMigrationRequest::withoutGlobalScope('school_id')->find($id)->completed_at,
        );
    }

    public function test_a_school_cannot_see_another_schools_request(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload())
            ->assertCreated();

        $rival = School::create([
            'name' => 'Rival Academy', 'slug' => 'rival', 'subdomain' => 'rival', 'status' => 'active',
        ]);
        $rivalAdmin = User::create([
            'name' => 'Rival Admin', 'email' => 'admin@rival.test', 'password' => bcrypt('password'),
        ]);
        $rivalAdmin->userProfile()->create(['school_id' => $rival->id, 'role' => 'school_admin']);

        $this->actingAs($rivalAdmin, 'sanctum')
            ->getJson('/api/v1/school-migration')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_school_admin_cannot_read_the_operator_queue(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/platform/school-migrations')
            ->assertForbidden();
    }

    public function test_a_teacher_cannot_request_a_migration(): void
    {
        $teacher = User::create([
            'name' => 'Mr Chidi', 'email' => 'teacher@greenfield.test', 'password' => bcrypt('password'),
        ]);
        $teacher->userProfile()->create(['school_id' => $this->school->id, 'role' => 'teacher']);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/school-migration', $this->payload())
            ->assertForbidden();
    }
}
