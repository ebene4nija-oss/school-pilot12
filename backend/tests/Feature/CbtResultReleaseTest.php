<?php

namespace Tests\Feature;

use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\Guardian;
use App\Models\QuestionBankItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Releasing one CBT paper's scores to students and parents, after the
 * sitting. The term's report cards are not part of it.
 */
class CbtResultReleaseTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private User $otherTeacher;
    private User $pupil;
    private User $parent;
    private User $stranger;
    private Student $student;
    private CbtExam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Graceland', 'slug' => 'graceland', 'subdomain' => 'graceland']);
        $this->teacher = $this->person('Teacher', 'teacher');
        $this->otherTeacher = $this->person('Other Teacher', 'teacher');
        $this->pupil = $this->person('Ada Obi', 'student');
        $this->parent = $this->person('Mr Obi', 'parent');
        $this->stranger = $this->person('Mrs Bello', 'parent');
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $this->student = Student::create(['school_id' => $this->school->id, 'user_id' => $this->pupil->id, 'class_id' => $class->id, 'admission_number' => 'G1']);
        Guardian::create(['school_id' => $this->school->id, 'user_id' => $this->parent->id])->students()->attach($this->student->id);
        $subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);

        // Scores are held back on submit: the school releases them later.
        $this->exam = CbtExam::create([
            'school_id' => $this->school->id, 'subject_id' => $subject->id, 'class_id' => $class->id, 'title' => 'Maths CA 1',
            'created_by' => $this->teacher->id, 'duration_minutes' => 30, 'status' => 'published', 'max_attempts' => 1,
            'show_results_immediately' => false, 'closes_at' => now()->addDay(), 'pass_mark' => 50,
        ]);
        $q = QuestionBankItem::create([
            'school_id' => $this->school->id, 'subject_id' => $subject->id, 'class_id' => $class->id, 'question' => '2 + 2',
            'question_type' => 'multiple_choice', 'options' => ['A' => '3', 'B' => '4'], 'correct_answer' => 'B',
            'status' => 'approved', 'marks' => 1, 'created_by' => $this->teacher->id,
        ]);
        CbtExamQuestion::create(['exam_id' => $this->exam->id, 'question_id' => $q->id, 'order_index' => 1]);
    }

    private function person(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@g.test', 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $u->id, 'role' => $role]);

        return $u;
    }

    private function as(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum')->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    private function sit(): int
    {
        $attempt = $this->as($this->pupil)->postJson("/api/v1/cbt/exams/{$this->exam->id}/start")->assertCreated()->json('attempt.id');
        $question = $this->exam->examQuestions()->value('question_id');
        $this->as($this->pupil)->postJson("/api/v1/cbt/attempts/{$attempt}/answers", ['answers' => [['question_id' => $question, 'response' => ['option' => 'B']]]])->assertOk();
        $this->as($this->pupil)->postJson("/api/v1/cbt/attempts/{$attempt}/submit")->assertOk();

        return $attempt;
    }

    public function test_scores_stay_hidden_until_the_teacher_releases_them(): void
    {
        $attempt = $this->sit();

        $this->as($this->pupil)->getJson("/api/v1/cbt/attempts/{$attempt}/result")->assertOk()->assertJsonPath('released', false);
        $this->as($this->parent)->getJson("/api/v1/parent/children/{$this->student->id}/cbt-results")->assertOk()->assertJson(['data' => []]);

        $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$this->exam->id}/release-results")
            ->assertOk()->assertJsonPath('candidates', 1);

        $this->as($this->pupil)->getJson("/api/v1/cbt/attempts/{$attempt}/result")->assertOk()
            ->assertJsonPath('released', true)->assertJsonPath('result.percentage', 100);

        $row = $this->as($this->parent)->getJson("/api/v1/parent/children/{$this->student->id}/cbt-results")->assertOk()->json('data.0');
        $this->assertSame('Maths CA 1', $row['title']);
        $this->assertSame('Mathematics', $row['subject']);
        $this->assertEquals(100, $row['percentage']);
        $this->assertTrue($row['passed']);

        // Student and parent are both told.
        $this->assertSame(2, DB::table('notification_logs')->where('category', 'cbt')->where('channel', 'in_app')->whereIn('user_id', [$this->pupil->id, $this->parent->id])->count());
        // The term's report cards are untouched.
        $this->assertSame(0, DB::table('result_releases')->count());
    }

    public function test_withdrawing_hides_the_scores_again(): void
    {
        $attempt = $this->sit();
        $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$this->exam->id}/release-results")->assertOk();
        $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$this->exam->id}/withdraw-results")->assertOk();

        $this->as($this->pupil)->getJson("/api/v1/cbt/attempts/{$attempt}/result")->assertJsonPath('released', false);
        $this->as($this->parent)->getJson("/api/v1/parent/children/{$this->student->id}/cbt-results")->assertJson(['data' => []]);
    }

    public function test_unmarked_theory_needs_a_deliberate_release(): void
    {
        $attempt = $this->sit();
        CbtAttempt::whereKey($attempt)->update(['requires_manual_grading' => true]);

        $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$this->exam->id}/release-results")
            ->assertStatus(409)->assertJsonPath('code', 'unmarked_scripts')->assertJsonPath('unmarked', 1);

        $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$this->exam->id}/release-results", ['include_unmarked' => true])->assertOk();

        $this->assertTrue($this->as($this->parent)->getJson("/api/v1/parent/children/{$this->student->id}/cbt-results")->json('data.0.provisional'));
    }

    public function test_only_the_setter_or_an_admin_releases_and_only_your_own_child_is_visible(): void
    {
        $this->sit();

        $this->as($this->otherTeacher)->postJson("/api/v1/cbt/exams/{$this->exam->id}/release-results")->assertForbidden();
        $this->as($this->pupil)->postJson("/api/v1/cbt/exams/{$this->exam->id}/release-results")->assertForbidden();

        $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$this->exam->id}/release-results")->assertOk();
        $this->as($this->stranger)->getJson("/api/v1/parent/children/{$this->student->id}/cbt-results")->assertNotFound();
    }

    public function test_nothing_to_release_before_anyone_sits(): void
    {
        $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$this->exam->id}/release-results")->assertStatus(409);
    }
}
