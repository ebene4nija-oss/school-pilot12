<?php

namespace Tests\Feature;

use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\QuestionBankItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a student sees when the paper ends is the teacher's choice, made when
 * the paper is set: the score, their answers, and the correct options — the
 * last only once the paper has closed.
 */
class CbtReviewSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private User $pupil;
    private CbtExam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::create(['name' => 'Graceland', 'slug' => 'graceland', 'subdomain' => 'graceland']);
        $this->teacher = User::create(['name' => 'Teacher', 'email' => 't@g.test', 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);
        $this->pupil = User::create(['name' => 'Ada Obi', 'email' => 'ada@g.test', 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $school->id, 'user_id' => $this->pupil->id, 'role' => 'student']);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 2']);
        Student::create(['school_id' => $school->id, 'user_id' => $this->pupil->id, 'class_id' => $class->id, 'admission_number' => 'G1']);
        $subject = Subject::create(['school_id' => $school->id, 'name' => 'Mathematics']);

        $this->exam = CbtExam::create([
            'school_id' => $school->id, 'subject_id' => $subject->id, 'class_id' => $class->id, 'title' => 'CA 1',
            'created_by' => $this->teacher->id, 'duration_minutes' => 30, 'status' => 'published', 'max_attempts' => 1,
            'show_results_immediately' => true, 'closes_at' => now()->addDay(),
        ]);
        $q = QuestionBankItem::create([
            'school_id' => $school->id, 'subject_id' => $subject->id, 'class_id' => $class->id, 'question' => '2 + 2',
            'question_type' => 'multiple_choice', 'options' => ['A' => '3', 'B' => '4'], 'correct_answer' => 'B',
            'status' => 'approved', 'marks' => 1, 'created_by' => $this->teacher->id,
        ]);
        CbtExamQuestion::create(['exam_id' => $this->exam->id, 'question_id' => $q->id, 'order_index' => 1]);
    }

    private function as(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum')->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    /** Sit the paper (one wrong answer) and return the student's result. */
    private function sitAndRead(): array
    {
        $attempt = $this->as($this->pupil)->postJson("/api/v1/cbt/exams/{$this->exam->id}/start")->assertCreated()->json('attempt.id');
        $question = $this->exam->examQuestions()->value('question_id');
        $this->as($this->pupil)->postJson("/api/v1/cbt/attempts/{$attempt}/answers", ['answers' => [['question_id' => $question, 'response' => ['option' => 'A']]]])->assertOk();
        $this->as($this->pupil)->postJson("/api/v1/cbt/attempts/{$attempt}/submit")->assertOk();

        return $this->as($this->pupil)->getJson("/api/v1/cbt/attempts/{$attempt}/result")->assertOk()->json();
    }

    public function test_by_default_a_student_sees_their_answers_but_not_the_correct_options()
    {
        $res = $this->sitAndRead();

        $this->assertSame(0.0, (float) $res['result']['raw_score']);
        $this->assertSame(['option' => 'A'], $res['result']['questions'][0]['your_response']);
        $this->assertArrayNotHasKey('correct_answer', $res['result']['questions'][0]);
        $this->assertFalse($res['result']['review']['correct_answers']);
    }

    public function test_answers_can_be_hidden_leaving_only_the_score()
    {
        $this->exam->update(['review_answers' => false]);

        $res = $this->sitAndRead();

        $this->assertArrayHasKey('percentage', $res['result']);
        $this->assertArrayNotHasKey('questions', $res['result']);
    }

    public function test_correct_options_wait_until_the_paper_closes()
    {
        $this->exam->update(['review_correct_answers' => true]);

        $open = $this->sitAndRead();
        $this->assertArrayNotHasKey('correct_answer', $open['result']['questions'][0]);
        $this->assertTrue($open['result']['review']['correct_answers_after_close']);

        $this->exam->update(['closes_at' => now()->subMinute()]);
        $attempt = $open['result']['attempt_id'];
        $closed = $this->as($this->pupil)->getJson("/api/v1/cbt/attempts/{$attempt}/result")->assertOk()->json('result');

        $this->assertSame('B', $closed['questions'][0]['correct_answer']);
        $this->assertSame(['A' => '3', 'B' => '4'], $closed['questions'][0]['options']);
    }

    public function test_the_choices_are_made_when_the_paper_is_set_and_can_change_after_publishing()
    {
        $subject = $this->exam->subject_id;

        $created = $this->as($this->teacher)->postJson('/api/v1/cbt/exams', [
            'subject_id' => $subject, 'title' => 'CA 2', 'duration_minutes' => 20,
            'shuffle_questions' => true, 'shuffle_options' => true,
            'show_results_immediately' => false, 'review_answers' => false, 'review_correct_answers' => true,
        ])->assertCreated()->json('exam');

        $this->assertFalse($created['review_answers']);
        $this->assertTrue($created['review_correct_answers']);

        $this->as($this->teacher)->putJson("/api/v1/cbt/exams/{$this->exam->id}", ['review_correct_answers' => true])->assertOk();
    }
}
