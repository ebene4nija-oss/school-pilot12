<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `GET /classes` and `GET /terms` — gap G12.
 *
 * Every staff screen has to choose a class and a term before it can ask the
 * server anything, and neither list was published. The interesting behaviour is
 * the current term: `terms.is_current` exists as a column but nothing in the
 * product ever writes it, so a picker that trusted it would open with no
 * default on real data.
 */
class AcademicStructureTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private AcademicSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Graceland College', 'slug' => 'graceland', 'subdomain' => 'graceland',
        ]);

        $this->admin = $this->user('Principal', 'principal@graceland.test', 'school_admin');

        $this->session = AcademicSession::create([
            'school_id' => $this->school->id,
            'name' => '2026/2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-07-31',
            'is_current' => true,
        ]);
    }

    private function user(string $name, string $email, string $role, ?School $school = null): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);

        UserProfile::create([
            'user_id' => $user->id,
            'school_id' => ($school ?? $this->school)->id,
            'role' => $role,
        ]);

        return $user;
    }

    private function term(string $name, string $start, string $end, bool $flagged = false): Term
    {
        return Term::create([
            'school_id' => $this->school->id,
            'session_id' => $this->session->id,
            'name' => $name,
            'start_date' => $start,
            'end_date' => $end,
            'is_current' => $flagged,
        ]);
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    // ----------------------------------------------------------- Classes

    public function test_classes_come_back_in_teaching_order_with_arms_and_roll()
    {
        // Deliberately created out of order, and named so that sorting them
        // alphabetically would put SS 1 first — which is the bug this ordering
        // exists to avoid.
        $ss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'SS 1', 'order_index' => 4, 'level_category' => 'senior_secondary']);
        $jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1', 'order_index' => 1, 'level_category' => 'junior_secondary']);

        Arm::create(['school_id' => $this->school->id, 'class_id' => $jss1->id, 'name' => 'Silver']);
        Arm::create(['school_id' => $this->school->id, 'class_id' => $jss1->id, 'name' => 'Gold']);

        $this->enrol($jss1, 3);
        $this->enrol($ss1, 1);

        $response = $this->as($this->admin)->getJson('/api/v1/classes')->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['JSS 1', 'SS 1'], $names);

        $first = $response->json('data.0');
        $this->assertSame(['Gold', 'Silver'], collect($first['arms'])->pluck('name')->all());
        $this->assertSame(3, $first['student_count']);
        $this->assertSame('junior_secondary', $first['level_category']);
    }

    /** Graduated and transferred children are not on this year's roll. */
    public function test_the_roll_count_only_counts_active_students()
    {
        $jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);

        $this->enrol($jss1, 2);
        $this->enrol($jss1, 1, 'graduated');

        $this->as($this->admin)
            ->getJson('/api/v1/classes')
            ->assertOk()
            ->assertJsonPath('data.0.student_count', 2);
    }

    public function test_another_schools_classes_are_not_listed()
    {
        SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);

        $rival = School::create(['name' => 'Rival', 'slug' => 'rival', 'subdomain' => 'rival']);
        SchoolClass::create(['school_id' => $rival->id, 'name' => 'Their JSS 1']);

        $response = $this->as($this->admin)->getJson('/api/v1/classes')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('JSS 1', $response->json('data.0.name'));
    }

    // ------------------------------------------------------------- Terms

    public function test_the_current_term_is_the_one_today_falls_inside()
    {
        Carbon::setTestNow('2026-10-15');

        $this->term('First Term', '2026-09-14', '2026-12-11');
        $second = $this->term('Second Term', '2027-01-05', '2027-04-02');

        $response = $this->as($this->admin)->getJson('/api/v1/terms')->assertOk();

        $first = collect($response->json('data'))->firstWhere('name', 'First Term');

        $this->assertSame($first['id'], $response->json('current_term_id'));
        $this->assertTrue($first['is_current']);
        $this->assertNotSame($second->id, $response->json('current_term_id'));

        // The session comes along, so a client needs one call rather than two.
        $this->assertSame('2026/2027', $response->json('current_session.name'));
    }

    /**
     * The regression this endpoint exists to avoid: nothing in the product
     * writes `terms.is_current`, so trusting the column alone opens every
     * picker with no default at all.
     */
    public function test_a_default_is_still_found_when_no_term_is_flagged_current()
    {
        Carbon::setTestNow('2026-10-15');

        $this->term('First Term', '2026-09-14', '2026-12-11');

        $this->assertSame(0, Term::where('is_current', true)->count());

        $this->as($this->admin)
            ->getJson('/api/v1/terms')
            ->assertOk()
            ->assertJsonPath('current_term_id', Term::first()->id);
    }

    /**
     * The Nigerian long vacation. Today is inside no term, and the school is
     * finishing last term's results rather than starting a term that has not
     * begun.
     */
    public function test_during_the_holidays_the_term_that_just_ended_is_current()
    {
        Carbon::setTestNow('2027-08-10');

        $this->term('Second Term', '2027-01-05', '2027-04-02');
        $third = $this->term('Third Term', '2027-04-26', '2027-07-23');

        $this->as($this->admin)
            ->getJson('/api/v1/terms')
            ->assertOk()
            ->assertJsonPath('current_term_id', $third->id);
    }

    /** A hand-set flag is a deliberate statement and outranks the guess. */
    public function test_a_pinned_term_wins_when_today_is_inside_none()
    {
        Carbon::setTestNow('2027-08-10');

        $pinned = $this->term('Second Term', '2027-01-05', '2027-04-02', flagged: true);
        $this->term('Third Term', '2027-04-26', '2027-07-23');

        $this->as($this->admin)
            ->getJson('/api/v1/terms')
            ->assertOk()
            ->assertJsonPath('current_term_id', $pinned->id);
    }

    public function test_a_school_with_no_terms_gets_an_empty_list_and_a_null_default()
    {
        $this->as($this->admin)
            ->getJson('/api/v1/terms')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('current_term_id', null);
    }

    public function test_another_schools_terms_are_not_listed()
    {
        $this->term('First Term', '2026-09-14', '2026-12-11');

        $rival = School::create(['name' => 'Rival', 'slug' => 'rival', 'subdomain' => 'rival']);
        $rivalSession = AcademicSession::create([
            'school_id' => $rival->id, 'name' => '2026/2027',
            'start_date' => '2026-09-01', 'end_date' => '2027-07-31',
        ]);
        Term::create([
            'school_id' => $rival->id, 'session_id' => $rivalSession->id,
            'name' => 'Their First Term', 'start_date' => '2026-09-14', 'end_date' => '2026-12-11',
        ]);

        $response = $this->as($this->admin)->getJson('/api/v1/terms')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('First Term', $response->json('data.0.name'));
    }

    /**
     * A guardian needs the term list to ask for a result, exactly as a teacher
     * needs it to enter scores. Neither list says anything about a child.
     */
    public function test_a_parent_can_read_the_term_list()
    {
        $this->term('First Term', '2026-09-14', '2026-12-11');
        $parent = $this->user('Parent', 'parent@graceland.test', 'parent');

        $this->as($parent)->getJson('/api/v1/terms')->assertOk();
    }

    public function test_both_pickers_require_authentication()
    {
        $this->getJson('/api/v1/classes')->assertStatus(401);
        $this->getJson('/api/v1/terms')->assertStatus(401);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function enrol(SchoolClass $class, int $count, string $status = 'active'): void
    {
        foreach (range(1, $count) as $index) {
            $email = "{$status}{$index}c{$class->id}@graceland.test";

            Student::create([
                'school_id' => $this->school->id,
                'user_id' => $this->user("Child {$index}", $email, 'student')->id,
                'class_id' => $class->id,
                'admission_number' => "GC/{$class->id}/{$status}/{$index}",
                'status' => $status,
            ]);
        }
    }
}
