<?php

namespace Tests\Feature;

use App\Models\Arm;
use App\Models\LiveClass;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Virtual classrooms.
 *
 * The page for this shipped as a mockup with an invented Zoom session on it.
 * The behaviour that actually matters and had nowhere to live: a student must
 * see their own class's sessions and no others, and a joining link must not sit
 * in a student's browser for a week before the lesson.
 */
class LiveClassTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $teacher;

    private SchoolClass $jss1;

    private SchoolClass $jss2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Greenfield Academy',
            'slug' => 'greenfield',
            'subdomain' => 'greenfield',
            'status' => 'active',
        ]);

        $this->admin = $this->makeUser('Greenfield Admin', 'admin@greenfield.test', 'school_admin');
        $this->teacher = $this->makeUser('Mr Chidi', 'chidi@greenfield.test', 'teacher');

        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1', 'level' => 'JSS']);
        $this->jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2', 'level' => 'JSS']);

        // Mr Chidi teaches JSS 1 Mathematics; he does not teach JSS 2.
        $maths = \App\Models\Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);
        \Illuminate\Support\Facades\DB::table('teacher_subjects')->insert(['school_id' => $this->school->id, 'teacher_id' => $this->teacher->id, 'subject_id' => $maths->id, 'class_id' => $this->jss1->id]);
    }

    private function makeUser(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function makeStudent(string $name, SchoolClass $class, ?Arm $arm = null): User
    {
        $user = $this->makeUser($name, strtolower(str_replace(' ', '.', $name)).'@greenfield.test', 'student');

        Student::create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'class_id' => $class->id,
            'arm_id' => $arm?->id,
            'admission_number' => 'GRN-'.fake()->unique()->numberBetween(100, 999),
            'status' => 'active',
        ]);

        return $user;
    }

    private function schedule(array $overrides = []): LiveClass
    {
        return LiveClass::create(array_merge([
            'school_id' => $this->school->id,
            'class_id' => $this->jss1->id,
            'teacher_id' => $this->teacher->id,
            'title' => 'Algebra & Linear Equations',
            'provider' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/1234567890',
            'meeting_id' => '123 456 7890',
            'passcode' => 'algebra',
            'scheduled_for' => now()->addDay(),
            'duration_minutes' => 45,
            'status' => 'scheduled',
        ], $overrides));
    }

    public function test_a_teacher_can_schedule_a_session(): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/v1/live-classes', [
                'title' => 'Quadratic Equations',
                'class_id' => $this->jss1->id,
                'provider' => 'google_meet',
                'meeting_url' => 'https://meet.google.com/abc-defg-hij',
                'scheduled_for' => now()->addDay()->toIso8601String(),
                'duration_minutes' => 45,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Quadratic Equations')
            ->assertJsonPath('data.status', 'scheduled')
            // Defaults to the teacher scheduling it.
            ->assertJsonPath('data.teacher', 'Mr Chidi');
    }

    public function test_a_teacher_cannot_schedule_for_a_class_they_do_not_teach(): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/v1/live-classes', [
                'title' => 'Not my class',
                'class_id' => $this->jss2->id,
                'provider' => 'zoom',
                'meeting_url' => 'https://zoom.us/j/1',
                'scheduled_for' => now()->addDay()->toIso8601String(),
                'duration_minutes' => 45,
            ])
            ->assertForbidden();
    }

    public function test_a_joining_link_must_be_a_url(): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/v1/live-classes', [
                'title' => 'Geometry',
                'provider' => 'zoom',
                'meeting_url' => 'room 4021',
                'scheduled_for' => now()->addDay()->toIso8601String(),
                'duration_minutes' => 45,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('meeting_url');
    }

    public function test_a_student_sees_only_their_own_classes_sessions(): void
    {
        $this->schedule(['class_id' => $this->jss1->id, 'title' => 'JSS 1 Maths']);
        $this->schedule(['class_id' => $this->jss2->id, 'title' => 'JSS 2 Maths']);

        $student = $this->makeStudent('Chidi Nnamdi', $this->jss1);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/live-classes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'JSS 1 Maths');
    }

    public function test_an_arm_specific_session_is_hidden_from_the_other_arm(): void
    {
        $gold = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'name' => 'Gold']);
        $silver = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'name' => 'Silver']);

        $this->schedule(['class_id' => $this->jss1->id, 'arm_id' => $gold->id, 'title' => 'Gold only']);
        $this->schedule(['class_id' => $this->jss1->id, 'arm_id' => null, 'title' => 'Whole class']);

        $silverStudent = $this->makeStudent('Amina Bello', $this->jss1, $silver);

        $response = $this->actingAs($silverStudent, 'sanctum')
            ->getJson('/api/v1/live-classes')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('Whole class', $response->json('data.0.title'));
    }

    /**
     * A link that is readable a day early is a link that leaves the school.
     */
    public function test_a_student_cannot_read_the_joining_link_before_the_session_opens(): void
    {
        $this->schedule(['scheduled_for' => now()->addDay()]);

        $student = $this->makeStudent('Chidi Nnamdi', $this->jss1);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/live-classes')
            ->assertOk()
            ->assertJsonPath('data.0.is_joinable', false)
            ->assertJsonPath('data.0.meeting_url', null)
            ->assertJsonPath('data.0.passcode', null);
    }

    public function test_a_student_gets_the_joining_link_once_the_session_opens(): void
    {
        // Opens five minutes early, so a lesson does not start late.
        $this->schedule(['scheduled_for' => now()->addMinutes(2)]);

        $student = $this->makeStudent('Chidi Nnamdi', $this->jss1);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/live-classes')
            ->assertOk()
            ->assertJsonPath('data.0.is_joinable', true)
            ->assertJsonPath('data.0.meeting_url', 'https://zoom.us/j/1234567890')
            ->assertJsonPath('data.0.passcode', 'algebra');
    }

    public function test_staff_always_see_the_joining_details(): void
    {
        $this->schedule(['scheduled_for' => now()->addWeek()]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/live-classes')
            ->assertOk()
            ->assertJsonPath('data.0.meeting_url', 'https://zoom.us/j/1234567890');
    }

    public function test_a_teacher_sees_only_their_own_sessions(): void
    {
        $otherTeacher = $this->makeUser('Mrs Bello', 'bello@greenfield.test', 'teacher');

        $this->schedule(['teacher_id' => $this->teacher->id, 'title' => 'Mine']);
        $this->schedule(['teacher_id' => $otherTeacher->id, 'title' => 'Theirs']);

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/v1/live-classes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');
    }

    public function test_marking_a_session_live_records_when_it_started(): void
    {
        $session = $this->schedule();

        $this->actingAs($this->teacher, 'sanctum')
            ->putJson("/api/v1/live-classes/{$session->id}", ['status' => 'live'])
            ->assertOk()
            ->assertJsonPath('data.status', 'live');

        $this->assertNotNull($session->fresh()->started_at);
    }

    public function test_cancelling_a_session_stamps_its_end(): void
    {
        $session = $this->schedule();

        $this->actingAs($this->teacher, 'sanctum')
            ->putJson("/api/v1/live-classes/{$session->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.is_joinable', false);

        $this->assertNotNull($session->fresh()->ended_at);
    }

    public function test_upcoming_filter_excludes_finished_sessions(): void
    {
        $this->schedule(['title' => 'Tomorrow', 'scheduled_for' => now()->addDay()]);
        $this->schedule(['title' => 'Last week', 'scheduled_for' => now()->subWeek(), 'status' => 'ended']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/live-classes?upcoming=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Tomorrow');
    }

    public function test_a_student_cannot_schedule_a_session(): void
    {
        $student = $this->makeStudent('Chidi Nnamdi', $this->jss1);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/live-classes', [
                'title' => 'Free period',
                'provider' => 'zoom',
                'meeting_url' => 'https://zoom.us/j/999',
                'scheduled_for' => now()->addDay()->toIso8601String(),
                'duration_minutes' => 45,
            ])
            ->assertForbidden();
    }

    public function test_sessions_do_not_cross_the_tenant_boundary(): void
    {
        $rival = School::create([
            'name' => 'Rival Academy', 'slug' => 'rival', 'subdomain' => 'rival', 'status' => 'active',
        ]);

        $rivalTeacher = User::create([
            'name' => 'Rival Teacher', 'email' => 'rival@rival.test', 'password' => bcrypt('password'),
        ]);
        $rivalTeacher->userProfile()->create(['school_id' => $rival->id, 'role' => 'teacher']);

        LiveClass::create([
            'school_id' => $rival->id,
            'teacher_id' => $rivalTeacher->id,
            'title' => 'Rival session',
            'provider' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/555',
            'scheduled_for' => now()->addDay(),
            'duration_minutes' => 45,
            'status' => 'scheduled',
        ]);

        $this->schedule(['title' => 'Ours']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/live-classes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Ours');
    }
}
