<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentRecordChange;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Support\TeacherScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** View Students page: the acceptance checklist in §14 of its instructions. */
class StudentDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $subjectTeacher;
    private User $formTeacher;
    private AcademicSession $session;
    private SchoolClass $jss1;
    private SchoolClass $jss2;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        TeacherScope::forget();

        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $this->admin = $this->user('Admin', 'school_admin');
        $this->subjectTeacher = $this->user('Subject Teacher', 'teacher');
        $this->formTeacher = $this->user('Form Teacher', 'teacher');

        $this->session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addYear(), 'is_current' => true]);
        Term::create(['school_id' => $this->school->id, 'session_id' => $this->session->id, 'name' => 'First Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2)]);
        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);

        $maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);
        DB::table('teacher_subjects')->insert(['school_id' => $this->school->id, 'teacher_id' => $this->subjectTeacher->id, 'subject_id' => $maths->id, 'class_id' => $this->jss1->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('class_teacher_assignments')->insert(['school_id' => $this->school->id, 'session_id' => $this->session->id, 'class_id' => $this->jss1->id, 'form_teacher_id' => $this->formTeacher->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(string $name, string $role, ?School $school = null): User
    {
        $school ??= $this->school;
        $user = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@'.$school->subdomain.'.test', 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $school->id, 'role' => $role]);

        return $user;
    }

    private function student(string $name, SchoolClass $class, string $gender = 'female', string $outcome = null, ?string $guardianPhone = null): Student
    {
        static $n = 0;
        $n++;
        $user = $this->user($name, 'student');
        $student = Student::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'class_id' => $class->id, 'admission_number' => sprintf('HT/%03d', $n), 'gender' => $gender, 'date_of_birth' => '2013-01-0'.min(9, $n), 'status' => match ($outcome) { 'transferred_out' => 'transferred', 'withdrawn' => 'withdrawn', default => 'active' }]);

        StudentEnrollment::create([
            'school_id' => $this->school->id, 'student_id' => $student->id, 'session_id' => $this->session->id, 'class_id' => $class->id,
            'status' => $outcome ? 'closed' : 'active', 'outcome' => $outcome, 'enrolled_on' => now()->subMonth()->toDateString(),
        ]);

        if ($guardianPhone) {
            $parent = $this->user("Parent of {$name}", 'parent');
            $parent->userProfile->update(['phone' => $guardianPhone]);
            $guardian = Guardian::create(['school_id' => $this->school->id, 'user_id' => $parent->id]);
            $student->guardians()->attach($guardian->id, ['is_primary' => true]);
        }

        return $student;
    }

    private function list(User $as, string $query = '')
    {
        return $this->actingAs($as, 'sanctum')->getJson("{$this->host}/student-directory?{$query}");
    }

    public function test_card_counts_match_the_list_their_filter_produces(): void
    {
        $this->student('Ada Obi', $this->jss1);
        $this->student('Bayo Ade', $this->jss1, 'male');
        $this->student('Chi Eze', $this->jss2, 'female', 'transferred_out');
        $this->student('Dayo Ola', $this->jss2, 'male', 'withdrawn');

        $res = $this->list($this->admin)->assertOk()->json();
        $cards = collect($res['cards'])->keyBy('key');

        $this->assertSame(4, $cards['total']['count']);
        $this->assertSame(2, $cards['active']['count']);
        $this->assertSame(2, $cards['left']['count']);

        foreach (['total', 'active', 'boys', 'girls'] as $key) {
            $listed = $this->list($this->admin, http_build_query($cards[$key]['filter']))->json('meta.total');
            $this->assertSame($cards[$key]['count'], $listed, "Card {$key} does not match its list.");
        }
    }

    /** Acceptance 18. */
    public function test_leavers_are_not_active_and_appear_under_their_own_tab(): void
    {
        $this->student('Ada Obi', $this->jss1);
        $this->student('Chi Eze', $this->jss2, 'female', 'transferred_out');

        $active = collect($this->list($this->admin, 'tab=active')->json('data'))->pluck('name');
        $this->assertContains('Ada Obi', $active);
        $this->assertNotContains('Chi Eze', $active);

        $this->assertSame(['Chi Eze'], collect($this->list($this->admin, 'tab=transferred')->json('data'))->pluck('name')->all());

        // There is no delete.
        $this->assertFalse(collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->contains(fn ($r) => str_starts_with($r->uri(), 'api/v1/student-directory') && in_array('DELETE', $r->methods(), true) && ! str_contains($r->uri(), 'views')));
    }

    /** Acceptance 2. */
    public function test_search_by_name_in_any_order_admission_number_and_guardian(): void
    {
        $ada = $this->student('Ada Ngozi Obi', $this->jss1, 'female', null, '08031234567');
        $this->student('Bayo Ade', $this->jss1);

        $this->assertSame([$ada->id], collect($this->list($this->admin, 'search=obi+ada')->json('data'))->pluck('id')->all());
        $this->assertSame([$ada->id], collect($this->list($this->admin, 'search='.urlencode($ada->admission_number))->json('data'))->pluck('id')->all());
        $this->assertSame([$ada->id], collect($this->list($this->admin, 'search=parent+of+ada')->json('data'))->pluck('id')->all());
        $this->assertSame([$ada->id], collect($this->list($this->admin, 'search=08031234567')->json('data'))->pluck('id')->all());
    }

    /** Acceptance 5, 19, 20. */
    public function test_a_subject_teacher_sees_only_their_classes_and_no_sensitive_columns(): void
    {
        $mine = $this->student('Ada Obi', $this->jss1, 'female', null, '08031234567');
        $other = $this->student('Bayo Ade', $this->jss2);

        $res = $this->list($this->subjectTeacher)->assertOk()->json();
        $this->assertSame([$mine->id], collect($res['data'])->pluck('id')->all());
        $this->assertSame('Showing students in your classes.', $res['meta']['scope_note']);

        $row = $res['data'][0];
        $this->assertNull($row['fee_status']);
        $this->assertNull($row['date_of_birth']);
        $this->assertNull($row['guardian_phone'], 'Subject teachers see guardian phones only if the school allows it.');
        $this->assertArrayNotHasKey('allergies', $row);

        // Nor by direct URL, search or export.
        $this->actingAs($this->subjectTeacher, 'sanctum')->getJson("{$this->host}/student-directory/{$other->id}/quick-view")->assertNotFound();
        $this->assertSame([], $this->list($this->subjectTeacher, 'search=bayo')->json('data'));
        $this->actingAs($this->subjectTeacher, 'sanctum')->get("{$this->host}/student-directory/export?class_id={$this->jss2->id}")->assertForbidden();
    }

    public function test_a_class_teacher_exports_only_a_plain_class_list_and_it_is_logged(): void
    {
        $this->student('Ada Obi', $this->jss1, 'female', null, '08031234567');

        $csv = $this->actingAs($this->formTeacher, 'sanctum')->get("{$this->host}/student-directory/export?class_id={$this->jss1->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Ada Obi', $csv);
        $this->assertStringNotContainsString('08031234567', $csv);

        $this->actingAs($this->formTeacher, 'sanctum')->getJson("{$this->host}/student-directory/export?class_id={$this->jss1->id}&fields[]=guardian_phone")->assertStatus(422);
        $this->actingAs($this->formTeacher, 'sanctum')->get("{$this->host}/student-directory/export?class_id={$this->jss2->id}")->assertForbidden();

        $this->assertTrue(AuditLog::where('action', 'students.exported')->where('user_id', $this->formTeacher->id)->exists());
    }

    public function test_a_suspension_shows_only_to_those_who_manage_it(): void
    {
        $ada = $this->student('Ada Obi', $this->jss1);
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/student-directory/{$ada->id}/status", ['status' => 'suspended', 'reason' => 'Disciplinary panel decision 12/10'])->assertOk();

        $this->assertSame('suspended', $this->list($this->admin, 'tab=all')->json('data.0.status'));
        $this->assertSame('active', $this->list($this->subjectTeacher, 'tab=active')->json('data.0.status'));
    }

    /** Acceptance 16 and 12: per-record results, and the change history with reasons. */
    public function test_bulk_move_reports_every_record_and_records_the_reason(): void
    {
        $ada = $this->student('Ada Obi', $this->jss1);
        $chi = $this->student('Chi Eze', $this->jss1, 'female', 'transferred_out');

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/student-directory/bulk/preview", [
            'action' => 'move_class', 'student_ids' => [$ada->id, $chi->id, 99999], 'class_id' => $this->jss2->id, 'reason' => 'Arm rebalancing',
        ])->assertOk()->assertJsonPath('count', 2)->assertJsonPath('destination', 'JSS 2')->assertJsonPath('not_found', [99999]);

        // Unconfirmed: refused.
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/student-directory/bulk", [
            'action' => 'move_class', 'student_ids' => [$ada->id], 'class_id' => $this->jss2->id, 'reason' => 'Arm rebalancing',
        ])->assertStatus(422);

        $res = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/student-directory/bulk", [
            'action' => 'move_class', 'student_ids' => [$ada->id, $chi->id, 99999], 'class_id' => $this->jss2->id, 'reason' => 'Arm rebalancing', 'confirm' => true,
        ])->assertOk()->json();

        $this->assertSame(1, $res['succeeded']);
        $this->assertSame(2, $res['failed']);
        $this->assertCount(3, $res['results']);
        $this->assertSame($this->jss2->id, (int) $ada->fresh()->class_id);
        $this->assertSame($this->jss2->id, (int) StudentEnrollment::where('student_id', $ada->id)->value('class_id'));

        $change = StudentRecordChange::where('student_id', $ada->id)->where('field', 'class_id')->first();
        $this->assertSame('Arm rebalancing', $change->reason);
        $this->assertSame((string) $this->jss1->id, $change->old_value);

        // Running it again changes nothing and still succeeds.
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/student-directory/bulk", [
            'action' => 'move_class', 'student_ids' => [$ada->id], 'class_id' => $this->jss2->id, 'reason' => 'Arm rebalancing', 'confirm' => true,
        ])->assertOk()->assertJsonPath('succeeded', 1);
        $this->assertSame(1, StudentRecordChange::where('student_id', $ada->id)->where('field', 'class_id')->count());
    }

    public function test_teachers_cannot_run_bulk_actions(): void
    {
        $ada = $this->student('Ada Obi', $this->jss1);

        $this->actingAs($this->formTeacher, 'sanctum')->postJson("{$this->host}/student-directory/bulk", [
            'action' => 'change_status', 'student_ids' => [$ada->id], 'status' => 'withdrawn', 'reason' => 'x x x', 'confirm' => true,
        ])->assertForbidden();
    }

    /** Acceptance 21. */
    public function test_a_likely_duplicate_is_flagged_and_nothing_merges(): void
    {
        $this->student('Ada Ngozi Obi', $this->jss1, 'female', null, '08031234567');

        $res = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/student-directory/duplicate-check", [
            'name' => 'Obi Ada', 'guardian_phone' => '0803 123 4567',
        ])->assertOk()->json();

        $this->assertCount(1, $res['possible_duplicates']);
        $this->assertContains('Same guardian phone', $res['possible_duplicates'][0]['reasons']);
        $this->assertSame(1, Student::count(), 'Checking creates and merges nothing.');
    }

    public function test_incomplete_records_are_identified(): void
    {
        $this->student('Ada Obi', $this->jss1, 'female', null, '08031234567');
        $this->student('Bayo Ade', $this->jss1);

        $chips = collect($this->list($this->admin)->json('chips'))->keyBy('key');
        $this->assertSame(1, $chips['incomplete']['count']);

        $rows = $this->list($this->admin, 'record_status=incomplete')->json('data');
        $this->assertSame(['Bayo Ade'], array_column($rows, 'name'));
        $this->assertContains('At least one guardian', $rows[0]['missing_fields']);
    }

    /** Acceptance 14. */
    public function test_one_school_cannot_see_anothers_students(): void
    {
        $this->student('Ada Obi', $this->jss1);
        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other', 'domain' => 'other.schoolpilot.test']);
        $otherAdmin = $this->user('Other Admin', 'school_admin', $other);

        $this->actingAs($otherAdmin, 'sanctum')->getJson('http://other.schoolpilot.test/api/v1/student-directory?tab=all')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
