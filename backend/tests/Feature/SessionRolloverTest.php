<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionRolloverTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://pilotacademy.localhost/api/v1';

    private function seedSchool(): array
    {
        $school = School::create(['name' => 'Pilot Academy', 'slug' => 'pilot-academy', 'subdomain' => 'pilotacademy']);

        $admin = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin']);

        $outgoing = AcademicSession::create([
            'school_id' => $school->id,
            'name' => '2025/2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-07-31',
            'is_current' => true,
        ]);

        $incoming = AcademicSession::create([
            'school_id' => $school->id,
            'name' => '2026/2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-07-31',
            'is_current' => false,
        ]);

        $term = Term::create([
            'school_id' => $school->id,
            'session_id' => $outgoing->id,
            'name' => 'Third Term',
            'start_date' => '2026-04-01',
            'end_date' => '2026-07-31',
            'is_current' => true,
        ]);

        $jss1 = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 1', 'level_category' => 'junior_secondary', 'order_index' => 1]);
        $jss2 = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 2', 'level_category' => 'junior_secondary', 'order_index' => 2]);
        $ss3 = SchoolClass::create(['school_id' => $school->id, 'name' => 'SS 3', 'level_category' => 'senior_secondary', 'order_index' => 6, 'is_exit_class' => true]);

        return [
            'school' => $school,
            'admin' => $admin,
            'outgoing' => $outgoing,
            'incoming' => $incoming,
            'term' => $term,
            'jss1' => $jss1,
            'jss2' => $jss2,
            'ss3' => $ss3,
            'token' => $admin->createToken('t')->plainTextToken,
        ];
    }

    private function enrolStudent(array $s, SchoolClass $class, ?float $average = null, ?Arm $arm = null): Student
    {
        $user = User::factory()->create();
        UserProfile::create(['school_id' => $s['school']->id, 'user_id' => $user->id, 'role' => 'student']);

        $student = Student::create([
            'school_id' => $s['school']->id,
            'user_id' => $user->id,
            'class_id' => $class->id,
            'arm_id' => $arm?->id,
            'gender' => 'male',
            'status' => 'active',
        ]);

        StudentEnrollment::create([
            'school_id' => $s['school']->id,
            'student_id' => $student->id,
            'session_id' => $s['outgoing']->id,
            'class_id' => $class->id,
            'arm_id' => $arm?->id,
            'status' => 'active',
            'enrolled_on' => '2025-09-01',
        ]);

        if ($average !== null) {
            $subject = Subject::create(['school_id' => $s['school']->id, 'name' => 'Mathematics ' . $student->id, 'code' => 'MTH']);

            ScoreEntry::create([
                'school_id' => $s['school']->id,
                'term_id' => $s['term']->id,
                'student_id' => $student->id,
                'subject_id' => $subject->id,
                'first_ca' => 0,
                'second_ca' => 0,
                'exam' => $average,
                'total_score' => $average,
                'grade' => 'C',
            ]);
        }

        return $student;
    }

    private function asAdmin(array $s)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $s['token']);
    }

    // ─── Preview ────────────────────────────────────────────────────

    public function test_preview_proposes_promote_repeat_and_graduate()
    {
        $s = $this->seedSchool();

        $passing = $this->enrolStudent($s, $s['jss1'], 72.0);
        $failing = $this->enrolStudent($s, $s['jss1'], 21.0);
        $leaver = $this->enrolStudent($s, $s['ss3'], 65.0);

        $response = $this->asAdmin($s)->postJson(self::HOST . '/students/rollover/preview', [
            'from_session_id' => $s['outgoing']->id,
            'pass_mark' => 40,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.promote', 1)
            ->assertJsonPath('summary.repeat', 1)
            ->assertJsonPath('summary.graduate', 1);

        $rows = collect($response->json('students'))->keyBy('student_id');

        $this->assertEquals('promote', $rows[$passing->id]['proposed_action']);
        $this->assertEquals($s['jss2']->id, $rows[$passing->id]['proposed_class_id']);

        $this->assertEquals('repeat', $rows[$failing->id]['proposed_action']);
        $this->assertEquals($s['jss1']->id, $rows[$failing->id]['proposed_class_id']);

        $this->assertEquals('graduate', $rows[$leaver->id]['proposed_action']);
        $this->assertNull($rows[$leaver->id]['proposed_class_id']);
    }

    /**
     * A child who arrived in the third term has no scores. Averaging that to
     * zero would hold them back for having joined late.
     */
    public function test_student_without_scores_is_proposed_for_promotion_and_flagged()
    {
        $s = $this->seedSchool();
        $newcomer = $this->enrolStudent($s, $s['jss1'], null);

        $response = $this->asAdmin($s)->postJson(self::HOST . '/students/rollover/preview', [
            'from_session_id' => $s['outgoing']->id,
        ]);

        $response->assertStatus(200)->assertJsonPath('summary.without_scores', 1);

        $row = collect($response->json('students'))->firstWhere('student_id', $newcomer->id);

        $this->assertEquals('promote', $row['proposed_action']);
        $this->assertNull($row['session_average']);
        $this->assertStringContainsString('No scores', $row['reason']);
    }

    public function test_preview_writes_nothing()
    {
        $s = $this->seedSchool();
        $student = $this->enrolStudent($s, $s['jss1'], 80.0);

        $this->asAdmin($s)->postJson(self::HOST . '/students/rollover/preview', [
            'from_session_id' => $s['outgoing']->id,
        ])->assertStatus(200);

        $this->assertEquals($s['jss1']->id, $student->fresh()->class_id);
        $this->assertEquals(1, StudentEnrollment::withoutGlobalScopes()->count());
        $this->assertEquals('active', StudentEnrollment::withoutGlobalScopes()->first()->status);
    }

    public function test_preview_can_be_scoped_to_one_class()
    {
        $s = $this->seedSchool();
        $this->enrolStudent($s, $s['jss1'], 80.0);
        $this->enrolStudent($s, $s['ss3'], 80.0);

        $this->asAdmin($s)->postJson(self::HOST . '/students/rollover/preview', [
            'from_session_id' => $s['outgoing']->id,
            'class_id' => $s['jss1']->id,
        ])->assertStatus(200)->assertJsonPath('summary.total', 1);
    }

    // ─── Commit ─────────────────────────────────────────────────────

    public function test_commit_promotes_and_opens_a_new_session_enrollment()
    {
        $s = $this->seedSchool();
        $silver = Arm::create(['school_id' => $s['school']->id, 'class_id' => $s['jss2']->id, 'name' => 'Silver']);
        $student = $this->enrolStudent($s, $s['jss1'], 75.0);

        $this->asAdmin($s)->postJson(self::HOST . '/students/rollover/commit', [
            'from_session_id' => $s['outgoing']->id,
            'to_session_id' => $s['incoming']->id,
            'decisions' => [[
                'student_id' => $student->id,
                'action' => 'promote',
                'target_class_id' => $s['jss2']->id,
                'target_arm_id' => $silver->id,
            ]],
        ])->assertStatus(200)->assertJsonPath('applied_count', 1);

        $fresh = $student->fresh();
        $this->assertEquals($s['jss2']->id, $fresh->class_id);
        $this->assertEquals($silver->id, $fresh->arm_id);
        $this->assertEquals('active', $fresh->status);

        $this->assertDatabaseHas('student_enrollments', [
            'student_id' => $student->id,
            'session_id' => $s['outgoing']->id,
            'status' => 'closed',
            'outcome' => 'promoted',
        ]);

        $this->assertDatabaseHas('student_enrollments', [
            'student_id' => $student->id,
            'session_id' => $s['incoming']->id,
            'class_id' => $s['jss2']->id,
            'arm_id' => $silver->id,
            'status' => 'active',
            'outcome' => null,
        ]);
    }

    public function test_repeat_opens_the_new_session_in_the_same_class()
    {
        $s = $this->seedSchool();
        $student = $this->enrolStudent($s, $s['jss1'], 18.0);

        $this->asAdmin($s)->postJson(self::HOST . '/students/rollover/commit', [
            'from_session_id' => $s['outgoing']->id,
            'to_session_id' => $s['incoming']->id,
            'decisions' => [[
                'student_id' => $student->id,
                'action' => 'repeat',
                'target_class_id' => $s['jss1']->id,
            ]],
        ])->assertStatus(200);

        $this->assertEquals($s['jss1']->id, $student->fresh()->class_id);

        $this->assertDatabaseHas('student_enrollments', [
            'student_id' => $student->id,
            'session_id' => $s['outgoing']->id,
            'outcome' => 'repeated',
        ]);

        $this->assertDatabaseHas('student_enrollments', [
            'student_id' => $student->id,
            'session_id' => $s['incoming']->id,
            'class_id' => $s['jss1']->id,
            'status' => 'active',
        ]);
    }

    /**
     * The gap that made the old endpoint unusable in July: a target class was
     * required, so the top year of the school could never be moved on.
     */
    public function test_graduating_needs_no_target_class_and_creates_no_new_enrollment()
    {
        $s = $this->seedSchool();
        $student = $this->enrolStudent($s, $s['ss3'], 68.0);

        $this->asAdmin($s)->postJson(self::HOST . '/students/rollover/commit', [
            'from_session_id' => $s['outgoing']->id,
            'to_session_id' => $s['incoming']->id,
            'decisions' => [[
                'student_id' => $student->id,
                'action' => 'graduate',
            ]],
        ])->assertStatus(200)->assertJsonPath('applied_count', 1);

        $this->assertEquals('graduated', $student->fresh()->status);

        $this->assertDatabaseHas('student_enrollments', [
            'student_id' => $student->id,
            'session_id' => $s['outgoing']->id,
            'outcome' => 'graduated',
            'status' => 'closed',
        ]);

        $this->assertDatabaseMissing('student_enrollments', [
            'student_id' => $student->id,
            'session_id' => $s['incoming']->id,
        ]);
    }

    public function test_transfer_out_records_destination_and_closes_the_enrollment()
    {
        $s = $this->seedSchool();
        $student = $this->enrolStudent($s, $s['jss1'], 55.0);

        $this->asAdmin($s)->postJson(self::HOST . '/students/rollover/commit', [
            'from_session_id' => $s['outgoing']->id,
            'to_session_id' => $s['incoming']->id,
            'decisions' => [[
                'student_id' => $student->id,
                'action' => 'transfer_out',
                'destination_school' => 'Command Secondary School, Jos',
                'remarks' => 'Family relocated.',
            ]],
        ])->assertStatus(200);

        $this->assertEquals('transferred', $student->fresh()->status);

        $this->assertDatabaseHas('student_enrollments', [
            'student_id' => $student->id,
            'outcome' => 'transferred_out',
            'destination_school' => 'Command Secondary School, Jos',
        ]);

        $this->assertDatabaseMissing('student_enrollments', [
            'student_id' => $student->id,
            'session_id' => $s['incoming']->id,
        ]);
    }

    public function test_promotion_without_a_target_class_is_rejected()
    {
        $s = $this->seedSchool();
        $student = $this->enrolStudent($s, $s['jss1'], 75.0);

        $this->asAdmin($s)->postJson(self::HOST . '/students/rollover/commit', [
            'from_session_id' => $s['outgoing']->id,
            'to_session_id' => $s['incoming']->id,
            'decisions' => [[
                'student_id' => $student->id,
                'action' => 'promote',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('decisions.0.target_class_id');
    }

    public function test_an_arm_from_another_class_is_rejected()
    {
        $s = $this->seedSchool();
        $jss1Gold = Arm::create(['school_id' => $s['school']->id, 'class_id' => $s['jss1']->id, 'name' => 'Gold']);
        $student = $this->enrolStudent($s, $s['jss1'], 75.0);

        $this->asAdmin($s)->postJson(self::HOST . '/students/rollover/commit', [
            'from_session_id' => $s['outgoing']->id,
            'to_session_id' => $s['incoming']->id,
            'decisions' => [[
                'student_id' => $student->id,
                'action' => 'promote',
                'target_class_id' => $s['jss2']->id,
                'target_arm_id' => $jss1Gold->id,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('decisions.0.target_arm_id');
    }

    public function test_rolling_into_the_same_session_is_rejected()
    {
        $s = $this->seedSchool();
        $student = $this->enrolStudent($s, $s['jss1'], 75.0);

        $this->asAdmin($s)->postJson(self::HOST . '/students/rollover/commit', [
            'from_session_id' => $s['outgoing']->id,
            'to_session_id' => $s['outgoing']->id,
            'decisions' => [[
                'student_id' => $student->id,
                'action' => 'promote',
                'target_class_id' => $s['jss2']->id,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('to_session_id');
    }

    public function test_another_schools_student_is_reported_as_skipped_not_moved()
    {
        $s = $this->seedSchool();

        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);
        $outsiderUser = User::factory()->create();
        UserProfile::create(['school_id' => $other->id, 'user_id' => $outsiderUser->id, 'role' => 'student']);
        $outsider = Student::create([
            'school_id' => $other->id,
            'user_id' => $outsiderUser->id,
            'class_id' => $s['jss1']->id,
            'gender' => 'female',
            'status' => 'active',
        ]);

        $response = $this->asAdmin($s)->postJson(self::HOST . '/students/rollover/commit', [
            'from_session_id' => $s['outgoing']->id,
            'to_session_id' => $s['incoming']->id,
            'decisions' => [[
                'student_id' => $outsider->id,
                'action' => 'promote',
                'target_class_id' => $s['jss2']->id,
            ]],
        ]);

        $response->assertStatus(200)->assertJsonPath('applied_count', 0);
        $this->assertCount(1, $response->json('skipped'));
        $this->assertEquals($s['jss1']->id, $outsider->fresh()->class_id);
    }

    public function test_commit_is_atomic_across_the_cohort()
    {
        $s = $this->seedSchool();
        $first = $this->enrolStudent($s, $s['jss1'], 75.0);
        $second = $this->enrolStudent($s, $s['jss1'], 75.0);

        // A class id from nowhere fails validation before anything is written.
        $this->asAdmin($s)->postJson(self::HOST . '/students/rollover/commit', [
            'from_session_id' => $s['outgoing']->id,
            'to_session_id' => $s['incoming']->id,
            'decisions' => [
                ['student_id' => $first->id, 'action' => 'promote', 'target_class_id' => $s['jss2']->id],
                ['student_id' => $second->id, 'action' => 'promote', 'target_class_id' => 999999],
            ],
        ])->assertStatus(422);

        $this->assertEquals($s['jss1']->id, $first->fresh()->class_id);
        $this->assertEquals($s['jss1']->id, $second->fresh()->class_id);
        $this->assertEquals(0, StudentEnrollment::withoutGlobalScopes()->where('session_id', $s['incoming']->id)->count());
    }

    // ─── Single-student exit and history ────────────────────────────

    public function test_a_student_can_be_withdrawn_mid_session()
    {
        $s = $this->seedSchool();
        $student = $this->enrolStudent($s, $s['jss1'], 60.0);

        $this->asAdmin($s)->postJson(self::HOST . '/students/' . $student->id . '/exit', [
            'action' => 'withdraw',
            'remarks' => 'Fees unpaid for two terms; parent withdrew the child.',
            'left_on' => '2026-03-14',
        ])->assertStatus(200);

        $this->assertEquals('withdrawn', $student->fresh()->status);

        $this->assertDatabaseHas('student_enrollments', [
            'student_id' => $student->id,
            'status' => 'closed',
            'outcome' => 'withdrawn',
        ]);
    }

    public function test_enrollment_history_is_readable_per_session()
    {
        $s = $this->seedSchool();
        $student = $this->enrolStudent($s, $s['jss1'], 75.0);

        StudentEnrollment::create([
            'school_id' => $s['school']->id,
            'student_id' => $student->id,
            'session_id' => $s['incoming']->id,
            'class_id' => $s['jss2']->id,
            'status' => 'active',
            'enrolled_on' => '2026-09-01',
        ]);

        $response = $this->asAdmin($s)->getJson(self::HOST . '/students/' . $student->id . '/enrollments');

        $response->assertStatus(200);
        $enrollments = $response->json('enrollments');

        $this->assertCount(2, $enrollments);
        // Newest session first.
        $this->assertEquals('2026/2027', $enrollments[0]['session']);
        $this->assertEquals('JSS 2', $enrollments[0]['class']);
        $this->assertEquals('2025/2026', $enrollments[1]['session']);
        $this->assertEquals('JSS 1', $enrollments[1]['class']);
    }

    /**
     * The legacy endpoint must not leave the new record behind, or students
     * moved with it would be missing from the next rollover preview.
     */
    public function test_legacy_promote_endpoint_keeps_enrollments_in_step()
    {
        $s = $this->seedSchool();
        $student = $this->enrolStudent($s, $s['jss1'], 75.0);

        $this->asAdmin($s)->postJson(self::HOST . '/students/promote', [
            'student_ids' => [$student->id],
            'target_class_id' => $s['jss2']->id,
            'session_id' => $s['incoming']->id,
            'action' => 'promote',
        ])->assertStatus(200);

        $this->assertDatabaseHas('student_enrollments', [
            'student_id' => $student->id,
            'session_id' => $s['incoming']->id,
            'class_id' => $s['jss2']->id,
            'status' => 'active',
        ]);
    }

    public function test_legacy_promote_rejects_another_schools_target_class()
    {
        $s = $this->seedSchool();
        $student = $this->enrolStudent($s, $s['jss1'], 75.0);

        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);
        $foreignClass = SchoolClass::create(['school_id' => $other->id, 'name' => 'JSS 2', 'order_index' => 2]);

        $this->asAdmin($s)->postJson(self::HOST . '/students/promote', [
            'student_ids' => [$student->id],
            'target_class_id' => $foreignClass->id,
            'session_id' => $s['outgoing']->id,
            'action' => 'promote',
        ])->assertStatus(422)->assertJsonValidationErrors('target_class_id');

        $this->assertEquals($s['jss1']->id, $student->fresh()->class_id);
    }
}
