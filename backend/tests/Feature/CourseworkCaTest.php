<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
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

/** A CA filled from homework and quizzes, adjusted by the teacher (TASK 08, 21). */
class CourseworkCaTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private SchoolClass $class;
    private Subject $maths;
    private Term $term;
    private Student $ada;
    private Student $tunde;
    private Homework $hw;
    private CbtExam $quiz;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31', 'is_current' => true]);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-07', 'end_date' => '2026-12-11', 'is_current' => true]);
        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);
        $this->teacher = $this->user('Mr Chidi', 'teacher');
        DB::table('teacher_subjects')->insert(['school_id' => $this->school->id, 'teacher_id' => $this->teacher->id, 'subject_id' => $this->maths->id, 'class_id' => $this->class->id]);
        $this->ada = $this->student('Ada Obi', 'A1');
        $this->tunde = $this->student('Tunde Bello', 'A2');

        $this->hw = Homework::create(['school_id' => $this->school->id, 'class_id' => $this->class->id, 'subject_id' => $this->maths->id, 'teacher_id' => $this->teacher->id, 'title' => 'Fractions worksheet', 'description' => 'Questions 1-10', 'due_date' => '2026-10-01', 'max_score' => 10, 'status' => 'published']);
        HomeworkSubmission::create(['school_id' => $this->school->id, 'homework_id' => $this->hw->id, 'student_id' => $this->ada->id, 'submitted_at' => now(), 'marks_awarded' => 8, 'status' => 'graded']);
        $this->quiz = CbtExam::create(['school_id' => $this->school->id, 'subject_id' => $this->maths->id, 'class_id' => $this->class->id, 'term_id' => $this->term->id, 'title' => 'Quiz 1', 'duration_minutes' => 20, 'status' => 'published', 'max_attempts' => 1, 'created_by' => $this->teacher->id]);
        foreach ([[$this->ada, 60], [$this->tunde, 50]] as [$s, $pct]) {
            CbtAttempt::create(['school_id' => $this->school->id, 'exam_id' => $this->quiz->id, 'student_id' => $s->id, 'attempt_number' => 1, 'seed' => 1, 'status' => 'graded', 'percentage' => $pct]);
        }
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function student(string $name, string $no): Student
    {
        return Student::create(['school_id' => $this->school->id, 'user_id' => $this->user($name, 'student')->id, 'class_id' => $this->class->id, 'admission_number' => $no, 'status' => 'active']);
    }

    private function scope(array $extra = []): array
    {
        return ['term_id' => $this->term->id, 'class_id' => $this->class->id, 'subject_id' => $this->maths->id] + $extra;
    }

    private function suggest(string $missing = 'zero')
    {
        return $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/assessment/coursework-ca/suggest", $this->scope([
            'component' => 'ca1', 'homework_ids' => [$this->hw->id], 'exam_ids' => [$this->quiz->id], 'missing' => $missing,
        ]));
    }

    public function test_the_teacher_sees_the_term_s_graded_coursework(): void
    {
        $res = $this->actingAs($this->teacher, 'sanctum')->getJson("{$this->host}/assessment/coursework-ca/sources?".http_build_query($this->scope()))->assertOk();
        $this->assertSame(1, $res->json('homework.0.graded'));
        $this->assertSame(2, $res->json('quizzes.0.graded'));
    }

    public function test_suggested_marks_scale_to_the_ca_and_missing_work_follows_the_teacher_s_choice(): void
    {
        $rows = collect($this->suggest()->assertOk()->assertJsonPath('part.max', 20)->json('rows'))->keyBy('admission_number');
        $this->assertEquals(14, $rows['A1']['suggested']); // (80% + 60%) / 2 of 20
        $this->assertEquals(5, $rows['A2']['suggested'], 'Homework not handed in counts as 0.');

        $rows = collect($this->suggest('skip')->json('rows'))->keyBy('admission_number');
        $this->assertEquals(10, $rows['A2']['suggested'], 'Left out: the quiz alone.');
    }

    public function test_work_handed_in_but_not_marked_is_never_counted_as_zero(): void
    {
        HomeworkSubmission::create(['school_id' => $this->school->id, 'homework_id' => $this->hw->id, 'student_id' => $this->tunde->id, 'submitted_at' => now(), 'status' => 'submitted']);
        $row = collect($this->suggest()->json('rows'))->firstWhere('admission_number', 'A2');

        $this->assertEquals(10, $row['suggested']);
        $this->assertSame(1, $row['unmarked']);
    }

    public function test_the_teacher_s_adjusted_marks_are_entered_and_recorded(): void
    {
        $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/assessment/coursework-ca/apply", $this->scope([
            'component' => 'ca1', 'homework_ids' => [$this->hw->id], 'exam_ids' => [$this->quiz->id],
            'rows' => [
                ['student_id' => $this->ada->id, 'suggested' => 14, 'score' => 14],
                ['student_id' => $this->tunde->id, 'suggested' => 5, 'score' => 9], // excused the missed homework
            ],
        ]))->assertOk()->assertJsonPath('saved', 2);

        $this->assertEquals(9, ScoreEntry::where('student_id', $this->tunde->id)->first()->component_scores['ca1']);
        $this->assertEquals(14, ScoreEntry::where('student_id', $this->ada->id)->value('first_ca'));
        $import = DB::table('ca_coursework_imports')->first();
        $this->assertSame(1, (int) $import->adjusted_count);

        // The next suggestion shows what is already on the score sheet.
        $this->assertEquals(9, collect($this->suggest()->json('rows'))->firstWhere('admission_number', 'A2')['current']);
    }

    public function test_marks_over_the_ca_maximum_and_exam_parts_are_refused(): void
    {
        $res = $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/assessment/coursework-ca/apply", $this->scope([
            'component' => 'ca1', 'rows' => [['student_id' => $this->ada->id, 'score' => 25]],
        ]))->assertOk();
        $this->assertSame(0, $res->json('saved'));
        $this->assertSame('1st CA is marked out of 20.', $res->json('refused.0.reason'));

        $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/assessment/coursework-ca/suggest", $this->scope(['component' => 'exam', 'exam_ids' => [$this->quiz->id]]))
            ->assertStatus(409)->assertJsonPath('message', 'Only a CA can be filled from coursework; the exam is entered directly.');
    }

    public function test_only_the_subject_teacher_fills_the_ca(): void
    {
        $this->actingAs($this->user('Mrs Bello', 'teacher'), 'sanctum')->postJson("{$this->host}/assessment/coursework-ca/suggest", $this->scope(['component' => 'ca1', 'exam_ids' => [$this->quiz->id]]))->assertForbidden();
    }
}
