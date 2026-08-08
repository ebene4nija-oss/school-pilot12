<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AttendanceRecord;
use App\Models\CbtExam;
use App\Models\Homework;
use App\Models\Invoice;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Doc §6 role dashboards, plus the §7.14 rule-based insight alerts.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private SchoolClass $class;
    private Subject $maths;
    private Subject $english;
    private Term $termOne;
    private Term $termTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Riverside School',
            'slug' => 'riverside',
            'subdomain' => 'riverside',
            'domain' => 'riverside.schoolpilot.test',
        ]);

        $this->admin = $this->user('Principal', 'principal@riverside.test', 'school_admin');
        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 3']);
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);
        $this->english = Subject::create(['school_id' => $this->school->id, 'name' => 'English']);

        $session = AcademicSession::create([
            'school_id' => $this->school->id,
            'name' => '2025/2026',
            'start_date' => now()->subYear(),
            'end_date' => now()->addMonths(3),
        ]);

        $this->termOne = Term::create([
            'school_id' => $this->school->id, 'session_id' => $session->id,
            'name' => 'First Term', 'start_date' => now()->subMonths(6), 'end_date' => now()->subMonths(3),
        ]);

        $this->termTwo = Term::create([
            'school_id' => $this->school->id, 'session_id' => $session->id,
            'name' => 'Second Term', 'start_date' => now()->subMonths(3), 'end_date' => now(),
        ]);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function student(string $name, string $admission): Student
    {
        $user = $this->user($name, strtolower(str_replace(' ', '', $name)) . '@riverside.test', 'student');

        return Student::create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'class_id' => $this->class->id,
            'admission_number' => $admission,
            'status' => 'active',
        ]);
    }

    private function score(Student $student, Subject $subject, Term $term, float $total): void
    {
        ScoreEntry::create([
            'school_id' => $this->school->id,
            'term_id' => $term->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'first_ca' => 0, 'second_ca' => 0, 'exam' => 0,
            'total_score' => $total,
            'grade' => 'C4',
        ]);
    }

    // ------------------------------------------------------------------
    // Teacher dashboard
    // ------------------------------------------------------------------

    /**
     * The core bug: `$teacherId` was assigned and never used, so every teacher
     * saw identical school-wide figures including subjects they do not teach.
     */
    public function test_teacher_dashboard_only_reports_subjects_that_teacher_teaches()
    {
        $mathsTeacher = $this->user('Maths Teacher', 'maths@riverside.test', 'teacher');

        DB::table('teacher_subjects')->insert([
            'school_id' => $this->school->id,
            'teacher_id' => $mathsTeacher->id,
            'subject_id' => $this->maths->id,
            'class_id' => $this->class->id,
            'is_primary' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $student = $this->student('Ada Test', 'ADM200');
        $this->score($student, $this->maths, $this->termTwo, 40);
        $this->score($student, $this->english, $this->termTwo, 90);

        $response = $this->actingAs($mathsTeacher, 'sanctum')
            ->getJson('http://riverside.schoolpilot.test/api/v1/analytics/teacher')
            ->assertStatus(200);

        $subjects = collect($response->json('weak_subjects_and_topics'))->pluck('subject_name');

        $this->assertTrue($subjects->contains('Mathematics'));
        $this->assertFalse(
            $subjects->contains('English'),
            'English is not taught by this teacher and must not appear on their dashboard.'
        );
        $this->assertSame(['Mathematics'], $response->json('my_subjects'));
    }

    /**
     * "top_improving_students" used to be ordered by highest average, not by
     * improvement — so the panel named the wrong children.
     */
    public function test_top_improving_students_measures_improvement_not_highest_average()
    {
        $teacher = $this->user('Teacher Two', 'teacher2@riverside.test', 'teacher');

        DB::table('teacher_subjects')->insert([
            'school_id' => $this->school->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $this->maths->id,
            'class_id' => $this->class->id,
            'is_primary' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Consistently excellent, but flat: 90 -> 91.
        $steady = $this->student('Steady High', 'ADM210');
        $this->score($steady, $this->maths, $this->termOne, 90);
        $this->score($steady, $this->maths, $this->termTwo, 91);

        // Big climb from a low base: 40 -> 75.
        $climber = $this->student('Big Climber', 'ADM211');
        $this->score($climber, $this->maths, $this->termOne, 40);
        $this->score($climber, $this->maths, $this->termTwo, 75);

        $improving = $this->actingAs($teacher, 'sanctum')
            ->getJson('http://riverside.schoolpilot.test/api/v1/analytics/teacher')
            ->assertStatus(200)
            ->json('top_improving_students');

        $this->assertSame('Big Climber', $improving[0]['student_name']);
        $this->assertEquals(35.0, $improving[0]['change']);
    }

    public function test_teacher_dashboard_reports_their_own_homework_due()
    {
        $teacher = $this->user('Teacher Three', 'teacher3@riverside.test', 'teacher');
        $other = $this->user('Teacher Four', 'teacher4@riverside.test', 'teacher');

        Homework::create([
            'school_id' => $this->school->id, 'class_id' => $this->class->id,
            'subject_id' => $this->maths->id, 'teacher_id' => $teacher->id,
            'title' => 'My worksheet', 'description' => 'x', 'due_date' => now()->addDays(2),
        ]);

        Homework::create([
            'school_id' => $this->school->id, 'class_id' => $this->class->id,
            'subject_id' => $this->maths->id, 'teacher_id' => $other->id,
            'title' => 'Someone elses worksheet', 'description' => 'x', 'due_date' => now()->addDays(2),
        ]);

        $titles = collect($this->actingAs($teacher, 'sanctum')
            ->getJson('http://riverside.schoolpilot.test/api/v1/analytics/teacher')
            ->json('homework_due'))->pluck('title');

        $this->assertTrue($titles->contains('My worksheet'));
        $this->assertFalse($titles->contains('Someone elses worksheet'));
    }

    // ------------------------------------------------------------------
    // Student dashboard
    // ------------------------------------------------------------------

    /** §6 requires timetable, homework, attendance, badges and upcoming tests. */
    public function test_student_dashboard_includes_the_panels_the_spec_requires()
    {
        $student = $this->student('Dash Student', 'ADM220');
        $teacher = $this->user('Teacher Five', 'teacher5@riverside.test', 'teacher');

        Homework::create([
            'school_id' => $this->school->id, 'class_id' => $this->class->id,
            'subject_id' => $this->maths->id, 'teacher_id' => $teacher->id,
            'title' => 'Algebra practice', 'description' => 'x', 'due_date' => now()->addDay(),
        ]);

        AttendanceRecord::create([
            'school_id' => $this->school->id,
            'term_id' => $this->termTwo->id,
            'student_id' => $student->id,
            'date' => now()->toDateString(),
            'status' => 'present',
        ]);

        CbtExam::create([
            'school_id' => $this->school->id,
            'subject_id' => $this->maths->id,
            'class_id' => $this->class->id,
            'title' => 'Mid-term test',
            'duration_minutes' => 30,
            'status' => 'published',
            'created_by' => $teacher->id,
        ]);

        $response = $this->actingAs($student->user, 'sanctum')
            ->getJson('http://riverside.schoolpilot.test/api/v1/analytics/student')
            ->assertStatus(200);

        $response->assertJsonStructure([
            'todays_timetable',
            'homework_due',
            'attendance_last_30_days' => ['days_marked', 'days_present', 'percentage'],
            'upcoming_tests',
            'badges',
            'points',
            'topics_to_work_on',
        ]);

        $this->assertSame('Algebra practice', $response->json('homework_due.0.title'));
        $this->assertSame('Mid-term test', $response->json('upcoming_tests.0.title'));
        $this->assertEquals(100.0, $response->json('attendance_last_30_days.percentage'));
    }

    // ------------------------------------------------------------------
    // Principal dashboard + §7.14 insights
    // ------------------------------------------------------------------

    public function test_principal_dashboard_reports_population_cbt_and_pending_approvals()
    {
        $this->student('Pupil One', 'ADM230');
        $this->student('Pupil Two', 'ADM231');

        $student = $this->student('Pupil Three', 'ADM232');
        ScoreEntry::create([
            'school_id' => $this->school->id, 'term_id' => $this->termTwo->id,
            'student_id' => $student->id, 'subject_id' => $this->maths->id,
            'first_ca' => 0, 'second_ca' => 0, 'exam' => 0, 'total_score' => 50,
            'ai_comment_status' => 'pending_approval',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('http://riverside.schoolpilot.test/api/v1/analytics/principal')
            ->assertStatus(200);

        $this->assertSame(3, $response->json('population.active'));
        $this->assertSame(1, $response->json('pending_approvals.ai_comments_awaiting_review'));
        $response->assertJsonStructure(['cbt' => ['exams_published', 'attempts_sat', 'awaiting_marking']]);
    }

    /** §7.14 grade-drop flag. */
    public function test_grade_drop_alert_fires_on_a_material_fall()
    {
        $dropper = $this->student('Falling Student', 'ADM240');
        $this->score($dropper, $this->maths, $this->termOne, 85);
        $this->score($dropper, $this->maths, $this->termTwo, 55);

        $steady = $this->student('Steady Student', 'ADM241');
        $this->score($steady, $this->maths, $this->termOne, 70);
        $this->score($steady, $this->maths, $this->termTwo, 68);

        $alerts = $this->actingAs($this->admin, 'sanctum')
            ->getJson('http://riverside.schoolpilot.test/api/v1/analytics/principal')
            ->json('insights.grade_drops');

        $names = collect($alerts)->pluck('student_name');

        $this->assertTrue($names->contains('Falling Student'));
        $this->assertFalse($names->contains('Steady Student'), 'A 2-point wobble is not a grade drop.');
        $this->assertEquals(-30.0, collect($alerts)->firstWhere('student_name', 'Falling Student')['change']);
    }

    /** §7.14 attrition risk needs two signals, not one. */
    public function test_attrition_risk_requires_more_than_one_signal()
    {
        // Fees owing only — one signal, should not fire.
        $lateOnly = $this->student('Late Payer', 'ADM250');
        Invoice::create([
            'school_id' => $this->school->id, 'term_id' => $this->termTwo->id,
            'student_id' => $lateOnly->id, 'invoice_number' => 'INV-1',
            'total_amount' => 50000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);

        // Fees owing AND poor attendance — two signals, should fire.
        $atRisk = $this->student('At Risk', 'ADM251');
        Invoice::create([
            'school_id' => $this->school->id, 'term_id' => $this->termTwo->id,
            'student_id' => $atRisk->id, 'invoice_number' => 'INV-2',
            'total_amount' => 50000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);

        foreach (range(1, 10) as $day) {
            AttendanceRecord::create([
                'school_id' => $this->school->id,
                'term_id' => $this->termTwo->id,
                'student_id' => $atRisk->id,
                'date' => now()->subDays($day)->toDateString(),
                'status' => $day <= 8 ? 'absent' : 'present',
            ]);
        }

        $risks = $this->actingAs($this->admin, 'sanctum')
            ->getJson('http://riverside.schoolpilot.test/api/v1/analytics/principal')
            ->json('insights.attrition_risk');

        $names = collect($risks)->pluck('student_name');

        $this->assertTrue($names->contains('At Risk'));
        $this->assertFalse($names->contains('Late Payer'), 'Owing fees alone is too common to be an attrition signal.');
    }

    public function test_revenue_forecast_reports_naira_and_collection_rate()
    {
        $student = $this->student('Paying Student', 'ADM260');

        Invoice::create([
            'school_id' => $this->school->id, 'term_id' => $this->termTwo->id,
            'student_id' => $student->id, 'invoice_number' => 'INV-10',
            'total_amount' => 100000, 'amount_paid' => 25000, 'status' => 'partial',
        ]);

        $forecast = $this->actingAs($this->admin, 'sanctum')
            ->getJson('http://riverside.schoolpilot.test/api/v1/analytics/principal')
            ->json('insights.revenue_forecast');

        $this->assertSame('NGN', $forecast['currency']);
        $this->assertEquals(100000.0, $forecast['total_invoiced']);
        $this->assertEquals(100000.0, $forecast['outstanding']);
    }
}
