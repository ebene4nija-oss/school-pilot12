<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A platform operator working inside one school through the console's picker.
 *
 * Every controller resolved its school from `$user->userProfile->school_id`,
 * which is null for a super admin even when the picker has scoped them to a
 * tenant. The dashboard died outright — SchoolInsightsService type-hints
 * `int $schoolId`, so null was a TypeError and a 500 on the first screen after
 * sign-in. The quieter half was worse: endpoints that merely filtered on the
 * value reported an empty school, and an empty school looks like an answer.
 */
class OperatorViewingSchoolTest extends TestCase
{
    use RefreshDatabase;

    private School $greenfield;

    private School $rival;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->greenfield = School::create([
            'name' => 'Greenfield Academy', 'slug' => 'greenfield',
            'subdomain' => 'greenfield', 'status' => 'active',
        ]);

        $this->rival = School::create([
            'name' => 'Rival Academy', 'slug' => 'rival',
            'subdomain' => 'rival', 'status' => 'active',
        ]);

        $this->operator = User::create([
            'name' => 'Platform Admin',
            'email' => 'ops@schoolpilot.test',
            'password' => bcrypt('password'),
        ]);
        // No school of their own — that is what makes them an operator.
        $this->operator->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);

        $this->enrol($this->greenfield, 3);
        $this->enrol($this->rival, 7);
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

    private function viewing(School $school): array
    {
        return ['X-Platform-School' => (string) $school->id];
    }

    /** The 500 this test exists for. */
    public function test_the_school_dashboard_loads_instead_of_erroring(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/analytics/principal', $this->viewing($this->greenfield))
            ->assertOk()
            ->assertJsonPath('population.active', 3);
    }

    /** And it reports the chosen school, not an empty one. */
    public function test_the_dashboard_reports_the_chosen_school(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/analytics/principal', $this->viewing($this->rival))
            ->assertOk()
            ->assertJsonPath('population.active', 7);
    }

    /**
     * The quiet half of the bug, across the endpoints the portal's pages call
     * as soon as they render. Each of these answered for a null school before.
     */
    public function test_the_portals_pages_answer_for_the_chosen_school(): void
    {
        $endpoints = [
            '/api/v1/students',
            '/api/v1/classes',
            '/api/v1/terms',
            '/api/v1/subjects',
            '/api/v1/transport/buses',
            '/api/v1/library/books',
            '/api/v1/hostel/rooms',
            '/api/v1/health/clinic-visits',
            '/api/v1/live-classes',
            '/api/v1/assessment/ai-comments/pending',
            '/api/v1/school-migration',
        ];

        foreach ($endpoints as $endpoint) {
            $this->actingAs($this->operator, 'sanctum')
                ->getJson($endpoint, $this->viewing($this->greenfield))
                ->assertOk("{$endpoint} should answer for the school being viewed");
        }
    }

    public function test_the_roster_is_the_viewed_schools_roster(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/students', $this->viewing($this->greenfield))
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/students', $this->viewing($this->rival))
            ->assertOk()
            ->assertJsonCount(7, 'data');
    }

    /**
     * What the portal's chrome reads to say whose school it is showing. Getting
     * this wrong is how an operator edits the wrong school's data.
     */
    public function test_the_acting_school_is_reported_to_the_client(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/user', $this->viewing($this->greenfield))
            ->assertOk()
            ->assertJsonPath('is_platform_operator', true)
            ->assertJsonPath('school.name', 'Greenfield Academy');
    }

    public function test_an_operator_outside_any_school_has_no_acting_school(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('is_platform_operator', true)
            ->assertJsonPath('school', null);
    }

    /** A school-side admin is unaffected by any of this. */
    public function test_a_school_admin_still_sees_only_their_own_school(): void
    {
        $admin = User::create([
            'name' => 'Greenfield Admin',
            'email' => 'admin@greenfield.test',
            'password' => bcrypt('password'),
        ]);
        $admin->userProfile()->create([
            'school_id' => $this->greenfield->id,
            'role' => 'school_admin',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/analytics/principal')
            ->assertOk()
            ->assertJsonPath('population.active', 3);

        // And still cannot borrow the operator's header to reach another school.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/analytics/principal', $this->viewing($this->rival))
            ->assertOk()
            ->assertJsonPath('population.active', 3);
    }

    /**
     * Signing in through the internal `admin` subdomain must not put the
     * operator *inside* that school.
     *
     * TenantResolutionMiddleware runs before authentication and resolves a
     * school from the host, so by the time the operator is known there is
     * already a school id on the context, and `markUnscoped()` does not clear
     * it. The portal consequently greeted a platform operator with SchoolPilot
     * HQ's dashboard and named that school in the chrome, instead of showing
     * their own console.
     */
    public function test_signing_in_through_a_subdomain_does_not_put_an_operator_inside_it(): void
    {
        $hq = School::create([
            'name' => 'SchoolPilot HQ', 'slug' => 'admin',
            'subdomain' => 'admin', 'status' => 'active',
        ]);
        $this->enrol($hq, 2);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/user', ['X-School-Subdomain' => 'admin'])
            ->assertOk()
            ->assertJsonPath('is_platform_operator', true)
            // Not "SchoolPilot HQ".
            ->assertJsonPath('school', null);
    }

    /**
     * And the school dashboard says so rather than crashing or, worse,
     * returning a tidy page of zeroes that reads as an empty school.
     */
    public function test_the_school_dashboard_refuses_an_operator_with_no_school_chosen(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/analytics/principal', ['X-School-Subdomain' => 'admin'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Choose a school to see its dashboard. Platform-wide figures are on the operator console.');
    }

    /** The operator console still answers across every tenant. */
    public function test_the_operator_console_answers_while_unscoped(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/platform/overview', ['X-School-Subdomain' => 'admin'])
            ->assertOk()
            ->assertJsonPath('students.active', 10);
    }
}
