<?php

namespace Tests\Feature;

use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\QuestionBankItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SectionHeadAssignment;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Filing the question bank by class, and acting on many questions at once.
 *
 * One Mathematics serves Primary 1 to SS 3, so the bank is filed by class;
 * questions written before that are "unsorted" until filed. Bulk actions
 * reach exactly what the list showed, and change only what the caller may.
 */
class QuestionBankOrganisationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $teacher;
    private User $colleague;
    private Subject $maths;
    private Subject $physics;
    private SchoolClass $pry4;
    private SchoolClass $ss3;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://graceland.localhost');

        $this->school = School::create(['name' => 'Graceland College', 'slug' => 'graceland', 'subdomain' => 'graceland']);
        $this->admin = $this->user('Principal', 'school_admin');
        $this->teacher = $this->user('Mrs Adeyemi', 'teacher');
        $this->colleague = $this->user('Mr Okafor', 'teacher');

        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'department' => 'science']);
        $this->physics = Subject::create(['school_id' => $this->school->id, 'name' => 'Physics', 'department' => 'science']);
        $this->pry4 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'Primary 4', 'level_category' => 'primary']);
        $this->ss3 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'SS 3', 'level_category' => 'senior_secondary']);
    }

    private function user(string $name, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => str_replace(' ', '.', strtolower($name)).'@graceland.test', 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum')->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    private function question(User $by, ?SchoolClass $class, array $extra = []): QuestionBankItem
    {
        return QuestionBankItem::create($extra + [
            'school_id' => $this->school->id, 'subject_id' => $this->maths->id, 'class_id' => $class?->id,
            'question' => 'What is 2 + 2?', 'question_type' => 'multiple_choice', 'options' => ['A' => '3', 'B' => '4'],
            'correct_answer' => 'B', 'status' => 'approved', 'created_by' => $by->id, 'marks' => 1,
        ]);
    }

    public function test_questions_are_filed_by_class_and_filtered_by_class_or_level()
    {
        $this->as($this->teacher)->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->maths->id, 'class_id' => $this->pry4->id, 'question' => 'Count: 3 + 1',
            'question_type' => 'multiple_choice', 'options' => ['A' => '4', 'B' => '5'], 'correct_answer' => 'A',
        ])->assertCreated();
        $this->question($this->teacher, $this->ss3);
        $this->question($this->teacher, null);

        $ids = fn (string $qs) => collect($this->as($this->teacher)->getJson("/api/v1/cbt/questions?{$qs}")->assertOk()->json('data'));

        $this->assertSame(['Primary 4'], $ids("class_id={$this->pry4->id}")->pluck('class')->all());
        $this->assertCount(1, $ids('class_id=none'));
        $this->assertCount(1, $ids('level_group=senior_secondary'));

        // An older app that sends no class still saves; the question is unsorted.
        $this->as($this->teacher)->postJson('/api/v1/cbt/questions/import', [
            'subject_id' => $this->maths->id,
            'questions' => [['question' => 'Old app', 'options' => ['A' => 'x', 'B' => 'y'], 'correct_answer' => 'A']],
        ])->assertCreated();
        $this->assertCount(2, $ids('class_id=none'));
    }

    public function test_the_summary_counts_by_class_subject_and_topic()
    {
        $this->question($this->teacher, $this->pry4, ['topic' => 'Addition']);
        $this->question($this->teacher, $this->pry4, ['topic' => 'Addition']);
        $this->question($this->teacher, null);

        $summary = $this->as($this->teacher)->getJson('/api/v1/cbt/questions/summary')->assertOk();

        $summary->assertJsonPath('total', 3)->assertJsonPath('unsorted', 1);
        $this->assertSame(2, collect($summary->json('classes'))->firstWhere('id', $this->pry4->id)['total']);
        $this->assertContains(['class_id' => $this->pry4->id, 'subject_id' => $this->maths->id, 'subject' => 'Mathematics', 'topic' => 'Addition', 'total' => 2], $summary->json('rows'));
    }

    public function test_select_all_matching_files_exactly_the_filtered_questions()
    {
        $unsorted = collect(range(1, 3))->map(fn () => $this->question($this->teacher, null));
        $filed = $this->question($this->teacher, $this->ss3);

        $this->as($this->teacher)->postJson('/api/v1/cbt/questions/bulk', [
            'action' => 'set_class', 'class_id' => $this->pry4->id, 'filters' => ['class_id' => 'none'],
        ])->assertOk()->assertJsonPath('changed', 3)->assertJsonPath('skipped', 0);

        $this->assertSame(3, QuestionBankItem::where('class_id', $this->pry4->id)->whereIn('id', $unsorted->pluck('id'))->count());
        $this->assertSame($this->ss3->id, $filed->fresh()->class_id);
    }

    public function test_a_teacher_changes_only_their_own_questions_and_an_admin_any()
    {
        $mine = $this->question($this->teacher, $this->pry4);
        $theirs = $this->question($this->colleague, $this->pry4);

        $this->as($this->teacher)->postJson('/api/v1/cbt/questions/bulk', [
            'action' => 'set_difficulty', 'difficulty' => 'hard', 'ids' => [$mine->id, $theirs->id],
        ])->assertOk()->assertJsonPath('changed', 1)->assertJsonPath('skipped', 1);

        $this->assertSame('hard', $mine->fresh()->difficulty);
        $this->assertNotSame('hard', $theirs->fresh()->difficulty);

        $this->as($this->admin)->postJson('/api/v1/cbt/questions/bulk', [
            'action' => 'set_status', 'status' => 'draft', 'ids' => [$mine->id, $theirs->id],
        ])->assertOk()->assertJsonPath('changed', 2);
    }

    public function test_a_head_of_department_changes_their_departments_questions()
    {
        SectionHeadAssignment::create(['school_id' => $this->school->id, 'position' => 'hod', 'level_group' => 'senior_secondary', 'department' => 'science', 'user_id' => $this->teacher->id]);
        $physics = $this->question($this->colleague, $this->ss3, ['subject_id' => $this->physics->id]);

        $this->as($this->teacher)->postJson('/api/v1/cbt/questions/bulk', [
            'action' => 'set_topic', 'topic' => 'Mechanics', 'ids' => [$physics->id],
        ])->assertOk()->assertJsonPath('changed', 1);

        $this->assertSame('Mechanics', $physics->fresh()->topic);
    }

    public function test_bulk_delete_retires_questions_already_on_a_paper()
    {
        $used = $this->question($this->teacher, $this->pry4);
        $spare = $this->question($this->teacher, $this->pry4);
        $exam = CbtExam::create(['school_id' => $this->school->id, 'subject_id' => $this->maths->id, 'title' => 'CA 1', 'created_by' => $this->teacher->id, 'duration_minutes' => 30]);
        CbtExamQuestion::create(['exam_id' => $exam->id, 'question_id' => $used->id, 'order_index' => 1]);

        $this->as($this->teacher)->postJson('/api/v1/cbt/questions/bulk', ['action' => 'delete', 'ids' => [$used->id, $spare->id]])
            ->assertOk()->assertJsonPath('changed', 2);

        $this->assertSame('retired', $used->fresh()->status);
        $this->assertNull(QuestionBankItem::find($spare->id));
    }

    public function test_select_all_matching_adds_to_a_paper_without_duplicates_or_retired_questions()
    {
        $exam = CbtExam::create(['school_id' => $this->school->id, 'subject_id' => $this->maths->id, 'class_id' => $this->pry4->id, 'title' => 'CA 1', 'created_by' => $this->teacher->id, 'duration_minutes' => 30]);
        $already = $this->question($this->teacher, $this->pry4);
        CbtExamQuestion::create(['exam_id' => $exam->id, 'question_id' => $already->id, 'order_index' => 1]);
        $this->question($this->colleague, $this->pry4);
        $this->question($this->teacher, $this->pry4, ['status' => 'retired']);
        $this->question($this->teacher, $this->ss3);

        $this->as($this->teacher)->postJson('/api/v1/cbt/questions/bulk', [
            'action' => 'add_to_exam', 'exam_id' => $exam->id, 'filters' => ['class_id' => $this->pry4->id, 'subject_id' => $this->maths->id],
        ])->assertOk()->assertJsonPath('changed', 1)->assertJsonPath('question_count', 2)->assertJsonPath('total_marks', 2);
    }

    public function test_a_selection_that_is_too_large_is_refused()
    {
        $this->as($this->admin)->postJson('/api/v1/cbt/questions/bulk', ['action' => 'delete', 'ids' => range(1, 2001)])->assertStatus(422);
    }
}
