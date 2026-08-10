<?php

namespace Tests\Feature;

use App\Models\Arm;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /parent/children` — gap G11.
 *
 * The pivot that links a guardian to their children has existed since the first
 * migration and nothing ever read it from the guardian's side. Every other
 * parent endpoint takes a student id, and `GET /students` is staff-only, so a
 * signed-in parent had no first request to make and the role did not function.
 *
 * What is tested here is mostly *what does not come back*: the children of the
 * other parent in the same school, a child at another school, and the four
 * encrypted health columns.
 */
class ParentChildrenTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $mother;
    private Student $chidi;
    private Student $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Graceland College', 'slug' => 'graceland', 'subdomain' => 'graceland',
        ]);

        $jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2', 'order_index' => 2]);
        $gold = Arm::create(['school_id' => $this->school->id, 'class_id' => $jss2->id, 'name' => 'Gold']);

        $this->mother = $this->user('Mrs Okeke', 'okeke@graceland.test', 'parent');

        $this->chidi = $this->student('Chidi Okeke', 'GC/2026/010', $jss2, $gold);
        $this->ada = $this->student('Ada Okeke', 'GC/2026/011', $jss2, $gold);

        $guardian = Guardian::create([
            'school_id' => $this->school->id,
            'user_id' => $this->mother->id,
            'relationship' => 'mother',
        ]);

        $guardian->students()->attach($this->chidi->id, ['is_primary' => true]);
        $guardian->students()->attach($this->ada->id, ['is_primary' => false]);
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

    private function student(string $name, string $admission, ?SchoolClass $class = null, ?Arm $arm = null, ?School $school = null): Student
    {
        $school = $school ?? $this->school;
        $user = $this->user($name, str_replace(' ', '', strtolower($name)) . '@graceland.test', 'student', $school);

        return Student::create([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'class_id' => $class?->id,
            'arm_id' => $arm?->id,
            'admission_number' => $admission,
            'blood_group' => 'O+',
            'allergies' => ['penicillin'],
            'medical_notes' => 'Asthmatic.',
        ]);
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    public function test_a_parent_gets_their_own_children_with_what_a_home_screen_needs()
    {
        $response = $this->as($this->mother)
            ->getJson('/api/v1/parent/children')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));

        $chidi = collect($response->json('data'))->firstWhere('admission_number', 'GC/2026/010');

        $this->assertSame('Chidi Okeke', $chidi['name']);
        $this->assertSame('JSS 2', $chidi['class_name']);
        $this->assertSame('Gold', $chidi['arm_name']);
        $this->assertTrue($chidi['is_primary_guardian']);

        // The id every other parent endpoint takes.
        $this->assertSame($this->chidi->id, $chidi['id']);
    }

    public function test_a_parent_does_not_get_another_parents_child()
    {
        $otherParent = $this->user('Mr Bello', 'bello@graceland.test', 'parent');
        $notMine = $this->student('Tunde Bello', 'GC/2026/050');

        $otherGuardian = Guardian::create([
            'school_id' => $this->school->id,
            'user_id' => $otherParent->id,
            'relationship' => 'father',
        ]);
        $otherGuardian->students()->attach($notMine->id, ['is_primary' => true]);

        $ids = collect(
            $this->as($this->mother)->getJson('/api/v1/parent/children')->json('data')
        )->pluck('id');

        $this->assertCount(2, $ids);
        $this->assertNotContains($notMine->id, $ids);
    }

    public function test_a_child_at_another_school_is_never_returned()
    {
        $rival = School::create(['name' => 'Rival', 'slug' => 'rival', 'subdomain' => 'rival']);
        $rivalClass = SchoolClass::create(['school_id' => $rival->id, 'name' => 'JSS 2']);
        $theirChild = $this->student('Foreign Child', 'RV/2026/001', $rivalClass, null, $rival);

        /*
         * The pivot carries no school_id, so a guardian row pointed at another
         * school's student would otherwise sail straight through. Attached
         * deliberately to prove the tenant scope on `students` is what stops it.
         */
        Guardian::where('user_id', $this->mother->id)->first()
            ->students()->attach($theirChild->id, ['is_primary' => true]);

        $ids = collect(
            $this->as($this->mother)->getJson('/api/v1/parent/children')->json('data')
        )->pluck('id');

        $this->assertNotContains($theirChild->id, $ids);
    }

    /** NDPA §12: a picker of children is not a place for health data. */
    public function test_health_data_never_appears_in_the_children_list()
    {
        $body = $this->as($this->mother)->getJson('/api/v1/parent/children')->getContent();

        $this->assertStringNotContainsString('blood_group', $body);
        $this->assertStringNotContainsString('O+', $body);
        $this->assertStringNotContainsString('penicillin', $body);
        $this->assertStringNotContainsString('Asthmatic', $body);
    }

    public function test_a_parent_with_no_linked_child_is_told_so_rather_than_erroring()
    {
        $unlinked = $this->user('New Parent', 'new@graceland.test', 'parent');

        $this->as($unlinked)
            ->getJson('/api/v1/parent/children')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('message', 'No children are linked to this account. Ask the school to link your child to your profile.');
    }

    public function test_a_teacher_cannot_use_the_parent_children_route()
    {
        $teacher = $this->user('Teacher', 'teacher@graceland.test', 'teacher');

        $this->as($teacher)->getJson('/api/v1/parent/children')->assertStatus(403);
    }

    public function test_the_route_requires_authentication()
    {
        $this->getJson('/api/v1/parent/children')->assertStatus(401);
    }
}
