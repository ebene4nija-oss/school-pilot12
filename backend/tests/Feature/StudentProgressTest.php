<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Guardian;
use App\Models\Intervention;
use App\Models\NotificationLog;
use App\Models\ResultRelease;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentProgressInsight;
use App\Models\StudentRemark;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Support\TeacherScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Student progress and analysis: the acceptance checks in §12 / PRD §19. */
class StudentProgressTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $mathsTeacher;
    private User $stranger;
    private AcademicSession $session;
    private Term $first;
    private Term $second;
    private SchoolClass $class;
    private Subject $maths;
    private Subject $english;
    private Student $ada;
    private Student $bayo;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        TeacherScope::forget();

        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $this->admin = $this->user('Admin', 'school_admin');
        $this->mathsTeacher = $this->user('Maths Teacher', 'teacher');
        $this->stranger = $this->user('Stranger', 'teacher');

        $this->session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonths(5), 'end_date' => now()->addMonths(7), 'is_current' => true]);
        $this->first = Term::create(['school_id' => $this->school->id, 'session_id' => $this->session->id, 'name' => 'First Term', 'start_date' => now()->subMonths(5), 'end_date' => now()->subMonths(2)]);
        $this->second = Term::create(['school_id' => $this->school->id, 'session_id' => $this->session->id, 'name' => 'Second Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonth()]);

        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2', 'level_category' => 'junior_secondary']);
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);
        $this->english = Subject::create(['school_id' => $this->school->id, 'name' => 'English']);
        DB::table('teacher_subjects')->insert(['school_id' => $this->school->id, 'teacher_id' => $this->mathsTeacher->id, 'subject_id' => $this->maths->id, 'class_id' => $this->class->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->ada = $this->student('Ada Obi');
        $this->bayo = $this->student('Bayo Ade');

        // First term: approved and closed. Ada 60/70, Bayo 50/50.
        $this->marks($this->first, $this->ada, 60, 70);
        $this->marks($this->first, $this->bayo, 50, 50);
        $this->approveAndClose($this->first);

        // Second term (current, provisional): Ada 72/80, Bayo 40/42.
        $this->marks($this->second, $this->ada, 72, 80);
        $this->marks($this->second, $this->bayo, 40, 42);
    }

    private function user(string $name, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@hilltop.test', 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function student(string $name): Student
    {
        $user = $this->user($name, 'student');

        return Student::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'class_id' => $this->class->id, 'admission_number' => strtoupper(substr($name, 0, 3)), 'status' => 'active']);
    }

    /** Totals as percentages on the 20/20/60 default: exam carries the rest. */
    private function marks(Term $term, Student $student, int $maths, int $english): void
    {
        foreach ([[$this->maths, $maths], [$this->english, $english]] as [$subject, $total]) {
            $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/assessment/score", [
                'term_id' => $term->id, 'student_id' => $student->id, 'subject_id' => $subject->id,
                'first_ca' => 10, 'second_ca' => 10, 'exam' => $total - 20,
            ])->assertOk();
        }
    }

    private function approveAndClose(Term $term): void
    {
        $bs = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/broadsheets/class?term_id={$term->id}&class_id={$this->class->id}")->json('broadsheet');

        // Maths belongs to its teacher; English has none, so the admin hands it in.
        foreach ([[$this->mathsTeacher, $this->maths], [$this->admin, $this->english]] as [$by, $subject]) {
            $this->actingAs($by, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs['id']}/subjects/{$subject->id}/submit")->assertOk();
        }

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs['id']}/approve");
        }

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/academic-history/close-term", ['term_id' => $term->id, 'class_id' => $this->class->id])->assertOk();
    }

    public function test_the_overview_matches_the_recorded_results_and_marks_the_current_term_provisional(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/progress")->assertOk()->json();

        $this->assertSame(76.0, (float) $res['overview']['average']);
        $this->assertSame(65.0, (float) $res['overview']['previous_average']);
        $this->assertSame(11.0, (float) $res['overview']['change_points'], 'A change in points, not percent.');
        $this->assertTrue($res['overview']['provisional']);
        $this->assertSame(2, $res['overview']['subjects_passed']);

        $this->assertCount(2, $res['trend']);
        $this->assertFalse($res['trend'][0]['provisional']);
        $this->assertTrue($res['trend'][1]['provisional']);

        $maths = collect($res['subjects'])->firstWhere('subject', 'Mathematics');
        $this->assertSame(12.0, (float) $maths['change']);
        $this->assertSame(20.0, (float) $maths['ca']);
        $this->assertSame(52.0, (float) $maths['exam']);
        $this->assertSame(56.0, (float) $maths['class_average']);
    }

    /** PRD acceptance 19 and 22. */
    public function test_a_subject_teacher_sees_class_detail_only_for_their_subject(): void
    {
        $res = $this->actingAs($this->mathsTeacher, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/progress")->assertOk()->json();
        $subjects = collect($res['subjects'])->keyBy('subject');

        $this->assertNotNull($subjects['Mathematics']['class_average']);
        $this->assertNull($subjects['English']['class_average']);
        $this->assertNull($subjects['English']['position']);
        $this->assertNotNull($subjects['English']['total'], 'The overview itself is visible.');

        $this->actingAs($this->stranger, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/progress")->assertNotFound();
        $this->actingAs($this->stranger, 'sanctum')->getJson("{$this->host}/classes/{$this->class->id}/analysis")->assertForbidden();

        $analysis = $this->actingAs($this->mathsTeacher, 'sanctum')->getJson("{$this->host}/classes/{$this->class->id}/analysis")->assertOk()->json();
        $this->assertSame(['Mathematics'], array_column($analysis['subjects'], 'name'));
    }

    /** PRD acceptance 3, instructions 22. */
    public function test_students_see_only_released_terms_and_no_class_comparisons(): void
    {
        $this->actingAs($this->ada->user, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/progress")->assertForbidden();

        $mine = $this->actingAs($this->ada->user, 'sanctum')->getJson("{$this->host}/me/progress")->assertOk()->json();
        $this->assertTrue($mine['empty'], 'Nothing released yet.');

        ResultRelease::create(['school_id' => $this->school->id, 'term_id' => $this->first->id, 'class_id' => null, 'is_released' => true, 'released_at' => now()]);

        $mine = $this->actingAs($this->ada->user, 'sanctum')->getJson("{$this->host}/me/progress")->assertOk()->json();
        $this->assertCount(1, $mine['trend'], 'The provisional current term is not shown to students.');
        $this->assertNull($mine['trend'][0]['class_average']);
        $this->assertNull($mine['subjects'][0]['class_average']);
        $this->assertNull($mine['overview']['position']);

        $parent = $this->user('Mrs Obi', 'parent');
        Guardian::create(['school_id' => $this->school->id, 'user_id' => $parent->id])->students()->attach($this->ada->id);
        $this->actingAs($parent, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/progress")->assertForbidden();
    }

    /** Acceptance 7 / 19: never rated on too little data. */
    public function test_a_topic_needs_enough_questions_before_it_is_rated(): void
    {
        $exam = DB::table('cbt_exams')->insertGetId(['school_id' => $this->school->id, 'subject_id' => $this->maths->id, 'class_id' => $this->class->id, 'title' => 'Algebra test', 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()]);
        $attempt = DB::table('cbt_attempts')->insertGetId(['school_id' => $this->school->id, 'exam_id' => $exam, 'student_id' => $this->ada->id, 'seed' => 1, 'status' => 'graded', 'submitted_at' => now(), 'raw_score' => 4, 'max_score' => 6, 'percentage' => 66.67, 'created_at' => now(), 'updated_at' => now()]);

        $question = fn (string $topic) => DB::table('question_bank')->insertGetId(['school_id' => $this->school->id, 'subject_id' => $this->maths->id, 'topic' => $topic, 'question' => 'Q', 'marks' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // Algebra: 5 questions, 1 right. Geometry: 1 question, right.
        foreach ([1, 0, 0, 0, 0] as $right) {
            DB::table('cbt_attempt_answers')->insert(['attempt_id' => $attempt, 'question_id' => $question('Algebra'), 'awarded_marks' => $right, 'is_correct' => (bool) $right, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('cbt_attempt_answers')->insert(['attempt_id' => $attempt, 'question_id' => $question('Geometry'), 'awarded_marks' => 1, 'is_correct' => true, 'created_at' => now(), 'updated_at' => now()]);

        $topics = collect($this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/progress/topics")->assertOk()->json('data'))->keyBy('topic');

        $this->assertSame('needs_attention', $topics['Algebra']['category']);
        $this->assertSame(20.0, (float) $topics['Algebra']['percentage']);
        $this->assertSame('insufficient_data', $topics['Geometry']['category']);
        $this->assertNull($topics['Geometry']['percentage']);
    }

    /** Acceptance 20: every insight is traceable and claims no cause. */
    public function test_insights_are_stored_with_their_rule_and_inputs(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/progress")->assertOk()->json();

        $rise = collect($res['insights'])->firstWhere('rule', 'average_rise');
        $this->assertNotNull($rise);
        $this->assertStringContainsString('rose by 11', $rise['text']);
        $this->assertStringNotContainsStringIgnoringCase('because', $rise['text']);

        $stored = StudentProgressInsight::where('student_id', $this->ada->id)->where('rule', 'average_rise')->firstOrFail();
        $this->assertEquals(['current' => 76, 'previous' => 65], $stored->inputs);

        // Bayo fell from 50 to 41: a flag, with its reason.
        $bayo = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/students/{$this->bayo->id}/progress")->json();
        $this->assertSame('sharp_decline', $bayo['flags'][0]['rule']);
        $this->assertStringContainsString('fell by 9', $bayo['flags'][0]['text']);
    }

    public function test_remarks_respect_visibility_and_edits_are_logged(): void
    {
        $id = $this->actingAs($this->mathsTeacher, 'sanctum')->postJson("{$this->host}/students/{$this->ada->id}/remarks", [
            'body' => 'Struggles with word problems; needs more practice.', 'subject_id' => $this->maths->id,
        ])->assertCreated()->json('remark.id');

        $this->actingAs($this->mathsTeacher, 'sanctum')->postJson("{$this->host}/students/{$this->ada->id}/remarks", [
            'body' => 'Great effort this term.', 'visibility' => 'student_visible',
        ])->assertCreated();

        // A subject teacher cannot remark on another subject.
        $this->actingAs($this->mathsTeacher, 'sanctum')->postJson("{$this->host}/students/{$this->ada->id}/remarks", ['body' => 'English note', 'subject_id' => $this->english->id])->assertForbidden();

        ResultRelease::create(['school_id' => $this->school->id, 'term_id' => $this->first->id, 'class_id' => null, 'is_released' => true, 'released_at' => now()]);
        $seen = $this->actingAs($this->ada->user, 'sanctum')->getJson("{$this->host}/me/progress")->json('remarks');
        $this->assertSame(['Great effort this term.'], array_column($seen, 'body'));

        $this->actingAs($this->mathsTeacher, 'sanctum')->putJson("{$this->host}/student-remarks/{$id}", ['body' => 'Struggles with word problems.'])->assertOk();
        $this->actingAs($this->mathsTeacher, 'sanctum')->deleteJson("{$this->host}/student-remarks/{$id}")->assertOk();

        $this->assertNotNull(StudentRemark::withTrashed()->find($id)->deleted_at, 'Soft deleted, not erased.');
        $this->assertTrue(AuditLog::where('action', 'progress.remark_edited')->exists());
    }

    public function test_interventions_track_targets_reviews_and_outcomes(): void
    {
        $plan = [
            'subject_id' => $this->maths->id, 'issue' => 'Low performance in algebra', 'baseline' => '48%', 'target' => 'At least 65% in the next test',
            'action' => 'Weekly revision exercises and extra support', 'start_date' => now()->toDateString(), 'review_date' => now()->addWeeks(4)->toDateString(),
        ];

        // A teacher cannot assign a plan to someone else; an admin can.
        $this->actingAs($this->mathsTeacher, 'sanctum')->postJson("{$this->host}/students/{$this->ada->id}/interventions", $plan + ['responsible_teacher_id' => $this->stranger->id])->assertForbidden();

        $id = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/students/{$this->ada->id}/interventions", $plan + ['responsible_teacher_id' => $this->mathsTeacher->id])
            ->assertCreated()->json('intervention.id');
        $this->assertTrue(NotificationLog::where('user_id', $this->mathsTeacher->id)->where('category', 'intervention')->exists());

        $this->actingAs($this->mathsTeacher, 'sanctum')->postJson("{$this->host}/interventions/{$id}/reviews", ['progress' => 'Completed 3 of 4 worksheets', 'result' => '58%'])
            ->assertCreated()->assertJsonPath('intervention.status', 'ongoing');

        $this->actingAs($this->mathsTeacher, 'sanctum')->patchJson("{$this->host}/interventions/{$id}", ['status' => 'completed'])->assertStatus(422);
        $this->actingAs($this->mathsTeacher, 'sanctum')->patchJson("{$this->host}/interventions/{$id}", ['status' => 'completed', 'outcome' => 'Scored 67% in the next test'])->assertOk();

        $this->actingAs($this->stranger, 'sanctum')->patchJson("{$this->host}/interventions/{$id}", ['status' => 'cancelled'])->assertNotFound();

        $outcomes = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/interventions")->assertOk()->json('outcomes');
        $this->assertSame('Scored 67% in the next test', $outcomes[0]['outcome']);
        $this->assertSame('completed', Intervention::find($id)->status);
    }

    public function test_the_page_never_edits_an_academic_record(): void
    {
        $before = DB::table('score_entries')->orderBy('id')->get(['total_score', 'grade', 'updated_at'])->toArray();

        $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/progress")->assertOk();
        $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/classes/{$this->class->id}/analysis")->assertOk();
        $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/analytics/school-progress")->assertOk();
        $this->actingAs($this->admin, 'sanctum')->get("{$this->host}/students/{$this->ada->id}/progress/report")->assertOk();

        $this->assertEquals($before, DB::table('score_entries')->orderBy('id')->get(['total_score', 'grade', 'updated_at'])->toArray());
    }
}
