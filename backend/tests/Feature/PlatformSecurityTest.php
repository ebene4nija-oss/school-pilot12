<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operator console's security and support screens: the cross-school audit
 * log, every administrator's 2FA and session state, and internal notes on a
 * school.
 *
 * The line these hold: all of it is super-admin only, and the audit log does
 * not hand an operator a child's personal data just because it was in a
 * school-side audit row.
 */
class PlatformSecurityTest extends TestCase
{
    use RefreshDatabase;

    private School $greenfield;

    private School $graceland;

    private User $superAdmin;

    private User $greenfieldAdmin;

    private User $gracelandAdmin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->greenfield = School::create([
            'name' => 'Greenfield Academy',
            'slug' => 'greenfield',
            'subdomain' => 'greenfield',
            'email' => 'head@greenfield.test',
            'status' => 'active',
        ]);

        $this->graceland = School::create([
            'name' => 'Graceland International',
            'slug' => 'graceland',
            'subdomain' => 'graceland',
            'email' => 'head@graceland.test',
            'status' => 'trial',
        ]);

        $this->superAdmin = $this->makeUser('Platform Admin', 'ops@schoolpilot.test', null, 'super_admin', true);
        $this->greenfieldAdmin = $this->makeUser('Greenfield Admin', 'admin@greenfield.test', $this->greenfield, 'school_admin', true);
        $this->gracelandAdmin = $this->makeUser('Graceland Admin', 'admin@graceland.test', $this->graceland, 'school_admin', false);
        $this->teacher = $this->makeUser('Greenfield Teacher', 'teacher@greenfield.test', $this->greenfield, 'teacher', false);
    }

    private function makeUser(string $name, string $email, ?School $school, string $role, bool $twoFactor): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
        ]);

        $user->userProfile()->create([
            'school_id' => $school?->id,
            'role' => $role,
            'two_factor_enabled' => $twoFactor,
        ]);

        return $user;
    }

    private function audit(?School $school, string $action, array $old, array $new): AuditLog
    {
        return AuditLog::create([
            'school_id' => $school?->id,
            'user_id' => $this->superAdmin->id,
            'action' => $action,
            'auditable_type' => School::class,
            'auditable_id' => $school?->id ?? 0,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => '10.0.0.1',
        ]);
    }

    // --- Audit log -------------------------------------------------------

    public function test_audit_log_spans_every_school(): void
    {
        $this->audit($this->greenfield, 'platform.school.status_changed', ['status' => 'active'], ['status' => 'suspended']);
        $this->audit($this->graceland, 'platform.school.ai_toggled', ['ai_enabled' => true], ['ai_enabled' => false]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/audit-log')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.action', 'platform.school.ai_toggled')
            ->assertJsonPath('data.0.school.name', 'Graceland International')
            ->assertJsonPath('data.0.actor.email', 'ops@schoolpilot.test')
            ->assertJsonPath('data.1.new_values.status', 'suspended');
    }

    public function test_audit_log_filters_by_school_and_scope(): void
    {
        $this->audit($this->greenfield, 'platform.school.status_changed', [], ['status' => 'suspended']);
        $this->audit($this->greenfield, 'student.updated', ['phone' => '0801'], ['phone' => '0802']);
        $this->audit($this->graceland, 'platform.school.ai_toggled', [], ['ai_enabled' => false]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/platform/audit-log?school_id={$this->greenfield->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/audit-log?scope=platform')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/audit-log?scope=school')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'student.updated');
    }

    public function test_school_side_rows_show_which_fields_changed_but_not_the_values(): void
    {
        $this->audit($this->greenfield, 'student.updated', ['guardian_phone' => '08011111111'], ['guardian_phone' => '08022222222']);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/audit-log')
            ->assertOk()
            ->assertJsonPath('data.0.changed_fields', ['guardian_phone'])
            ->assertJsonPath('data.0.old_values', null)
            ->assertJsonPath('data.0.new_values', null);

        $this->assertStringNotContainsString('08022222222', $response->getContent());
    }

    // --- Administrators ---------------------------------------------------

    public function test_admins_lists_every_administrator_with_two_factor_state(): void
    {
        $this->greenfieldAdmin->createToken('web');
        $this->greenfieldAdmin->createToken('mobile');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/security/admins')
            ->assertOk()
            // Two school admins and the operator; never the teacher.
            ->assertJsonPath('summary.admins', 3)
            ->assertJsonPath('summary.with_two_factor', 2)
            ->assertJsonPath('summary.without_two_factor', 1)
            ->assertJsonPath('meta.total', 3);

        $rows = collect($response->json('data'))->keyBy('email');

        $this->assertArrayNotHasKey('teacher@greenfield.test', $rows->all());
        $this->assertSame(2, $rows['admin@greenfield.test']['active_sessions']);
        $this->assertSame('Greenfield Academy', $rows['admin@greenfield.test']['school']['name']);
        $this->assertFalse($rows['admin@graceland.test']['two_factor_enabled']);
        $this->assertNull($rows['ops@schoolpilot.test']['school']);
    }

    public function test_admins_can_be_filtered_to_those_without_two_factor(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/security/admins?two_factor=off')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'admin@graceland.test');
    }

    public function test_operator_can_sign_an_administrator_out_everywhere(): void
    {
        $this->gracelandAdmin->createToken('web');
        $this->gracelandAdmin->createToken('mobile');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/security/admins/{$this->gracelandAdmin->id}/revoke-sessions")
            ->assertOk()
            ->assertJsonPath('sessions_revoked', 2);

        $this->assertSame(0, $this->gracelandAdmin->tokens()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'platform.user.sessions_revoked',
            'auditable_id' => $this->gracelandAdmin->id,
            'school_id' => $this->graceland->id,
        ]);
    }

    public function test_revoke_refuses_non_administrators_and_the_operators_own_account(): void
    {
        $this->teacher->createToken('web');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/security/admins/{$this->teacher->id}/revoke-sessions")
            ->assertNotFound();

        $this->assertSame(1, $this->teacher->tokens()->count());

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/security/admins/{$this->superAdmin->id}/revoke-sessions")
            ->assertStatus(422);
    }

    // --- Support notes ----------------------------------------------------

    public function test_operator_can_keep_notes_on_a_school(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->greenfield->id}/notes", [
                'body' => 'Proprietor called about term 2 PINs; promised a callback Monday.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.author', 'Platform Admin');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/platform/schools/{$this->greenfield->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Proprietor called about term 2 PINs; promised a callback Monday.');

        // Notes belong to the school they were written about.
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/platform/schools/{$this->graceland->id}/notes")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_notes_reject_an_empty_body_and_an_unknown_school(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->greenfield->id}/notes", ['body' => ''])
            ->assertStatus(422);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/schools/999999/notes')
            ->assertNotFound();
    }

    // --- Access -----------------------------------------------------------

    public function test_a_school_admin_cannot_reach_any_of_it(): void
    {
        $this->actingAs($this->greenfieldAdmin, 'sanctum');

        $this->getJson('/api/v1/platform/audit-log')->assertForbidden();
        $this->getJson('/api/v1/platform/security/admins')->assertForbidden();
        $this->postJson("/api/v1/platform/security/admins/{$this->gracelandAdmin->id}/revoke-sessions")->assertForbidden();
        $this->getJson("/api/v1/platform/schools/{$this->greenfield->id}/notes")->assertForbidden();
        $this->postJson("/api/v1/platform/schools/{$this->greenfield->id}/notes", ['body' => 'x'])->assertForbidden();
    }
}
