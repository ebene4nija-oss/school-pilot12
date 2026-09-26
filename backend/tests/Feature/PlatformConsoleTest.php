<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operator console, and the line between it and a school's own dashboard.
 *
 * The bug these cover: a super admin has no `school_id`, so pointing them at
 * the school-side dashboard reported them zeroes for everything. The console
 * has to answer across tenants, and a school admin must not be able to reach it
 * at all.
 */
class PlatformConsoleTest extends TestCase
{
    use RefreshDatabase;

    private School $greenfield;

    private School $graceland;

    private User $superAdmin;

    private User $schoolAdmin;

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

        $this->superAdmin = User::create([
            'name' => 'Platform Admin',
            'email' => 'ops@schoolpilot.test',
            'password' => bcrypt('password'),
        ]);
        // No school: that is what makes them a platform operator.
        $this->superAdmin->userProfile()->create([
            'school_id' => null,
            'role' => 'super_admin',
        ]);

        $this->schoolAdmin = User::create([
            'name' => 'Greenfield Admin',
            'email' => 'admin@greenfield.test',
            'password' => bcrypt('password'),
        ]);
        $this->schoolAdmin->userProfile()->create([
            'school_id' => $this->greenfield->id,
            'role' => 'school_admin',
        ]);

        $this->enrol($this->greenfield, 3);
        $this->enrol($this->graceland, 2);
    }

    private function enrol(School $school, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $user = User::create([
                'name' => "Student {$school->subdomain} {$i}",
                'email' => "student{$i}@{$school->subdomain}.test",
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

    public function test_overview_counts_across_every_tenant(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/overview');

        $response->assertOk()
            ->assertJsonPath('schools.total', 2)
            ->assertJsonPath('schools.active', 1)
            ->assertJsonPath('schools.trial', 1)
            // Five students across two schools. Scoped to the operator's own
            // (null) school this would have been zero.
            ->assertJsonPath('students.active', 5);
    }

    public function test_overview_does_not_label_school_fee_collections_as_platform_revenue(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/overview');

        $response->assertOk()
            ->assertJsonStructure([
                'school_fee_flow' => ['collected', 'invoiced', 'recovery_rate_percentage', 'currency'],
                'ai_spend_this_month' => ['cost_kobo', 'cost_naira', 'calls'],
            ]);
    }

    public function test_school_directory_lists_tenants_with_derived_plan_band(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/schools');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'Graceland International')
            ->assertJsonPath('data.0.active_students', 2)
            ->assertJsonPath('data.0.plan_band', 'Starter (0-200)')
            ->assertJsonPath('data.1.name', 'Greenfield Academy')
            ->assertJsonPath('data.1.active_students', 3);
    }

    public function test_school_directory_can_be_searched(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/schools?search=greenfield')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subdomain', 'greenfield');
    }

    public function test_school_detail_reports_one_tenant(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/platform/schools/{$this->greenfield->id}")
            ->assertOk()
            ->assertJsonPath('school.name', 'Greenfield Academy')
            ->assertJsonPath('students.active', 3);
    }

    public function test_missing_school_is_a_404_not_an_empty_tenant(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/schools/999999')
            ->assertNotFound();
    }

    public function test_operator_can_suspend_and_reinstate_a_tenant(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->greenfield->id}/status", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('school.status', 'suspended');

        // Suspension keeps the records: the roll is untouched.
        $this->assertDatabaseHas('schools', ['id' => $this->greenfield->id, 'status' => 'suspended']);
        $this->assertSame(3, Student::withoutGlobalScope('school_id')
            ->where('school_id', $this->greenfield->id)->count());

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->greenfield->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('school.status', 'active');
    }

    public function test_status_change_is_audited(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->greenfield->id}/status", ['status' => 'suspended'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'platform.school.status_changed',
            'auditable_id' => $this->greenfield->id,
            'user_id' => $this->superAdmin->id,
        ]);
    }

    public function test_school_admin_cannot_reach_the_operator_console(): void
    {
        foreach ([
            '/api/v1/platform/overview',
            '/api/v1/platform/schools',
            "/api/v1/platform/schools/{$this->greenfield->id}",
        ] as $endpoint) {
            $this->actingAs($this->schoolAdmin, 'sanctum')
                ->getJson($endpoint)
                ->assertForbidden();
        }

        $this->actingAs($this->schoolAdmin, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->graceland->id}/status", ['status' => 'suspended'])
            ->assertForbidden();
    }

    /**
     * The school picker: an operator naming one tenant is scoped to it, so the
     * ordinary school-side endpoints answer for that school.
     */
    public function test_operator_can_scope_to_one_school_with_the_platform_header(): void
    {
        foreach ([$this->greenfield, $this->graceland] as $school) {
            \App\Models\PlatformSchoolAccess::create([
                'user_id' => $this->superAdmin->id,
                'school_id' => $school->id,
                'reason' => 'Support request: roster check',
                'expires_at' => now()->addHour(),
            ]);
        }

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/students', ['X-Platform-School' => (string) $this->greenfield->id])
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/students', ['X-Platform-School' => (string) $this->graceland->id])
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_school_admin_cannot_use_the_platform_header_to_read_another_school(): void
    {
        // Greenfield's admin, asking for Graceland. They must still get their
        // own three students, not Graceland's two.
        $this->actingAs($this->schoolAdmin, 'sanctum')
            ->getJson('/api/v1/students', ['X-Platform-School' => (string) $this->graceland->id])
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_unknown_school_in_the_header_is_refused_not_read_across_schools(): void
    {
        // Not "a school with no students", and no longer "every school's
        // students" either: with no live grant for a real school, school-side
        // routes are refused outright.
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/students', ['X-Platform-School' => '999999'])
            ->assertForbidden()
            ->assertJsonPath('code', 'school_access_required');
    }

    // ---------------------------------------------------------------- platform row

    /**
     * SchoolPilot's own row is not a customer.
     *
     * Every login resolves a tenant from the host, the operator's included, so
     * SchoolPilot holds a row in `schools` purely to give itself a subdomain to
     * sign in through. Listing it made the console report a school that has no
     * pupils, pays no fees and belongs to no proprietor.
     */
    private function platformSchool(): School
    {
        return School::create([
            'name' => 'SchoolPilot HQ',
            'slug' => 'admin',
            'subdomain' => 'admin',
            'status' => 'active',
            'is_platform' => true,
        ]);
    }

    public function test_the_platform_row_is_not_listed_as_a_school(): void
    {
        $this->platformSchool();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/schools')
            ->assertOk()
            // Still the two real schools, not three.
            ->assertJsonPath('meta.total', 2);

        $this->assertStringNotContainsString('SchoolPilot HQ', $response->getContent());
    }

    public function test_the_platform_row_is_not_counted_as_a_school(): void
    {
        $this->platformSchool();

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/overview')
            ->assertOk()
            ->assertJsonPath('schools.total', 2)
            ->assertJsonPath('schools.active', 1);
    }

    /**
     * The footgun. Suspending this row blocks sign-in through the subdomain the
     * operator authenticates with, and the button to undo it is inside the
     * console they would no longer be able to reach.
     */
    public function test_the_platform_row_cannot_be_suspended(): void
    {
        $platform = $this->platformSchool();

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$platform->id}/status", ['status' => 'suspended'])
            ->assertStatus(422);

        $this->assertDatabaseHas('schools', ['id' => $platform->id, 'status' => 'active']);
    }

    /** Real schools are still suspendable — the guard is narrow. */
    public function test_a_real_school_can_still_be_suspended(): void
    {
        $this->platformSchool();

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->greenfield->id}/status", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('school.status', 'suspended');
    }

    /** A brand-new platform reports no schools, rather than one. */
    public function test_a_platform_with_no_customers_reports_none(): void
    {
        School::query()->whereKey([$this->greenfield->id, $this->graceland->id])->forceDelete();
        $this->platformSchool();

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/overview')
            ->assertOk()
            ->assertJsonPath('schools.total', 0);
    }

    // ------------------------------------------------------------ provisioning

    private function provision(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sunrise Academy',
            'subdomain' => 'sunrise',
            'email' => 'head@sunrise.test',
            'admin_name' => 'Mrs Adeyemi',
            'admin_email' => 'adeyemi@sunrise.test',
        ], $overrides);
    }

    /**
     * Onboarding, which nothing could do before: there was no endpoint at all,
     * and the only school on the platform had been inserted by hand with SQL.
     */
    public function test_an_operator_can_onboard_a_school(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/platform/schools', $this->provision())
            ->assertCreated()
            ->assertJsonPath('school.subdomain', 'sunrise')
            // Trial unless stated: a school being set up has not agreed a plan.
            ->assertJsonPath('school.status', 'trial');

        $this->assertDatabaseHas('schools', [
            'subdomain' => 'sunrise',
            'is_platform' => false,
        ]);

        // And it shows up as a tenant immediately.
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/platform/schools?search=sunrise')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->assertNotEmpty($response->json('administrator.temporary_password'));
    }

    /**
     * A school with nobody who can sign in is a row, not a tenant.
     */
    public function test_onboarding_creates_the_schools_first_administrator(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/platform/schools', $this->provision())
            ->assertCreated()
            ->assertJsonPath('administrator.email', 'adeyemi@sunrise.test');

        $admin = User::where('email', 'adeyemi@sunrise.test')->firstOrFail();
        $school = School::where('subdomain', 'sunrise')->firstOrFail();

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $admin->id,
            'school_id' => $school->id,
            'role' => 'school_admin',
        ]);
    }

    /** That administrator can actually sign in to the school just created. */
    public function test_the_new_administrator_can_sign_in(): void
    {
        $password = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/platform/schools', $this->provision())
            ->assertCreated()
            ->json('administrator.temporary_password');

        $this->postJson('/api/v1/auth/login', [
            'subdomain' => 'sunrise',
            'email' => 'adeyemi@sunrise.test',
            'password' => $password,
        ], ['X-School-Subdomain' => 'sunrise'])->assertOk();
    }

    /**
     * Handing a school the operator's own subdomain would take the console
     * offline, and there would be no console left to undo it from.
     */
    public function test_reserved_subdomains_are_refused(): void
    {
        foreach (['admin', 'www', 'api', 'app'] as $reserved) {
            $this->actingAs($this->superAdmin, 'sanctum')
                ->postJson('/api/v1/platform/schools', $this->provision([
                    'subdomain' => $reserved,
                    'admin_email' => "admin@{$reserved}.test",
                ]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('subdomain');
        }
    }

    public function test_a_subdomain_must_be_a_valid_hostname_label(): void
    {
        foreach (['Sun Rise', 'sunrise_academy', '-sunrise', 'sunrise-', 'SUNRISE'] as $bad) {
            $this->actingAs($this->superAdmin, 'sanctum')
                ->postJson('/api/v1/platform/schools', $this->provision(['subdomain' => $bad]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('subdomain');
        }
    }

    public function test_a_subdomain_cannot_be_taken_twice(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/platform/schools', $this->provision(['subdomain' => 'greenfield']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('subdomain');
    }

    /** Nothing half-created when the administrator's email is already taken. */
    public function test_a_failed_provision_leaves_no_orphan_school(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/platform/schools', $this->provision([
                'admin_email' => 'admin@greenfield.test',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_email');

        $this->assertDatabaseMissing('schools', ['subdomain' => 'sunrise']);
    }

    public function test_provisioning_is_audited(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/platform/schools', $this->provision())
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'platform.school.provisioned',
            'user_id' => $this->superAdmin->id,
        ]);
    }

    public function test_a_school_admin_cannot_onboard_a_school(): void
    {
        $this->actingAs($this->schoolAdmin, 'sanctum')
            ->postJson('/api/v1/platform/schools', $this->provision())
            ->assertForbidden();

        $this->assertDatabaseMissing('schools', ['subdomain' => 'sunrise']);
    }
}
