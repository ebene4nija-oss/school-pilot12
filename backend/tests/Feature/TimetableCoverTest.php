<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\NotificationLog;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Clash checks and cover for absent teachers (TASK 07). */
class TimetableCoverTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $chidi;
    private User $bello;
    private User $eze;
    private SchoolClass $jss1;
    private SchoolClass $jss2;
    private Subject $maths;
    private int $version;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 07:00:00')); // Monday

        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $this->admin = $this->user('Admin', 'school_admin');
        $this->chidi = $this->user('Mr Chidi', 'teacher');
        $this->bello = $this->user('Mrs Bello', 'teacher');
        $this->eze = $this->user('Mr Eze', 'teacher');
        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);
        DB::table('teacher_subjects')->insert(['school_id' => $this->school->id, 'teacher_id' => $this->eze->id, 'subject_id' => $this->maths->id, 'class_id' => $this->jss2->id]);

        $this->version = DB::table('timetable_versions')->insertGetId(['school_id' => $this->school->id, 'name' => 'T1', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $this->entry($this->jss1, $this->chidi, 'P1', '08:00', '08:40');
        $this->entry($this->jss2, $this->bello, 'P1', '08:00', '08:40');
        $this->entry($this->jss2, $this->chidi, 'P2', '08:40', '09:20');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function entry(SchoolClass $class, User $teacher, string $slot, string $start, string $end): int
    {
        return DB::table('timetable_entries')->insertGetId(['timetable_version_id' => $this->version, 'school_class_id' => $class->id, 'subject_id' => $this->maths->id, 'subject_name' => 'Mathematics', 'teacher_id' => $teacher->id, 'day_of_week' => 'Monday', 'slot_name' => $slot, 'start_time' => $start, 'end_time' => $end, 'is_break' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function as(User $u)
    {
        return $this->actingAs($u, 'sanctum');
    }

    private function publish()
    {
        return $this->as($this->admin)->postJson("{$this->host}/timetable/versions/{$this->version}/publish");
    }

    public function test_a_teacher_in_two_classes_at_once_blocks_publication(): void
    {
        $this->entry($this->jss2, $this->chidi, 'P1b', '08:20', '09:00'); // overlaps his JSS 1 P1

        $res = $this->publish()->assertStatus(422);
        $this->assertSame('Mr Chidi', $res->json('clashes.0.teacher'));
        $this->assertSame('draft', DB::table('timetable_versions')->where('id', $this->version)->value('status'));
    }

    public function test_absent_teachers_lessons_get_cover_from_someone_free(): void
    {
        $this->publish()->assertOk();
        $staff = Staff::create(['school_id' => $this->school->id, 'user_id' => $this->chidi->id, 'staff_id' => 'S1']);
        LeaveRequest::create(['school_id' => $this->school->id, 'staff_id' => $staff->id, 'leave_type' => 'sick', 'start_date' => '2026-10-05', 'end_date' => '2026-10-06', 'days_requested' => 2, 'status' => 'approved']);

        $sheet = $this->as($this->admin)->getJson("{$this->host}/timetable/cover?date=2026-10-05")->assertOk()->json();
        $this->assertSame(['Mr Chidi'], array_column($sheet['absent'], 'name'));
        $this->assertCount(2, $sheet['lessons']);

        $p1 = $sheet['lessons'][0];
        $suggested = array_column($p1['suggestions'], 'name');
        $this->assertSame('Mr Eze', $suggested[0], 'A maths teacher who is free comes first.');
        $this->assertNotContains('Mrs Bello', $suggested, 'She teaches JSS 2 at the same time.');

        // Busy teachers are refused.
        $this->as($this->admin)->postJson("{$this->host}/timetable/substitutions", ['date' => '2026-10-05', 'entry_id' => $p1['entry_id'], 'substitute_teacher_id' => $this->bello->id])->assertStatus(409);
        $this->as($this->admin)->postJson("{$this->host}/timetable/substitutions", ['date' => '2026-10-05', 'entry_id' => $p1['entry_id'], 'substitute_teacher_id' => $this->eze->id])->assertCreated();
        $this->assertTrue(NotificationLog::withoutGlobalScopes()->where('user_id', $this->eze->id)->where('body', 'like', 'Cover lesson:%')->exists());

        $this->assertSame('Mr Chidi', $this->as($this->eze)->getJson("{$this->host}/timetable/my-cover")->json('data.0.covering_for'));

        // The student sees who is teaching them today.
        $ada = Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('Ada Obi', 'student')->id, 'class_id' => $this->jss1->id, 'admission_number' => 'A1', 'status' => 'active']);
        $today = $this->as($ada->user)->getJson("{$this->host}/me/dashboard")->json('today.periods');
        $this->assertSame('Mr Eze', $today[0]['cover_teacher']);
    }

    public function test_a_teacher_recorded_absent_for_the_day_needs_cover_too(): void
    {
        $this->publish()->assertOk();
        $this->as($this->admin)->postJson("{$this->host}/timetable/absences", ['teacher_id' => $this->bello->id, 'date' => '2026-10-05', 'reason' => 'sick'])->assertCreated();

        $sheet = $this->as($this->admin)->getJson("{$this->host}/timetable/cover?date=2026-10-05")->json();
        $this->assertSame(['JSS 2'], array_column($sheet['lessons'], 'class'));
        $this->as($this->chidi)->getJson("{$this->host}/timetable/cover?date=2026-10-05")->assertForbidden();
    }
}
