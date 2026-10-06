<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a teacher may do to a child's record, and to a colleague's: conduct
 * notes and class history only for children they teach, conduct notices to
 * the real guardians, and GPS clock-in for themselves alone.
 */
class TeacherSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private User $stranger;
    private Student $ada;
    private User $mum;
    private Term $term;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test', 'latitude' => 6.5244, 'longitude' => 3.3792, 'geofence_radius_meters' => 250]);
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31', 'is_current' => true]);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-08', 'end_date' => '2026-12-18']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);
        $this->teacher = $this->user('Mr Chidi', 'teacher');
        $this->stranger = $this->user('Mrs Bello', 'teacher');
        DB::table('teacher_subjects')->insert(['school_id' => $this->school->id, 'teacher_id' => $this->teacher->id, 'subject_id' => $maths->id, 'class_id' => $class->id]);
        $this->ada = Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('Ada Obi', 'student')->id, 'class_id' => $class->id, 'admission_number' => 'ADA001', 'status' => 'active']);
        $this->mum = $this->user('Mrs Obi', 'parent');
        $guardian = Guardian::create(['school_id' => $this->school->id, 'user_id' => $this->mum->id, 'relationship' => 'mother']);
        DB::table('student_guardian')->insert(['student_id' => $this->ada->id, 'guardian_id' => $guardian->id]);
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function report(User $by)
    {
        return $this->actingAs($by, 'sanctum')->postJson("{$this->host}/students/behavior-report", [
            'student_id' => $this->ada->id, 'type' => 'warning', 'title' => 'Fighting at break', 'description' => 'Details.', 'incident_date' => '2026-10-05',
        ]);
    }

    public function test_a_conduct_note_reaches_the_guardians_without_its_details(): void
    {
        $this->report($this->teacher)->assertCreated();

        $notice = NotificationLog::where('user_id', $this->mum->id)->first();
        $this->assertNotNull($notice, 'The mother is told.');
        $this->assertStringContainsString('Ada', $notice->body);
        $this->assertStringNotContainsString('Fighting', $notice->body, 'No detail on a lock screen.');
        $this->assertFalse(NotificationLog::where('user_id', $this->ada->user_id)->exists(), 'Not sent to the child.');
    }

    public function test_only_a_teacher_who_teaches_the_child_records_or_reads_their_history(): void
    {
        $this->report($this->stranger)->assertForbidden();
        $this->actingAs($this->stranger, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/class-history")->assertForbidden();
        $this->actingAs($this->stranger, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/enrollments")->assertForbidden();

        $this->actingAs($this->teacher, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/class-history")->assertOk();
        $this->actingAs($this->teacher, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/enrollments")->assertOk();
    }

    public function test_gps_clock_in_is_for_yourself_only(): void
    {
        $mine = Staff::create(['school_id' => $this->school->id, 'user_id' => $this->teacher->id, 'staff_id' => 'T-001']);
        $colleague = Staff::create(['school_id' => $this->school->id, 'user_id' => $this->stranger->id, 'staff_id' => 'T-002']);
        $atGate = ['term_id' => $this->term->id, 'latitude' => 6.5245, 'longitude' => 3.3793];

        $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/attendance/staff-gps", $atGate + ['staff_id' => $colleague->id])
            ->assertForbidden();
        $this->assertDatabaseMissing('attendance_records', ['staff_id' => $colleague->id]);

        // No staff_id: the caller's own record.
        $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/attendance/staff-gps", $atGate)->assertSuccessful();
        $this->assertDatabaseHas('attendance_records', ['staff_id' => $mine->id, 'status' => 'present']);
    }
}
