<?php

namespace Tests\Feature;

use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\CbtQuestionGroup;
use App\Models\QuestionBankItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\CbtExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Grouped questions — docs/offline-cbt-client.md §6.2 and §6.6.
 *
 * One shared stimulus, several sub-questions. Most of what can go wrong here
 * goes wrong quietly: a passage's sub-questions scattered across a shuffled
 * paper still renders, still submits, and is simply unanswerable. So the
 * assertions that matter are about *contiguity* and *wholeness*, not about
 * whether the endpoint returns 200.
 */
class CbtQuestionGroupTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private User $studentUser;
    private Student $student;
    private Subject $subject;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://graceland.localhost');

        $this->school = School::create([
            'name' => 'Graceland College', 'slug' => 'graceland', 'subdomain' => 'graceland',
        ]);

        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $this->subject = Subject::create(['school_id' => $this->school->id, 'name' => 'English', 'code' => 'ENG']);

        $this->teacher = $this->user('Mrs Adeyemi', 'adeyemi@graceland.test', 'teacher');
        $this->studentUser = $this->user('Adaeze Nwosu', 'adaeze@graceland.test', 'student');

        $this->student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $this->studentUser->id,
            'class_id' => $this->class->id,
            'admission_number' => 'GC/2026/001',
        ]);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function asTeacher()
    {
        return $this->actingAs($this->teacher, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    private function group(array $overrides = []): CbtQuestionGroup
    {
        return CbtQuestionGroup::create(array_merge([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'title' => 'Passage 2 — The Harmattan',
            'stimulus' => 'The harmattan is a dry, dusty wind that blows from the Sahara...',
            'instructions' => 'Read the passage below and answer the questions that follow.',
        ], $overrides));
    }

    private function question(array $overrides = []): QuestionBankItem
    {
        return QuestionBankItem::create(array_merge([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'question' => 'What is the passage about?',
            'question_type' => 'multiple_choice',
            'options' => ['A' => 'Wind', 'B' => 'Rain', 'C' => 'Sun', 'D' => 'Snow'],
            'correct_answer' => 'A',
            'marks' => 2,
            'status' => 'approved',
        ], $overrides));
    }

    private function exam(array $overrides = []): CbtExam
    {
        return CbtExam::create(array_merge([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'class_id' => $this->class->id,
            'title' => 'JSS 2 English Mock',
            'duration_minutes' => 60,
            'status' => 'published',
            'opens_at' => now()->subMinutes(5),
            'closes_at' => now()->addHours(3),
            'shuffle_questions' => true,
            'shuffle_options' => false,
            'max_attempts' => 1,
            'created_by' => $this->teacher->id,
        ], $overrides));
    }

    private function attach(CbtExam $exam, array $questions): void
    {
        $index = 0;
        foreach ($questions as $question) {
            CbtExamQuestion::create([
                'exam_id' => $exam->id,
                'question_id' => $question->id,
                'order_index' => ++$index,
            ]);
        }
    }

    /**
     * A comprehension passage with six sub-questions, four objectives around
     * it, and a hundred different seeds. If a single seed splits the passage,
     * a room of candidates meets question (d) before question (a).
     */
    public function test_a_grouped_paper_never_splits_a_passage(): void
    {
        $group = $this->group();
        $exam = $this->exam();

        $grouped = [];
        for ($i = 1; $i <= 6; $i++) {
            $grouped[] = $this->question(['group_id' => $group->id, 'group_sequence' => $i, 'question' => "Sub-question {$i}"]);
        }

        $standalone = [];
        for ($i = 1; $i <= 4; $i++) {
            $standalone[] = $this->question(['question' => "Objective {$i}"]);
        }

        // Interleaved on attach, so contiguity cannot come from the authoring
        // order by accident.
        $this->attach($exam, [
            $standalone[0], $grouped[0], $grouped[1], $standalone[1],
            $grouped[2], $grouped[3], $standalone[2], $grouped[4],
            $grouped[5], $standalone[3],
        ]);

        $service = app(CbtExamService::class);
        $groupedIds = collect($grouped)->pluck('id')->all();

        for ($seed = 1; $seed <= 100; $seed++) {
            $order = $service->composePaper($exam, $seed);

            $this->assertCount(10, $order, "Seed {$seed} lost or duplicated questions.");

            $positions = [];
            foreach ($order as $position => $questionId) {
                if (in_array($questionId, $groupedIds, true)) {
                    $positions[] = $position;
                }
            }

            $this->assertSame(
                range(min($positions), min($positions) + 5),
                $positions,
                "Seed {$seed} scattered the passage's sub-questions across the paper."
            );
        }
    }

    /** Authored sequence decides order inside a group, not insertion order. */
    public function test_sub_questions_follow_their_authored_sequence(): void
    {
        $group = $this->group();
        $exam = $this->exam(['shuffle_questions' => false]);

        // Attached in the wrong order on purpose.
        $third = $this->question(['group_id' => $group->id, 'group_sequence' => 3]);
        $first = $this->question(['group_id' => $group->id, 'group_sequence' => 1]);
        $second = $this->question(['group_id' => $group->id, 'group_sequence' => 2]);

        $this->attach($exam, [$third, $first, $second]);

        $order = app(CbtExamService::class)->composePaper($exam, 99);

        $this->assertSame([$first->id, $second->id, $third->id], $order);
    }

    /**
     * §6.6 rule 2. A subset draw that splits a group hands a candidate part
     * (c) of a comprehension without (a) and (b), and the marks stop adding up
     * to `total_marks`.
     */
    public function test_a_random_subset_takes_whole_groups_and_overshoots(): void
    {
        $groupA = $this->group(['title' => 'Passage A']);
        $groupB = $this->group(['title' => 'Passage B']);
        $exam = $this->exam(['questions_per_attempt' => 4]);

        $a = [];
        for ($i = 1; $i <= 3; $i++) {
            $a[] = $this->question(['group_id' => $groupA->id, 'group_sequence' => $i]);
        }

        $b = [];
        for ($i = 1; $i <= 3; $i++) {
            $b[] = $this->question(['group_id' => $groupB->id, 'group_sequence' => $i]);
        }

        $loose = [$this->question(), $this->question(), $this->question()];

        $this->attach($exam, array_merge($a, $b, $loose));

        $service = app(CbtExamService::class);
        $aIds = collect($a)->pluck('id')->all();
        $bIds = collect($b)->pluck('id')->all();

        for ($seed = 1; $seed <= 60; $seed++) {
            $order = $service->composePaper($exam, $seed);

            foreach ([['A', $aIds], ['B', $bIds]] as [$label, $ids]) {
                $present = count(array_intersect($order, $ids));

                $this->assertContains(
                    $present,
                    [0, 3],
                    "Seed {$seed} served {$present} of the 3 questions in passage {$label} — a group was split."
                );
            }

            // Overshoot, never undershoot: the candidate gets at least the
            // number the author asked for.
            $this->assertGreaterThanOrEqual(4, count($order), "Seed {$seed} produced a short paper.");
        }
    }

    /** The author is told about the overshoot at publish, not by a candidate. */
    public function test_publishing_warns_when_groups_round_the_paper_up(): void
    {
        $group = $this->group();
        $exam = $this->exam(['status' => 'draft', 'questions_per_attempt' => 2]);

        $this->attach($exam, [
            $this->question(['group_id' => $group->id, 'group_sequence' => 1]),
            $this->question(['group_id' => $group->id, 'group_sequence' => 2]),
            $this->question(['group_id' => $group->id, 'group_sequence' => 3]),
            $this->question(),
            $this->question(),
        ]);

        $response = $this->asTeacher()->postJson("/api/v1/cbt/exams/{$exam->id}/publish");

        $response->assertOk();
        $this->assertGreaterThan(2, $response->json('questions_per_candidate'));
        $this->assertStringContainsString('whole groups', (string) $response->json('notice'));
    }

    /** The stimulus travels with the paper, once, with a position readout. */
    public function test_the_candidate_paper_carries_the_stimulus_and_its_position(): void
    {
        $group = $this->group();
        $exam = $this->exam(['shuffle_questions' => false]);

        $this->attach($exam, [
            $this->question(['group_id' => $group->id, 'group_sequence' => 1]),
            $this->question(['group_id' => $group->id, 'group_sequence' => 2]),
            $this->question(),
        ]);

        $attempt = app(CbtExamService::class)->startAttempt($exam, $this->student->id);
        $paper = app(CbtExamService::class)->buildCandidatePaper($attempt, $exam);

        $this->assertSame('Passage 2 — The Harmattan', $paper[0]['group']['title']);
        $this->assertStringContainsString('harmattan', $paper[0]['group']['stimulus']);
        $this->assertSame(1, $paper[0]['group']['position']);
        $this->assertSame(2, $paper[0]['group']['of']);
        $this->assertSame(2, $paper[1]['group']['position']);

        // A standalone question is untouched by any of this.
        $this->assertArrayNotHasKey('group', $paper[2]);
    }

    public function test_a_teacher_can_author_a_group_through_the_api(): void
    {
        $response = $this->asTeacher()->postJson('/api/v1/cbt/question-groups', [
            'subject_id' => $this->subject->id,
            'title' => 'Data table — rainfall in Jos',
            'stimulus' => 'Month | Rainfall (mm)',
            'instructions' => 'Study the table and answer questions 12 to 15.',
        ]);

        $response->assertCreated();

        $groupId = $response->json('group.id');

        $question = $this->asTeacher()->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'group_id' => $groupId,
            'group_sequence' => 1,
            'question' => 'Which month was wettest?',
            'question_type' => 'multiple_choice',
            'options' => ['A' => 'June', 'B' => 'July'],
            'correct_answer' => 'B',
        ]);

        $question->assertCreated();
        $this->assertSame($groupId, $question->json('question.group_id'));

        $this->asTeacher()->getJson("/api/v1/cbt/question-groups/{$groupId}")
            ->assertOk()
            ->assertJsonPath('questions.0.group_sequence', 1);
    }

    /**
     * Deleting a passage that six questions point at must not be a cascade
     * default. Either outcome is destructive in a different way, so the caller
     * has to say which they meant.
     */
    public function test_deleting_a_group_with_questions_is_refused_until_asked_twice(): void
    {
        $group = $this->group();
        $question = $this->question(['group_id' => $group->id, 'group_sequence' => 1]);

        $this->asTeacher()->deleteJson("/api/v1/cbt/question-groups/{$group->id}")
            ->assertStatus(409)
            ->assertJsonPath('question_ids.0', $question->id);

        $this->assertDatabaseHas('cbt_question_groups', ['id' => $group->id]);

        $this->asTeacher()->deleteJson("/api/v1/cbt/question-groups/{$group->id}?detach=1")
            ->assertOk()
            ->assertJsonPath('questions_detached', 1);

        // The question survives as a standalone item; its attempt history and
        // item statistics would have been orphaned by a cascade.
        $this->assertDatabaseMissing('cbt_question_groups', ['id' => $group->id]);
        $this->assertNull($question->fresh()->group_id);
        $this->assertNotNull($question->fresh());
    }

    /** Editing the passage under a candidate reading it is the same offence
     *  as editing a question mid-exam. */
    public function test_a_live_groups_stimulus_cannot_be_rewritten(): void
    {
        $group = $this->group();
        $exam = $this->exam();
        $this->attach($exam, [$this->question(['group_id' => $group->id, 'group_sequence' => 1])]);

        $this->asTeacher()->putJson("/api/v1/cbt/question-groups/{$group->id}", [
            'stimulus' => 'A completely different passage.',
        ])->assertStatus(409);

        $this->assertStringContainsString('harmattan', $group->fresh()->stimulus);
    }

    /** Groups are tenant-scoped like everything else. */
    public function test_a_teacher_cannot_reach_another_schools_group(): void
    {
        $other = School::create(['name' => 'Zenith Academy', 'slug' => 'zenith', 'subdomain' => 'zenith']);
        $foreign = CbtQuestionGroup::create([
            'school_id' => $other->id,
            'title' => 'Someone else\'s passage',
        ]);

        $this->asTeacher()->getJson("/api/v1/cbt/question-groups/{$foreign->id}")->assertNotFound();
    }
}
