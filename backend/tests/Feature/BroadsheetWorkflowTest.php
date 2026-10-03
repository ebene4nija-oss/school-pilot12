<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Broadsheet;
use App\Models\BroadsheetEvent;
use App\Models\CaScheme;
use App\Models\ResultRelease;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSectionSetting;
use App\Models\ScoreEntry;
use App\Models\SectionHeadAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\Results\ResultCalculator;
use App\Support\TeacherScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sections baseline (D1–D3) and the broadsheet: calculation, review chain,
 * locking, corrections, versions and exports.
 */
class BroadsheetWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $mathsTeacher;
    private User $englishTeacher;
    private User $formTeacher;
    private User $principal;
    private AcademicSession $session;
    private Term $term;
    private SchoolClass $class;
    private Subject $maths;
    private Subject $english;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        TeacherScope::forget();

        $this->school = School::create(['name' => 'Hilltop College', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $this->admin = $this->user('Admin', 'school_admin');
        $this->mathsTeacher = $this->user('Mr Maths', 'teacher');
        $this->englishTeacher = $this->user('Mrs English', 'teacher');
        $this->formTeacher = $this->user('Form Teacher', 'teacher');
        $this->principal = $this->user('Principal', 'teacher');

        $this->session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addYear()]);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $this->session->id, 'name' => 'First Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2)]);

        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2', 'level_category' => 'junior_secondary']);
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'department' => 'Sciences']);
        $this->english = Subject::create(['school_id' => $this->school->id, 'name' => 'English', 'department' => 'Languages']);

        foreach ([[$this->mathsTeacher, $this->maths], [$this->englishTeacher, $this->english]] as [$teacher, $subject]) {
            DB::table('teacher_subjects')->insert(['school_id' => $this->school->id, 'teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'class_id' => $this->class->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('class_teacher_assignments')->insert(['school_id' => $this->school->id, 'session_id' => $this->session->id, 'class_id' => $this->class->id, 'form_teacher_id' => $this->formTeacher->id, 'created_at' => now(), 'updated_at' => now()]);

        SectionHeadAssignment::create(['school_id' => $this->school->id, 'position' => 'principal', 'user_id' => $this->principal->id, 'started_at' => now()]);
    }

    private function user(string $name, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@hilltop.test', 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function student(string $name, string $admission, ?SchoolClass $class = null): Student
    {
        $user = $this->user($name, 'student');

        return Student::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'class_id' => ($class ?? $this->class)->id, 'admission_number' => $admission]);
    }

    private function score(User $as, Student $student, Subject $subject, array $marks)
    {
        return $this->actingAs($as, 'sanctum')->postJson("{$this->host}/assessment/score", array_merge([
            'term_id' => $this->term->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ], $marks));
    }

    private function calc(): array
    {
        return app(ResultCalculator::class)->forClass($this->school->id, $this->term->id, $this->class->id);
    }

    private function openBroadsheet(): Broadsheet
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("{$this->host}/broadsheets/class?term_id={$this->term->id}&class_id={$this->class->id}")
            ->assertOk();

        return Broadsheet::where('class_id', $this->class->id)->firstOrFail();
    }

    /** Two students marked in both subjects, both subjects submitted. */
    private function readyForReview(): array
    {
        $ada = $this->student('Ada Obi', 'A001');
        $bayo = $this->student('Bayo Ade', 'A002');

        $this->score($this->mathsTeacher, $ada, $this->maths, ['first_ca' => 18, 'second_ca' => 17, 'exam' => 50])->assertOk();
        $this->score($this->mathsTeacher, $bayo, $this->maths, ['first_ca' => 10, 'second_ca' => 10, 'exam' => 30])->assertOk();
        $this->score($this->englishTeacher, $ada, $this->english, ['first_ca' => 15, 'second_ca' => 15, 'exam' => 40])->assertOk();
        $this->score($this->englishTeacher, $bayo, $this->english, ['first_ca' => 12, 'second_ca' => 12, 'exam' => 36])->assertOk();

        $bs = $this->openBroadsheet();

        $this->actingAs($this->mathsTeacher, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/subjects/{$this->maths->id}/submit")->assertOk();
        $this->actingAs($this->englishTeacher, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/subjects/{$this->english->id}/submit")->assertOk();

        return [$ada, $bayo, $bs->fresh()];
    }

    // ------------------------------------------------------------------
    // Calculation
    // ------------------------------------------------------------------

    public function test_a_missing_score_is_never_counted_as_zero(): void
    {
        $ada = $this->student('Ada Obi', 'A001');
        $bayo = $this->student('Bayo Ade', 'A002');

        $this->score($this->admin, $ada, $this->maths, ['first_ca' => 20, 'second_ca' => 20, 'exam' => 40])->assertOk();
        $this->score($this->admin, $ada, $this->english, ['first_ca' => 10, 'second_ca' => 10, 'exam' => 40])->assertOk();
        $this->score($this->admin, $bayo, $this->maths, ['first_ca' => 20, 'second_ca' => 20, 'exam' => 40])->assertOk();

        $calc = $this->calc();
        $bayoRow = collect($calc['students'])->firstWhere('id', $bayo->id);

        $this->assertSame(80.0, $bayoRow['average']);
        $this->assertSame('not_entered', $bayoRow['results'][$this->english->id]['state']);
        $this->assertFalse($bayoRow['complete']);
    }

    public function test_absence_follows_the_school_policy(): void
    {
        $ada = $this->student('Ada Obi', 'A001');

        $this->score($this->admin, $ada, $this->maths, ['first_ca' => 20, 'second_ca' => 20, 'exam' => 40])->assertOk();
        $this->score($this->admin, $ada, $this->english, ['first_ca' => 0, 'second_ca' => 0, 'exam' => 0, 'result_state' => 'absent'])->assertOk();

        $row = collect($this->calc()['students'])->firstWhere('id', $ada->id);
        $this->assertSame(80.0, $row['average'], 'Excluded by default.');

        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/broadsheets/config", ['absence_policy' => 'zero'])->assertOk();

        $row = collect($this->calc()['students'])->firstWhere('id', $ada->id);
        $this->assertSame(40.0, $row['average'], 'Counted as zero once the school says so.');
    }

    public function test_an_elective_only_applies_to_students_taking_it(): void
    {
        $ada = $this->student('Ada Obi', 'A001');
        $bayo = $this->student('Bayo Ade', 'A002');

        DB::table('class_subjects')->insert([
            ['school_id' => $this->school->id, 'class_id' => $this->class->id, 'subject_id' => $this->maths->id, 'is_compulsory' => true, 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $this->school->id, 'class_id' => $this->class->id, 'subject_id' => $this->english->id, 'is_compulsory' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('student_subjects')->insert(['school_id' => $this->school->id, 'student_id' => $ada->id, 'subject_id' => $this->english->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->score($this->admin, $bayo, $this->maths, ['first_ca' => 20, 'second_ca' => 20, 'exam' => 40])->assertOk();

        $rows = collect($this->calc()['students'])->keyBy('id');

        $this->assertSame('not_offered', $rows[$bayo->id]['results'][$this->english->id]['state']);
        $this->assertTrue($rows[$bayo->id]['complete']);
        $this->assertSame('not_entered', $rows[$ada->id]['results'][$this->english->id]['state']);
    }

    public function test_competition_and_dense_ranking(): void
    {
        $calculator = app(ResultCalculator::class);

        $this->assertSame([0 => 1, 1 => 2, 2 => 2, 3 => 4], $this->sortedByKey($calculator->positions([90.0, 80.0, 80.0, 70.0], 'competition')));
        $this->assertSame([0 => 1, 1 => 2, 2 => 2, 3 => 3], $this->sortedByKey($calculator->positions([90.0, 80.0, 80.0, 70.0], 'dense')));
    }

    private function sortedByKey(array $a): array
    {
        ksort($a);

        return $a;
    }

    public function test_complete_only_ranking_leaves_incomplete_students_unranked(): void
    {
        $ada = $this->student('Ada Obi', 'A001');
        $bayo = $this->student('Bayo Ade', 'A002');

        $this->score($this->admin, $ada, $this->maths, ['first_ca' => 20, 'second_ca' => 20, 'exam' => 40])->assertOk();
        $this->score($this->admin, $ada, $this->english, ['first_ca' => 20, 'second_ca' => 20, 'exam' => 40])->assertOk();
        $this->score($this->admin, $bayo, $this->maths, ['first_ca' => 20, 'second_ca' => 20, 'exam' => 60])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/broadsheets/config", ['ranking_scope' => 'complete_only'])->assertOk();

        $rows = collect($this->calc()['students'])->keyBy('id');

        $this->assertSame(1, $rows[$ada->id]['position']);
        $this->assertNull($rows[$bayo->id]['position']);
    }

    // ------------------------------------------------------------------
    // Workflow
    // ------------------------------------------------------------------

    public function test_a_submitted_subject_is_locked_against_edits(): void
    {
        [$ada, , $bs] = $this->readyForReview();

        $this->score($this->mathsTeacher, $ada, $this->maths, ['first_ca' => 1, 'second_ca' => 1, 'exam' => 1])
            ->assertStatus(409)
            ->assertJsonPath('code', 'result_locked');

        $this->assertSame('in_review', $bs->status);
        $this->assertSame('class_teacher', $bs->currentStepRole());
    }

    public function test_a_subject_teacher_cannot_submit_another_teachers_subject(): void
    {
        $this->student('Ada Obi', 'A001');
        $bs = $this->openBroadsheet();

        $this->actingAs($this->englishTeacher, 'sanctum')
            ->postJson("{$this->host}/broadsheets/{$bs->id}/subjects/{$this->maths->id}/submit")
            ->assertForbidden();
    }

    public function test_the_full_chain_produces_a_hashed_version_and_releases_on_publish(): void
    {
        [, , $bs] = $this->readyForReview();

        // Out of turn: the principal cannot approve the class teacher's step.
        $this->actingAs($this->principal, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")->assertForbidden();

        $this->actingAs($this->formTeacher, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")->assertOk();
        $this->assertSame('level_head', $bs->fresh()->currentStepRole(), 'The HOD step is off by default and skipped.');

        $this->actingAs($this->principal, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")
            ->assertOk()
            ->assertJsonPath('broadsheet.status', 'approved')
            ->assertJsonPath('broadsheet.current_version', 1);

        $version = $bs->versions()->first();
        $this->assertTrue($version->intact());
        $this->assertFalse(ResultRelease::isReleasedFor($this->school->id, $this->term->id, $this->class->id));

        $this->actingAs($this->principal, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/publish")
            ->assertOk()
            ->assertJsonPath('broadsheet.status', 'published');

        $this->assertTrue(ResultRelease::isReleasedFor($this->school->id, $this->term->id, $this->class->id));
    }

    public function test_warnings_need_a_recorded_override_before_final_approval(): void
    {
        $ada = $this->student('Ada Obi', 'A001');
        $bayo = $this->student('Bayo Ade', 'A002');
        $this->score($this->admin, $ada, $this->maths, ['first_ca' => 18, 'second_ca' => 17, 'exam' => 50])->assertOk();
        $this->score($this->admin, $bayo, $this->maths, ['first_ca' => 18, 'second_ca' => 17, 'exam' => 50])->assertOk();
        $this->score($this->admin, $ada, $this->english, ['first_ca' => 18, 'second_ca' => 17, 'exam' => 50])->assertOk();
        // Bayo has no English mark.

        $bs = $this->openBroadsheet();
        $this->actingAs($this->mathsTeacher, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/subjects/{$this->maths->id}/submit")->assertOk();
        $this->actingAs($this->englishTeacher, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/subjects/{$this->english->id}/submit")->assertOk();
        $this->actingAs($this->formTeacher, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")->assertOk();

        $this->actingAs($this->principal, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => '1 warning(s) need a recorded override before final approval.']);

        $this->actingAs($this->principal, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/overrides", [
            'code' => 'missing_score',
            'key' => "{$bayo->id}:{$this->english->id}",
            'reason' => 'On medical leave for the whole exam period; letter on file.',
        ])->assertOk();

        $this->actingAs($this->principal, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")->assertOk()
            ->assertJsonPath('broadsheet.status', 'approved');
    }

    public function test_errors_cannot_be_overridden(): void
    {
        [, , $bs] = $this->readyForReview();

        $this->actingAs($this->principal, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/overrides", [
            'code' => 'subject_not_submitted',
            'key' => (string) $this->maths->id,
            'reason' => 'Trying to skip the teacher.',
        ])->assertStatus(409);
    }

    public function test_a_return_reopens_only_the_returned_students(): void
    {
        [$ada, $bayo, $bs] = $this->readyForReview();

        $this->actingAs($this->formTeacher, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/return", [
            'reason' => 'Ada\'s maths exam mark looks transposed.',
            'items' => [['subject_id' => $this->maths->id, 'student_ids' => [$ada->id]]],
        ])->assertOk()->assertJsonPath('broadsheet.status', 'returned');

        $this->score($this->mathsTeacher, $ada, $this->maths, ['first_ca' => 18, 'second_ca' => 17, 'exam' => 55])->assertOk();
        $this->score($this->mathsTeacher, $bayo, $this->maths, ['first_ca' => 1, 'second_ca' => 1, 'exam' => 1])->assertStatus(409);
        $this->score($this->englishTeacher, $ada, $this->english, ['first_ca' => 1, 'second_ca' => 1, 'exam' => 1])->assertStatus(409);

        $return = BroadsheetEvent::where('broadsheet_id', $bs->id)->where('action', 'return')->first();
        $this->assertSame('Ada\'s maths exam mark looks transposed.', $return->reason);
    }

    public function test_a_correction_changes_nothing_until_approved_and_keeps_the_old_version(): void
    {
        [$ada, , $bs] = $this->readyForReview();
        $this->actingAs($this->formTeacher, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")->assertOk();
        $this->actingAs($this->principal, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")->assertOk();

        $before = (float) ScoreEntry::where('student_id', $ada->id)->where('subject_id', $this->maths->id)->value('total_score');

        // Direct edits are closed once approved.
        $this->score($this->mathsTeacher, $ada, $this->maths, ['first_ca' => 20, 'second_ca' => 20, 'exam' => 60])->assertStatus(409);

        $correction = $this->actingAs($this->mathsTeacher, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/corrections", [
            'student_id' => $ada->id,
            'subject_id' => $this->maths->id,
            'components' => ['ca1' => 18, 'ca2' => 17, 'exam' => 58],
            'reason' => 'Exam script re-marked: page 4 was not counted.',
        ])->assertCreated()->json('correction');

        $this->assertSame($before, (float) ScoreEntry::where('student_id', $ada->id)->where('subject_id', $this->maths->id)->value('total_score'));

        // The requester cannot approve their own correction, and nor can a subject teacher.
        $this->actingAs($this->mathsTeacher, 'sanctum')->postJson("{$this->host}/broadsheet-corrections/{$correction['id']}/decide", ['approve' => true])->assertForbidden();

        $this->actingAs($this->principal, 'sanctum')->postJson("{$this->host}/broadsheet-corrections/{$correction['id']}/decide", ['approve' => true, 'note' => 'Checked the script.'])
            ->assertOk()
            ->assertJsonPath('correction.status', 'applied')
            ->assertJsonPath('correction.applied_in_version', 2);

        $this->assertSame(93.0, (float) ScoreEntry::where('student_id', $ada->id)->where('subject_id', $this->maths->id)->value('total_score'));

        $versions = $bs->versions()->get();
        $this->assertCount(2, $versions);
        $this->assertNotNull($versions->firstWhere('version', 1)->superseded_at);
        $this->assertTrue($bs->fresh()->report_cards_stale);
    }

    public function test_exports_come_only_from_an_approved_version_and_are_logged(): void
    {
        [, , $bs] = $this->readyForReview();

        $this->actingAs($this->admin, 'sanctum')->get("{$this->host}/broadsheets/{$bs->id}/export?format=csv")->assertStatus(409);

        $this->actingAs($this->formTeacher, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")->assertOk();
        $this->actingAs($this->principal, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")->assertOk();

        $this->actingAs($this->admin, 'sanctum')->get("{$this->host}/broadsheets/{$bs->id}/export?format=csv")->assertOk();
        $response = $this->actingAs($this->admin, 'sanctum')->get("{$this->host}/broadsheets/{$bs->id}/export?format=csv")->assertOk();

        $this->assertStringContainsString('Ada Obi', $response->getContent());
        $this->assertStringNotContainsString('date_of_birth', $response->getContent());
        $this->assertSame(2, DB::table('broadsheet_exports')->where('broadsheet_id', $bs->id)->count());

        $this->actingAs($this->admin, 'sanctum')->get("{$this->host}/broadsheets/{$bs->id}/export?format=pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_subject_teacher_cannot_open_the_class_broadsheet(): void
    {
        $this->student('Ada Obi', 'A001');

        $this->actingAs($this->mathsTeacher, 'sanctum')
            ->getJson("{$this->host}/broadsheets/class?term_id={$this->term->id}&class_id={$this->class->id}")
            ->assertForbidden();

        $this->actingAs($this->mathsTeacher, 'sanctum')
            ->getJson("{$this->host}/broadsheets/subject?term_id={$this->term->id}&class_id={$this->class->id}&subject_id={$this->maths->id}")
            ->assertOk();

        $this->actingAs($this->mathsTeacher, 'sanctum')
            ->getJson("{$this->host}/broadsheets/subject?term_id={$this->term->id}&class_id={$this->class->id}&subject_id={$this->english->id}")
            ->assertForbidden();
    }

    public function test_broadsheet_events_are_append_only(): void
    {
        [, , $bs] = $this->readyForReview();
        $event = BroadsheetEvent::where('broadsheet_id', $bs->id)->first();

        $this->expectException(\LogicException::class);
        $event->update(['reason' => 'rewritten']);
    }

    // ------------------------------------------------------------------
    // Decisions D1–D3
    // ------------------------------------------------------------------

    /** D3 acceptance check 3: switching heads mode changes approvers, migrates nothing. */
    public function test_switching_to_separate_jss_head_changes_the_approver_without_migration(): void
    {
        $jssHead = $this->user('JSS Head', 'teacher');
        SectionHeadAssignment::create(['school_id' => $this->school->id, 'position' => 'level_head', 'level_group' => 'junior_secondary', 'user_id' => $jssHead->id, 'started_at' => now()]);

        // Option A (default): the principal is the level head even with a JSS head on file.
        $steps = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/sections/resolve?class_id={$this->class->id}")->assertOk()->json('steps');
        $this->assertSame(['Principal'], collect($steps)->firstWhere('role', 'level_head')['holders']);

        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/sections/settings", ['secondary_heads_mode' => 'separate_heads'])->assertOk();

        $steps = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/sections/resolve?class_id={$this->class->id}")->assertOk()->json('steps');
        $this->assertSame(['JSS Head'], collect($steps)->firstWhere('role', 'level_head')['holders']);

        [, , $bs] = $this->readyForReview();
        $this->actingAs($this->formTeacher, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")->assertOk();
        $this->actingAs($this->principal, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")->assertForbidden();
        $this->actingAs($jssHead, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")->assertOk();
    }

    public function test_a_required_chain_step_cannot_be_switched_off(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/sections/settings", [
            'approval_chains' => ['primary' => [
                ['role' => 'subject_teacher', 'enabled' => true],
                ['role' => 'class_teacher', 'enabled' => true],
                ['role' => 'level_head', 'enabled' => false],
            ]],
        ])->assertStatus(422);
    }

    /** D1 acceptance checks 1 and 2. */
    public function test_a_scheme_proposal_changes_nothing_until_approved_and_never_touches_a_scored_term(): void
    {
        $nextTerm = Term::create(['school_id' => $this->school->id, 'session_id' => $this->session->id, 'name' => 'Second Term', 'start_date' => now()->addMonths(3), 'end_date' => now()->addMonths(6)]);
        $jssHead = $this->user('JSS Head', 'teacher');
        SectionHeadAssignment::create(['school_id' => $this->school->id, 'position' => 'level_head', 'level_group' => 'junior_secondary', 'user_id' => $jssHead->id, 'started_at' => now()]);

        $ada = $this->student('Ada Obi', 'A001');
        $this->score($this->admin, $ada, $this->maths, ['first_ca' => 20, 'second_ca' => 20, 'exam' => 60])->assertOk();

        $parts = [['name' => 'CA 1', 'max' => 10, 'kind' => 'ca'], ['name' => 'CA 2', 'max' => 10, 'kind' => 'ca'], ['name' => 'Project', 'max' => 10, 'kind' => 'ca'], ['name' => 'Exam', 'max' => 70, 'kind' => 'exam']];

        // A scored term is refused outright.
        $this->actingAs($jssHead, 'sanctum')->postJson("{$this->host}/assessment/scheme-versions", [
            'name' => '10/10/10/70', 'level_group' => 'junior_secondary', 'effective_term_id' => $this->term->id, 'components' => $parts, 'propose' => true,
        ])->assertStatus(422);

        // A head may propose only for their own level.
        $this->actingAs($jssHead, 'sanctum')->postJson("{$this->host}/assessment/scheme-versions", [
            'name' => '10/10/10/70', 'level_group' => 'primary', 'effective_term_id' => $nextTerm->id, 'components' => $parts, 'propose' => true,
        ])->assertForbidden();

        $id = $this->actingAs($jssHead, 'sanctum')->postJson("{$this->host}/assessment/scheme-versions", [
            'name' => '10/10/10/70', 'level_group' => 'junior_secondary', 'effective_term_id' => $nextTerm->id, 'components' => $parts, 'propose' => true,
        ])->assertCreated()->json('scheme.id');

        $this->assertSame(3, count(CaScheme::forTerm($this->school->id, $nextTerm->id, 'junior_secondary')->parts()), 'Nothing changes while only proposed.');

        $this->actingAs($jssHead, 'sanctum')->postJson("{$this->host}/assessment/scheme-versions/{$id}/approve")->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/assessment/scheme-versions/{$id}/approve")->assertOk();

        $this->assertSame(4, count(CaScheme::forTerm($this->school->id, $nextTerm->id, 'junior_secondary')->parts()));
        $this->assertSame(3, count(CaScheme::forTerm($this->school->id, $this->term->id, 'junior_secondary')->parts()), 'The scored term keeps its scheme.');
        $this->assertSame(3, count(CaScheme::forTerm($this->school->id, $nextTerm->id, 'primary')->parts()), 'Other sections are untouched.');
    }

    public function test_release_waits_for_approval_when_the_school_uses_the_chain(): void
    {
        $this->readyForReview();
        SchoolSectionSetting::create(['school_id' => $this->school->id, 'result_workflow_enabled' => true]);

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/results/release", ['term_id' => $this->term->id, 'class_id' => $this->class->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_approved');
    }

    public function test_one_school_cannot_reach_another_schools_broadsheet(): void
    {
        [, , $bs] = $this->readyForReview();

        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other', 'domain' => 'other.schoolpilot.test']);
        $otherAdmin = User::create(['name' => 'Other Admin', 'email' => 'admin@other.test', 'password' => bcrypt('password123')]);
        $otherAdmin->userProfile()->create(['school_id' => $other->id, 'role' => 'school_admin']);

        $this->actingAs($otherAdmin, 'sanctum')->getJson("http://other.schoolpilot.test/api/v1/broadsheets/{$bs->id}/history")->assertNotFound();
    }
}
