<?php

namespace Tests\Feature;

use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\CbtQuestionVersion;
use App\Models\CbtResultRevision;
use App\Models\CbtSetting;
use App\Models\NotificationLog;
use App\Models\QuestionBankItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SectionHeadAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\CbtExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * CBT oversight: question versions, result revisions, regrades with preview
 * and rollback, approval, the live monitor and interventions, analysis.
 * Acceptance test 20 of the CBT instructions in particular.
 */
class CbtOversightTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private User $admin;
    private SchoolClass $class;
    private Subject $subject;
    private CbtExam $exam;
    private QuestionBankItem $q1;
    private QuestionBankItem $q2;
    /** @var array<int, Student> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://graceland.localhost');

        $this->school = School::create(['name' => 'Graceland', 'slug' => 'graceland', 'subdomain' => 'graceland']);
        $this->teacher = $this->user('Mrs Adeyemi', 'teacher');
        $this->admin = $this->user('Admin', 'school_admin');
        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2', 'level_category' => 'junior_secondary']);
        $this->subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'department' => 'Sciences']);

        $this->q1 = $this->question('What is 2 + 2?', 'B');
        $this->q2 = $this->question('What is 3 + 3?', 'C');

        $this->exam = CbtExam::create([
            'school_id' => $this->school->id, 'subject_id' => $this->subject->id, 'class_id' => $this->class->id,
            'title' => 'Weekly test', 'duration_minutes' => 30, 'status' => 'published', 'shuffle_questions' => false,
            'shuffle_options' => false, 'max_attempts' => 1, 'pass_mark' => 50, 'created_by' => $this->teacher->id,
        ]);

        foreach ([$this->q1, $this->q2] as $i => $q) {
            CbtExamQuestion::create(['exam_id' => $this->exam->id, 'question_id' => $q->id, 'order_index' => $i + 1]);
        }

        // Three students: right/right, right/wrong ("A" on q2), wrong/wrong.
        foreach ([['B', 'C'], ['B', 'A'], ['A', 'A']] as $i => [$a1, $a2]) {
            $user = $this->user("Student {$i}", 'student');
            $student = Student::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'class_id' => $this->class->id, 'admission_number' => "GC/{$i}", 'status' => 'active']);
            $this->students[] = $student;

            $service = app(CbtExamService::class);
            $attempt = $service->startAttempt($this->exam, $student->id);
            $service->recordAnswers($attempt, [
                ['question_id' => $this->q1->id, 'response' => ['option' => $a1], 'client_sequence' => 1],
                ['question_id' => $this->q2->id, 'response' => ['option' => $a2], 'client_sequence' => 2],
            ]);
            $this->inSchool($attempt->school_id, fn () => $service->submitAttempt($attempt->fresh()));
        }
    }

    private function user(string $name, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@graceland.test', 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function question(string $text, string $key): QuestionBankItem
    {
        return QuestionBankItem::create([
            'school_id' => $this->school->id, 'subject_id' => $this->subject->id, 'topic' => 'Arithmetic', 'question' => $text,
            'question_type' => 'multiple_choice', 'options' => ['A' => '1', 'B' => '4', 'C' => '6', 'D' => '8'],
            'correct_answer' => $key, 'marks' => 1, 'status' => 'approved',
        ]);
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum')->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    private function percentages(): array
    {
        return CbtAttempt::orderBy('id')->pluck('percentage')->map(fn ($p) => (float) $p)->all();
    }

    public function test_every_submission_writes_revision_one_pinned_to_the_question_version(): void
    {
        $this->inSchool($this->school->id, function () {
            $this->assertSame([100.0, 50.0, 0.0], $this->percentages());
            $this->assertSame(3, CbtResultRevision::count());
            $this->assertSame([1], CbtResultRevision::pluck('revision')->unique()->values()->all());
            $this->assertSame(1, (int) \App\Models\CbtAttemptAnswer::first()->question_version);
        });
    }

    public function test_editing_an_answered_question_versions_it_and_changes_no_result(): void
    {
        $this->exam->update(['status' => 'closed']);

        $this->as($this->teacher)->putJson("/api/v1/cbt/questions/{$this->q2->id}", ['correct_answer' => 'A'])
            ->assertOk()->assertJsonPath('versioning.affects_scoring', true)->assertJsonPath('versioning.version', 2);

        $this->assertSame([100.0, 50.0, 0.0], $this->percentages(), 'Editing never changes results silently.');
        $this->assertSame(2, CbtQuestionVersion::where('question_id', $this->q2->id)->count());

        $stale = $this->as($this->admin)->getJson("/api/v1/cbt/exams/{$this->exam->id}/analysis")->assertOk()->json('stale_results');
        $this->assertSame(3, $stale[0]['attempts'], 'Results show as marked on an older version.');
    }

    /** Acceptance test 20. */
    public function test_regrade_previews_applies_as_a_new_revision_is_idempotent_and_rolls_back(): void
    {
        $body = ['question_id' => $this->q2->id, 'action' => 'accept_answers', 'params' => ['correct_answers' => ['C', 'A']]];

        $preview = $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$this->exam->id}/regrade/preview", $body)->assertOk()->json();
        $this->assertSame(2, $preview['up']);
        $this->assertSame(1, $preview['unchanged']);
        $this->assertSame([100.0, 50.0, 0.0], $this->percentages(), 'A preview changes nothing.');

        // A reason is required.
        $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$this->exam->id}/regrade", $body)->assertStatus(422);

        $job = $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$this->exam->id}/regrade", $body + ['reason' => 'Option A is also correct as printed.'])
            ->assertOk()->json('job');
        $this->assertSame([100.0, 100.0, 50.0], $this->percentages());

        $attempt = CbtAttempt::orderBy('id')->skip(1)->first();
        $this->assertSame([1, 2], CbtResultRevision::where('attempt_id', $attempt->id)->orderBy('revision')->pluck('revision')->all(), 'The old revision is kept.');

        // Only students whose scores changed are notified.
        $this->assertSame(2, NotificationLog::where('category', 'cbt_regrade')->count());

        // Running it twice gives the same result.
        $again = $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$this->exam->id}/regrade", $body + ['reason' => 'Option A is also correct as printed.'])->json('job');
        $this->assertSame($job['id'], $again['id']);
        $this->assertSame([100.0, 100.0, 50.0], $this->percentages());

        $this->as($this->teacher)->postJson("/api/v1/cbt/regrades/{$job['id']}/rollback", ['reason' => 'Committee decided A is wrong.'])->assertOk();
        $this->assertSame([100.0, 50.0, 0.0], $this->percentages(), 'Rollback restores the previous figures.');
        $this->assertSame(3, CbtResultRevision::where('attempt_id', $attempt->id)->count());
    }

    public function test_dropping_a_question_rescales_and_after_close_only_an_admin_may_regrade(): void
    {
        $this->exam->update(['status' => 'closed']);
        $body = ['question_id' => $this->q2->id, 'action' => 'drop', 'reason' => 'Question 2 was misprinted.'];

        $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$this->exam->id}/regrade", $body)->assertForbidden();
        $this->as($this->admin)->postJson("/api/v1/cbt/exams/{$this->exam->id}/regrade", $body)->assertOk();

        $this->assertSame([100.0, 100.0, 0.0], $this->percentages());
        $this->assertSame(1.0, (float) CbtAttempt::first()->max_score);
    }

    public function test_publishing_waits_for_approval_when_the_school_requires_it(): void
    {
        CbtSetting::create(['school_id' => $this->school->id, 'exam_approval_required' => true]);
        $hod = $this->user('Head of Sciences', 'teacher');
        SectionHeadAssignment::create(['school_id' => $this->school->id, 'position' => 'hod', 'department' => 'Sciences', 'user_id' => $hod->id, 'started_at' => now()]);

        $draft = CbtExam::create(['school_id' => $this->school->id, 'subject_id' => $this->subject->id, 'class_id' => $this->class->id, 'title' => 'Mid-term', 'duration_minutes' => 40, 'status' => 'draft', 'max_attempts' => 1, 'created_by' => $this->teacher->id]);
        CbtExamQuestion::create(['exam_id' => $draft->id, 'question_id' => $this->q1->id, 'order_index' => 1]);

        $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$draft->id}/publish")->assertStatus(409)->assertJsonPath('code', 'approval_required');

        $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$draft->id}/submit-for-approval")->assertOk();
        $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$draft->id}/approval", ['approve' => true])->assertForbidden();
        $this->as($hod)->postJson("/api/v1/cbt/exams/{$draft->id}/approval", ['approve' => true])->assertOk();

        $this->as($this->teacher)->postJson("/api/v1/cbt/exams/{$draft->id}/publish")->assertOk();
    }

    public function test_the_live_monitor_and_logged_interventions(): void
    {
        $user = $this->user('Late Student', 'student');
        $late = Student::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'class_id' => $this->class->id, 'admission_number' => 'GC/9', 'status' => 'active']);
        $absent = Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('Absent Student', 'student')->id, 'class_id' => $this->class->id, 'admission_number' => 'GC/10', 'status' => 'active']);

        $attempt = app(CbtExamService::class)->startAttempt($this->exam, $late->id);
        $deadline = $attempt->server_deadline_at;

        $monitor = $this->as($this->teacher)->getJson("/api/v1/cbt/exams/{$this->exam->id}/monitor")->assertOk()->json();
        $this->assertSame(3, $monitor['counts']['submitted']);
        $this->assertSame(1, $monitor['counts']['in_progress']);
        $this->assertSame(1, $monitor['counts']['not_started']);

        $this->as($this->teacher)->postJson("/api/v1/cbt/attempts/{$attempt->id}/extend", ['minutes' => 10])->assertStatus(422);
        $this->as($this->teacher)->postJson("/api/v1/cbt/attempts/{$attempt->id}/extend", ['minutes' => 10, 'reason' => 'Power cut in lab 2'])->assertOk();
        $this->assertEquals($deadline->copy()->addMinutes(10)->timestamp, $attempt->fresh()->server_deadline_at->timestamp);

        $this->as($this->teacher)->postJson("/api/v1/cbt/attempts/{$attempt->id}/reset", ['reason' => 'Machine crashed'])->assertForbidden();
        $this->as($this->teacher)->postJson("/api/v1/cbt/attempts/{$attempt->id}/force-submit", ['reason' => 'Caught using a phone'])->assertOk();
        $this->assertSame('graded', $attempt->fresh()->status);

        $this->assertTrue(\App\Models\CbtAttemptEvent::where('attempt_id', $attempt->id)->where('event_type', 'time_extended')->exists());

        $analysis = $this->as($this->teacher)->getJson("/api/v1/cbt/exams/{$this->exam->id}/analysis")->assertOk()->json();
        $this->assertSame(1, $analysis['attendance']['absent']);
        $this->assertSame($absent->id, $analysis['attendance']['absent_students'][0]['id']);
        $q2 = collect($analysis['questions'])->firstWhere('question_id', $this->q2->id);
        $this->assertSame(['C' => 1, 'A' => 2], $q2['option_counts']);
    }
}
