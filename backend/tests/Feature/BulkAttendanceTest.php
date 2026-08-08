<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AttendanceRecord;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roll call. Previously only reachable as one HTTP request per child — 42
 * sequential calls from a teacher's phone on 3G for a single JSS2 register.
 */
class BulkAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private Term $term;
    private SchoolClass $class;
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Register Academy',
            'slug' => 'register',
            'subdomain' => 'register',
            'domain' => 'register.schoolpilot.test',
        ]);

        $this->teacher = $this->user('Teacher', 'teacher@register.test', 'teacher');
        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);

        $session = AcademicSession::create([
            'school_id' => $this->school->id, 'name' => '2025/2026',
            'start_date' => now()->subMonths(3), 'end_date' => now()->addMonths(6),
        ]);

        $this->term = Term::create([
            'school_id' => $this->school->id, 'session_id' => $session->id,
            'name' => 'First Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2),
        ]);

        foreach (range(1, 3) as $i) {
            $this->students[] = $this->student("Pupil {$i}", "ADM60{$i}");
        }
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function student(string $name, string $admission): Student
    {
        $user = $this->user($name, strtolower(str_replace(' ', '', $name)) . '@register.test', 'student');

        return Student::create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'class_id' => $this->class->id,
            'admission_number' => $admission,
            'status' => 'active',
        ]);
    }

    private function url(string $path): string
    {
        return 'http://register.schoolpilot.test/api/v1' . $path;
    }

    private function payload(string $key = 'reg-key-12345678', array $overrides = []): array
    {
        return array_merge([
            'term_id' => $this->term->id,
            'date' => now()->toDateString(),
            'idempotency_key' => $key,
            'records' => [
                ['student_id' => $this->students[0]->id, 'status' => 'present'],
                ['student_id' => $this->students[1]->id, 'status' => 'absent'],
                ['student_id' => $this->students[2]->id, 'status' => 'late'],
            ],
        ], $overrides);
    }

    public function test_whole_register_is_marked_in_one_request()
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/attendance/bulk'), $this->payload())
            ->assertStatus(200)
            ->assertJsonPath('marked_count', 3)
            ->assertJsonPath('rejected_count', 0);

        $this->assertSame(3, AttendanceRecord::count());
        $this->assertSame('absent', AttendanceRecord::where('student_id', $this->students[1]->id)->first()->status);
    }

    /** A teacher taps save twice on a bad connection. */
    public function test_replaying_the_same_idempotency_key_does_not_write_again()
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/attendance/bulk'), $this->payload())
            ->assertStatus(200);

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/attendance/bulk'), $this->payload())
            ->assertStatus(200)
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('marked_count', 3);

        $this->assertSame(3, AttendanceRecord::count());
    }

    /** A genuine correction with a fresh key still updates the register. */
    public function test_a_new_key_corrects_an_earlier_mark()
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/attendance/bulk'), $this->payload())
            ->assertStatus(200);

        $corrected = $this->payload('reg-key-87654321', [
            'records' => [['student_id' => $this->students[1]->id, 'status' => 'present']],
        ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/attendance/bulk'), $corrected)
            ->assertStatus(200)
            ->assertJsonPath('marked_count', 1);

        $this->assertSame('present', AttendanceRecord::where('student_id', $this->students[1]->id)->first()->status);
        // Still one row per child per day, not a second.
        $this->assertSame(3, AttendanceRecord::count());
    }

    /** A stale roster on the client must not fail the whole register. */
    public function test_unknown_students_are_reported_without_failing_the_batch()
    {
        $payload = $this->payload('reg-key-mixed-001', [
            'records' => [
                ['student_id' => $this->students[0]->id, 'status' => 'present'],
                ['student_id' => 999999, 'status' => 'present'],
            ],
        ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/attendance/bulk'), $payload)
            ->assertStatus(200)
            ->assertJsonPath('marked_count', 1)
            ->assertJsonPath('rejected_count', 1)
            ->assertJsonPath('rejected.0.student_id', 999999);
    }

    /** Another school's pupil is not markable from this school's register. */
    public function test_cross_tenant_student_is_rejected()
    {
        $otherSchool = School::create([
            'name' => 'Other', 'slug' => 'otherreg', 'subdomain' => 'otherreg',
            'domain' => 'otherreg.schoolpilot.test',
        ]);
        $otherUser = User::create(['name' => 'Outsider', 'email' => 'out@otherreg.test', 'password' => bcrypt('x')]);
        $otherUser->userProfile()->create(['school_id' => $otherSchool->id, 'role' => 'student']);
        $outsider = Student::create([
            'school_id' => $otherSchool->id, 'user_id' => $otherUser->id, 'admission_number' => 'X1',
        ]);

        $payload = $this->payload('reg-key-cross-001', [
            'records' => [['student_id' => $outsider->id, 'status' => 'present']],
        ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/attendance/bulk'), $payload)
            ->assertStatus(200)
            ->assertJsonPath('marked_count', 0)
            ->assertJsonPath('rejected_count', 1);

        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_register_endpoint_shows_what_is_already_marked()
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/attendance/bulk'), $this->payload());

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson($this->url("/attendance/register?class_id={$this->class->id}"))
            ->assertStatus(200);

        $response->assertJsonCount(3, 'register')
            ->assertJsonPath('register.0.already_marked', true);
    }

    public function test_a_parent_cannot_mark_the_register()
    {
        $parent = $this->user('Parent', 'parent@register.test', 'parent');

        $this->actingAs($parent, 'sanctum')
            ->postJson($this->url('/attendance/bulk'), $this->payload('reg-key-parent-01'))
            ->assertStatus(403);
    }
}
