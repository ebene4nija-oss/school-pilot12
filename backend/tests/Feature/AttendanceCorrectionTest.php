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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Attendance corrections need permission and leave history (TASK 12). */
class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $teacher;
    private Student $ada;
    private Term $term;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addYear(), 'is_current' => true]);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2)]);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->admin = $this->user('Admin', 'school_admin');
        $this->teacher = $this->user('Mr Chidi', 'teacher');
        $this->ada = Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('Ada Obi', 'student')->id, 'class_id' => $class->id, 'admission_number' => 'A1', 'status' => 'active']);
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function mark(User $by, string $date, string $status, ?string $reason = null)
    {
        return $this->actingAs($by, 'sanctum')->postJson("{$this->host}/attendance/bulk", array_filter([
            'term_id' => $this->term->id, 'date' => $date, 'idempotency_key' => str()->random(16),
            'records' => [['student_id' => $this->ada->id, 'status' => $status]], 'correction_reason' => $reason,
        ]));
    }

    public function test_a_same_day_change_goes_through_and_is_recorded(): void
    {
        $today = now()->toDateString();
        $this->mark($this->teacher, $today, 'absent')->assertJsonPath('marked_count', 1);
        $this->mark($this->teacher, $today, 'late')->assertJsonPath('marked_count', 1);

        $this->assertSame('late', AttendanceRecord::first()->status);
        $row = DB::table('attendance_corrections')->first();
        $this->assertSame(['absent', 'late'], [$row->from, $row->to]);
    }

    public function test_a_past_day_is_corrected_only_by_an_admin_with_a_reason(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $this->mark($this->teacher, $yesterday, 'absent')->assertJsonPath('marked_count', 1);

        $this->mark($this->teacher, $yesterday, 'present')->assertJsonPath('rejected.0.reason', 'Only an administrator can change attendance for a past day.');
        $this->mark($this->admin, $yesterday, 'present')->assertJsonPath('rejected.0.reason', 'Give a reason to correct attendance for a past day.');
        $this->mark($this->admin, $yesterday, 'present', 'Was at the inter-house sports, confirmed by the games master')->assertJsonPath('marked_count', 1);

        $this->assertSame('present', AttendanceRecord::first()->status);
        $history = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/attendance/corrections?student_id={$this->ada->id}")->assertOk()->json('data');
        $this->assertCount(1, $history);
        $this->assertSame('Admin', $history[0]['by']);
        $this->assertStringContainsString('inter-house sports', $history[0]['reason']);
    }
}
