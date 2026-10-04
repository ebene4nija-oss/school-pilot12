<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AttendanceRecord;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\ResultRelease;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The student portal: dashboard, My Subjects, My Results (TASK 02, 06, 10). */
class StudentPortalTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private SchoolClass $jss1;
    private Term $term;
    private Subject $maths;
    private Subject $english;
    private Subject $french;
    private Student $ada;
    private Student $bayo;
    private User $teacher;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00')); // a Monday

        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31', 'is_current' => true]);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-08', 'end_date' => '2026-12-18']);
        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1', 'level_category' => 'junior_secondary']);
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);
        $this->english = Subject::create(['school_id' => $this->school->id, 'name' => 'English', 'code' => 'ENG']);
        $this->french = Subject::create(['school_id' => $this->school->id, 'name' => 'French', 'code' => 'FRE']);
        foreach ([$this->maths, $this->english] as $s) {
            DB::table('class_subjects')->insert(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'subject_id' => $s->id, 'is_compulsory' => true]);
        }
        $this->teacher = $this->user('Mr Chidi', 'teacher');
        DB::table('teacher_subjects')->insert(['school_id' => $this->school->id, 'teacher_id' => $this->teacher->id, 'subject_id' => $this->maths->id, 'class_id' => $this->jss1->id]);

        $this->ada = $this->student('Ada Obi');
        $this->bayo = $this->student('Bayo Ade');
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

    private function student(string $name): Student
    {
        return Student::create(['school_id' => $this->school->id, 'user_id' => $this->user($name, 'student')->id, 'class_id' => $this->jss1->id, 'admission_number' => strtoupper(substr($name, 0, 3)), 'status' => 'active']);
    }

    private function as(Student $s)
    {
        return $this->actingAs($s->user, 'sanctum');
    }

    private function score(Student $s, Subject $subject, float $total, array $extra = []): void
    {
        ScoreEntry::create($extra + ['school_id' => $this->school->id, 'term_id' => $this->term->id, 'student_id' => $s->id, 'subject_id' => $subject->id, 'first_ca' => 10, 'second_ca' => 10, 'exam' => $total - 20, 'total_score' => $total, 'grade' => $total >= 70 ? 'A' : 'C']);
    }

    public function test_results_appear_only_once_released_and_never_with_an_unreviewed_ai_comment(): void
    {
        $this->score($this->ada, $this->maths, 78, ['teacher_comment' => 'Excellent work this term.', 'ai_comment_status' => 'approved']);
        $this->score($this->ada, $this->english, 55, ['teacher_comment' => 'AI draft awaiting review', 'ai_comment_status' => 'pending_approval']);
        $this->score($this->bayo, $this->maths, 40);

        $this->assertSame('not_released', $this->as($this->ada)->getJson("{$this->host}/me/results?term_id={$this->term->id}")->json('status'));
        $this->assertFalse($this->as($this->ada)->getJson("{$this->host}/me/results")->json('terms.0.released'));

        ResultRelease::create(['school_id' => $this->school->id, 'term_id' => $this->term->id, 'is_released' => true, 'released_at' => now()]);
        $res = $this->as($this->ada)->getJson("{$this->host}/me/results?term_id={$this->term->id}")->assertOk()->json();

        $this->assertSame('released', $res['status']);
        $this->assertCount(2, $res['subjects'], 'Only Ada\'s own scores.');
        $bySubject = collect($res['subjects'])->keyBy('subject');
        $this->assertSame('Excellent work this term.', $bySubject['Mathematics']['comment']);
        $this->assertNull($bySubject['English']['comment']);
        $this->assertEquals(66.5, $res['average']);
    }

    public function test_where_the_school_sells_result_pins_the_portal_is_not_a_way_round_it(): void
    {
        $this->score($this->ada, $this->maths, 78);
        ResultRelease::create(['school_id' => $this->school->id, 'term_id' => $this->term->id, 'is_released' => true, 'released_at' => now()]);
        $this->school->forceFill(['result_checker_enabled' => true])->save();

        $res = $this->as($this->ada)->getJson("{$this->host}/me/results?term_id={$this->term->id}")->json();
        $this->assertSame('locked', $res['status']);
        $this->assertArrayNotHasKey('subjects', $res);

        $this->assertSame([], $this->as($this->ada)->getJson("{$this->host}/me/subjects/{$this->maths->id}")->json('results'));
    }

    public function test_my_subjects_follow_enrolment_and_hide_the_rest(): void
    {
        $subjects = $this->as($this->ada)->getJson("{$this->host}/me/subjects")->assertOk()->json('data');
        $this->assertSame(['English', 'Mathematics'], array_column($subjects, 'name'));
        $this->assertSame(['Mr Chidi'], collect($subjects)->firstWhere('name', 'Mathematics')['teachers']);

        $this->as($this->ada)->getJson("{$this->host}/me/subjects/{$this->french->id}")->assertNotFound();

        // An individually enrolled student sees their enrolment, electives included.
        DB::table('student_subjects')->insert([
            ['school_id' => $this->school->id, 'student_id' => $this->bayo->id, 'subject_id' => $this->maths->id, 'term_id' => null, 'enrollment_type' => 'compulsory', 'status' => 'active'],
            ['school_id' => $this->school->id, 'student_id' => $this->bayo->id, 'subject_id' => $this->french->id, 'term_id' => $this->term->id, 'enrollment_type' => 'elective', 'status' => 'active'],
        ]);
        $bayo = $this->as($this->bayo)->getJson("{$this->host}/me/subjects")->json('data');
        $this->assertSame(['French', 'Mathematics'], array_column($bayo, 'name'));
        $this->assertTrue(collect($bayo)->firstWhere('name', 'French')['elective']);
    }

    public function test_a_cbt_score_shows_only_where_the_exam_releases_it(): void
    {
        $hidden = CbtExam::create(['school_id' => $this->school->id, 'subject_id' => $this->maths->id, 'class_id' => $this->jss1->id, 'title' => 'Mid-term test', 'status' => 'published', 'show_results_immediately' => false]);
        $shown = CbtExam::create(['school_id' => $this->school->id, 'subject_id' => $this->maths->id, 'class_id' => $this->jss1->id, 'title' => 'Quiz 1', 'status' => 'published', 'show_results_immediately' => true]);
        foreach ([$hidden, $shown] as $exam) {
            CbtAttempt::create(['school_id' => $this->school->id, 'exam_id' => $exam->id, 'student_id' => $this->ada->id, 'attempt_number' => 1, 'seed' => 42, 'status' => 'graded', 'percentage' => 80]);
        }

        $tests = collect($this->as($this->ada)->getJson("{$this->host}/me/subjects/{$this->maths->id}")->assertOk()->json('tests'))->keyBy('title');
        $this->assertNull($tests['Mid-term test']['percentage']);
        $this->assertEquals(80, $tests['Quiz 1']['percentage']);
    }

    public function test_the_dashboard_brings_today_together(): void
    {
        $version = DB::table('timetable_versions')->insertGetId(['school_id' => $this->school->id, 'name' => 'First term', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('timetable_entries')->insert([
            ['timetable_version_id' => $version, 'school_class_id' => $this->jss1->id, 'subject_id' => $this->maths->id, 'subject_name' => 'Mathematics', 'teacher_id' => $this->teacher->id, 'day_of_week' => 'Monday', 'slot_name' => 'P1', 'start_time' => '08:00', 'end_time' => '08:40', 'is_break' => false],
            ['timetable_version_id' => $version, 'school_class_id' => $this->jss1->id, 'subject_id' => $this->english->id, 'subject_name' => 'English', 'teacher_id' => null, 'day_of_week' => 'Tuesday', 'slot_name' => 'P1', 'start_time' => '08:00', 'end_time' => '08:40', 'is_break' => false],
        ]);
        foreach (['present', 'present', 'late', 'absent'] as $i => $status) {
            AttendanceRecord::create(['school_id' => $this->school->id, 'term_id' => $this->term->id, 'student_id' => $this->ada->id, 'date' => now()->subDays($i + 1)->toDateString(), 'status' => $status]);
        }
        $due = Homework::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'subject_id' => $this->maths->id, 'teacher_id' => $this->teacher->id, 'title' => 'Fractions', 'description' => 'x', 'due_date' => now()->addDays(2)->toDateString(), 'status' => 'published']);
        $done = Homework::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'subject_id' => $this->maths->id, 'teacher_id' => $this->teacher->id, 'title' => 'Handed in', 'description' => 'x', 'due_date' => now()->addDays(2)->toDateString(), 'status' => 'published']);
        HomeworkSubmission::create(['school_id' => $this->school->id, 'homework_id' => $done->id, 'student_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now(), 'status' => 'submitted']);

        $d = $this->as($this->ada)->getJson("{$this->host}/me/dashboard")->assertOk()->json();
        $this->assertSame('Monday', $d['today']['day']);
        $this->assertSame(['Mathematics'], array_column($d['today']['periods'], 'subject_name'));
        $this->assertEquals(75.0, $d['attendance']['rate']);
        $this->assertSame([$due->id], array_column($d['assignments_due'], 'id'));
        $this->assertSame(2, $d['subjects_count']);
        $this->assertFalse($d['results']['released']);

        $week = $this->as($this->ada)->getJson("{$this->host}/me/timetable")->assertOk()->json('days');
        $this->assertSame(['Monday', 'Tuesday'], array_keys($week));
    }

    public function test_only_students_reach_the_portal(): void
    {
        $this->actingAs($this->teacher, 'sanctum')->getJson("{$this->host}/me/dashboard")->assertForbidden();
        $this->actingAs($this->user('Mrs Obi', 'parent'), 'sanctum')->getJson("{$this->host}/me/results")->assertForbidden();
    }
}
