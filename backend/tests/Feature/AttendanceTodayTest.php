<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AttendanceRecord;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The administrator's "how is today going": registers in, who is absent with
 * a parent's number, and staff clock-ins.
 */
class AttendanceTodayTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Today Academy', 'slug' => 'today', 'subdomain' => 'today', 'domain' => 'today.schoolpilot.test']);
        $this->admin = $this->user('Admin', 'school_admin');

        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(10), 'is_current' => true]);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonth()]);
    }

    private function user(string $name, string $role, ?string $phone = null): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@today.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role, 'phone' => $phone]);

        return $u;
    }

    private function enrol(string $name, SchoolClass $class): Student
    {
        return Student::create(['school_id' => $this->school->id, 'user_id' => $this->user($name, 'student')->id, 'class_id' => $class->id, 'admission_number' => strtoupper(substr($name, 0, 3)).rand(100, 999), 'status' => 'active']);
    }

    private function mark(Student $s, string $status): void
    {
        AttendanceRecord::create(['school_id' => $this->school->id, 'term_id' => $this->term->id, 'student_id' => $s->id, 'date' => now()->toDateString(), 'status' => $status]);
    }

    private function url(): string
    {
        return 'http://today.schoolpilot.test/api/v1/attendance/today';
    }

    public function test_registers_absences_and_staff_for_today(): void
    {
        $jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);

        $ada = $this->enrol('Ada Obi', $jss1);
        $bayo = $this->enrol('Bayo Ade', $jss1);
        $this->enrol('Chika Eze', $jss2); // JSS 2's register is not in yet.

        $this->mark($ada, 'present');
        $this->mark($bayo, 'absent');

        $parent = $this->user('Mr Ade', 'parent', '08031234567');
        $guardian = Guardian::create(['school_id' => $this->school->id, 'user_id' => $parent->id, 'relationship' => 'father']);
        $bayo->guardians()->attach($guardian->id);

        $in = Staff::create(['school_id' => $this->school->id, 'user_id' => $this->user('Mrs Early', 'teacher')->id, 'staff_id' => 'ST1', 'designation' => 'Teacher']);
        Staff::create(['school_id' => $this->school->id, 'user_id' => $this->user('Mr Late', 'teacher')->id, 'staff_id' => 'ST2', 'designation' => 'Teacher']);
        AttendanceRecord::create(['school_id' => $this->school->id, 'term_id' => $this->term->id, 'staff_id' => $in->id, 'date' => now()->toDateString(), 'status' => 'present']);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk();

        $response->assertJsonPath('summary.students', 3)
            ->assertJsonPath('summary.present', 1)
            ->assertJsonPath('summary.absent', 1)
            ->assertJsonPath('summary.classes', 2)
            ->assertJsonPath('summary.classes_marked', 1)
            ->assertJsonPath('absent.0.name', 'Bayo Ade')
            ->assertJsonPath('absent.0.guardian_phone', '08031234567')
            ->assertJsonPath('staff.total', 2)
            ->assertJsonPath('staff.clocked_in', 1);

        $jss2Row = collect($response->json('classes'))->firstWhere('class', 'JSS 2');
        $this->assertFalse($jss2Row['marked']);

        // Phone numbers handed out in bulk are recorded (counts only).
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.today_viewed', 'user_id' => $this->admin->id]);
    }

    public function test_teachers_cannot_see_the_whole_school(): void
    {
        $this->actingAs($this->user('Teacher', 'teacher'), 'sanctum')->getJson($this->url())->assertForbidden();
    }
}
