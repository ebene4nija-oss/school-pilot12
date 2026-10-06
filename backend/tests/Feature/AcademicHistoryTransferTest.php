<?php

namespace Tests\Feature;

use App\Models\AcademicDocument;
use App\Models\AcademicSession;
use App\Models\Broadsheet;
use App\Models\GradingScale;
use App\Models\Guardian;
use App\Models\PreviousSchoolRecord;
use App\Models\ResultRelease;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentIdentityLink;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TermSnapshot;
use App\Models\Transfer;
use App\Models\User;
use App\Support\TeacherScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Student academic history and school transfer: the acceptance checks in
 * §13 of the build instructions.
 */
class AcademicHistoryTransferTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private School $other;
    private User $admin;
    private User $otherAdmin;
    private User $parent;
    private AcademicSession $session;
    private Term $term;
    private SchoolClass $class;
    private Subject $maths;
    private Student $ada;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';
    private string $otherHost = 'http://riverside.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        TeacherScope::forget();

        $this->school = School::create(['name' => 'Hilltop College', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $this->other = School::create(['name' => 'Riverside Academy', 'slug' => 'riverside', 'subdomain' => 'riverside', 'domain' => 'riverside.schoolpilot.test']);

        $this->admin = $this->user($this->school, 'Admin', 'school_admin');
        $this->otherAdmin = $this->user($this->other, 'Riverside Admin', 'school_admin');

        $this->session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonths(2), 'end_date' => now()->addMonths(10), 'is_current' => true]);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $this->session->id, 'name' => 'First Term', 'start_date' => now()->subMonths(2), 'end_date' => now()->subDays(5)]);
        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2', 'level_category' => 'junior_secondary']);
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);

        $user = $this->user($this->school, 'Ada Obi', 'student');
        $this->ada = Student::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'class_id' => $this->class->id, 'admission_number' => 'HT/001', 'date_of_birth' => '2013-04-01', 'gender' => 'female', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $this->school->id, 'student_id' => $this->ada->id, 'session_id' => $this->session->id, 'class_id' => $this->class->id, 'status' => 'active', 'enrolled_on' => now()->subMonths(2)->toDateString()]);

        $this->parent = $this->user($this->school, 'Mrs Obi', 'parent');
        $guardian = Guardian::create(['school_id' => $this->school->id, 'user_id' => $this->parent->id]);
        $this->ada->guardians()->attach($guardian->id, ['is_primary' => true]);
    }

    private function user(School $school, string $name, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@'.$school->subdomain.'.test', 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $school->id, 'role' => $role]);

        return $user;
    }

    /** Mark, submit and approve the class broadsheet; the admin acts for every vacant role. */
    private function approvedTerm(float $exam = 50): Broadsheet
    {
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/assessment/score", [
            'term_id' => $this->term->id, 'student_id' => $this->ada->id, 'subject_id' => $this->maths->id,
            'first_ca' => 15, 'second_ca' => 15, 'exam' => $exam,
        ])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/broadsheets/class?term_id={$this->term->id}&class_id={$this->class->id}")->assertOk();
        $bs = Broadsheet::firstOrFail();

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/subjects/{$this->maths->id}/submit")->assertOk();

        while ($bs->fresh()->status === 'in_review') {
            $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/approve")->assertOk();
        }

        return $bs->fresh();
    }

    private function closeTerm(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/academic-history/close-term", ['term_id' => $this->term->id, 'class_id' => $this->class->id])->assertOk();
    }

    // ------------------------------------------------------------------
    // History
    // ------------------------------------------------------------------

    public function test_closing_an_unapproved_term_needs_explicit_confirmation(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("{$this->host}/academic-history/close-term", ['term_id' => $this->term->id, 'class_id' => $this->class->id])
            ->assertStatus(409);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("{$this->host}/academic-history/close-term", ['term_id' => $this->term->id, 'class_id' => $this->class->id, 'accept_unapproved' => true])
            ->assertOk()
            ->assertJsonPath('from_approved_broadsheet', false);

        $this->assertSame('live_at_close', TermSnapshot::first()->source);
    }

    /** Acceptance 16 and 17: the record keeps the scale it was issued under. */
    public function test_a_later_grading_scale_change_never_rewrites_a_locked_term(): void
    {
        $this->approvedTerm(50); // 80% → A1 on WAEC
        $this->closeTerm();

        $before = TermSnapshot::first()->figures();
        $this->assertSame('A1', $before['subjects'][0]['grade']);
        $this->assertSame('A1', $before['grading_scale'][0]['grade']);

        GradingScale::create(['school_id' => $this->school->id, 'level' => 'default', 'name' => 'Letters', 'bands' => [
            ['min' => 70, 'max' => 100, 'grade' => 'A', 'remark' => 'Excellent'],
            ['min' => 0, 'max' => 69, 'grade' => 'F', 'remark' => 'Fail'],
        ]]);

        $after = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/academic-history")->assertOk()->json('terms.0.figures');

        $this->assertSame('A1', $after['subjects'][0]['grade']);
        // Equal, not identical: JSON transport turns 80.0 into 80.
        $this->assertEquals($before, $after);
        $this->assertTrue(TermSnapshot::first()->intact());
    }

    public function test_a_locked_snapshot_cannot_be_edited_or_deleted(): void
    {
        $this->approvedTerm();
        $this->closeTerm();
        $snapshot = TermSnapshot::first();

        $this->expectException(\LogicException::class);
        $snapshot->update(['data' => '{}']);
    }

    public function test_a_correction_after_close_makes_a_new_version_and_keeps_the_old(): void
    {
        $bs = $this->approvedTerm(50);
        $this->closeTerm();

        $correction = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/corrections", [
            'student_id' => $this->ada->id, 'subject_id' => $this->maths->id,
            'components' => ['ca1' => 15, 'ca2' => 15, 'exam' => 30], 'reason' => 'Exam mark keyed wrongly; script shows 30.',
        ])->assertCreated()->json('correction.id');

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/broadsheet-corrections/{$correction}/decide", ['approve' => true])->assertOk();

        $versions = TermSnapshot::where('student_id', $this->ada->id)->orderBy('version')->get();
        $this->assertCount(2, $versions);
        $this->assertNotNull($versions[0]->superseded_at);
        $this->assertSame(80.0, $versions[0]->figures()['subjects'][0]['total']);
        $this->assertSame(60.0, $versions[1]->figures()['subjects'][0]['total']);
        $this->assertSame('Exam mark keyed wrongly; script shows 30.', $versions[1]->correction_reason);
    }

    public function test_parents_see_only_released_terms_without_class_comparisons(): void
    {
        $this->approvedTerm();
        $this->closeTerm();

        $this->actingAs($this->parent, 'sanctum')->getJson("{$this->host}/parent/children/{$this->ada->id}/academic-history")
            ->assertOk()->assertJsonCount(0, 'terms');

        ResultRelease::create(['school_id' => $this->school->id, 'term_id' => $this->term->id, 'class_id' => null, 'is_released' => true, 'released_at' => now()]);

        $terms = $this->actingAs($this->parent, 'sanctum')->getJson("{$this->host}/parent/children/{$this->ada->id}/academic-history")->assertOk()->json('terms');
        $this->assertCount(1, $terms);
        $this->assertArrayNotHasKey('class_average', $terms[0]['figures']);
        $this->assertSame([], $this->actingAs($this->parent, 'sanctum')->getJson("{$this->host}/parent/children/{$this->ada->id}/academic-history")->json('documents'), 'The downloadable documents are listed (none yet).');

        // Where the school sells result PINs, the history is behind the same PIN.
        $this->school->forceFill(['result_checker_enabled' => true])->save();
        $this->actingAs($this->parent, 'sanctum')->getJson("{$this->host}/parent/children/{$this->ada->id}/academic-history")
            ->assertOk()->assertJsonCount(0, 'terms');
    }

    public function test_a_teacher_who_does_not_teach_the_student_cannot_open_their_history(): void
    {
        $teacher = $this->user($this->school, 'Stranger', 'teacher');

        $this->actingAs($teacher, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/academic-history")->assertNotFound();
    }

    /** Acceptance 23: an invalid row is never silently dropped. */
    public function test_legacy_import_reports_every_bad_row_and_imports_the_rest_unverified(): void
    {
        $csv = "admission_number,session,term,class,subject,ca,exam,total,grade,remark\n"
            ."HT/001,2024/2025,Third Term,JSS 1,Mathematics,30,45,75,A1,Excellent\n"
            ."HT/001,2024/2025,Third Term,JSS 1,English,30,40,95,A1,Excellent\n"
            ."NOPE/9,2024/2025,Third Term,JSS 1,English,30,40,70,B2,Very good\n";

        $preview = $this->actingAs($this->admin, 'sanctum')->post("{$this->host}/academic-history/import/preview", [
            'file' => UploadedFile::fake()->createWithContent('history.csv', $csv),
        ])->assertOk()->assertJsonPath('valid_rows', 1)->assertJsonPath('error_rows', 2)->json();

        $report = $this->actingAs($this->admin, 'sanctum')->get("{$this->host}/academic-history/import/{$preview['batch_id']}/errors")->assertOk()->getContent();
        $this->assertStringContainsString('does not make the total', $report);
        $this->assertStringContainsString('NOPE/9', $report);

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/academic-history/import/{$preview['batch_id']}/commit")->assertOk()->assertJsonPath('created', 1);

        $snapshot = TermSnapshot::firstOrFail();
        $this->assertSame('uploaded_unverified', $snapshot->provenance);

        $history = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/academic-history")->json();
        $this->assertTrue($history['partial_history']);

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/academic-history/snapshots/{$snapshot->id}/verify", ['status' => 'verified'])->assertOk();
        $this->assertSame('imported_verified_upload', $snapshot->fresh()->provenance);
    }

    /** Acceptance 18: editing the document breaks verification. */
    public function test_a_transcript_verifies_and_an_altered_copy_does_not(): void
    {
        $this->approvedTerm();
        $this->closeTerm();

        $doc = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/students/{$this->ada->id}/documents", ['type' => 'transcript'])->assertCreated()->json('document');
        $hash = AcademicDocument::find($doc['id'])->payload_hash;

        $this->getJson("{$this->host}/verify-document/{$doc['reference']}?h={$hash}")
            ->assertOk()->assertJsonPath('matches_issued_copy', true)->assertJsonPath('status', 'valid')
            ->assertJsonMissingPath('student_name');

        $this->getJson("{$this->host}/verify-document/{$doc['reference']}?h=".str_repeat('0', 64))
            ->assertOk()->assertJsonPath('matches_issued_copy', false);

        $this->getJson("{$this->host}/verify-document/DOC-NOT-REAL")->assertNotFound();

        $this->actingAs($this->admin, 'sanctum')->get("{$this->host}/academic-documents/{$doc['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/academic-documents/{$doc['id']}/revoke", ['reason' => 'Issued with wrong signatory', 'reissue' => true])->assertOk();
        $this->getJson("{$this->host}/verify-document/{$doc['reference']}")->assertOk()->assertJsonPath('status', 'replaced');
    }

    // ------------------------------------------------------------------
    // Transfers
    // ------------------------------------------------------------------

    private function approvedOutgoing(): Transfer
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/outgoing", [
            'student_id' => $this->ada->id, 'reason' => 'Family relocating to Abuja', 'other_school_name' => 'Riverside Academy', 'submit' => true,
        ])->assertCreated()->assertJsonPath('transfer.status', 'awaiting_consent')->json('transfer.id');

        // Fees and library clear themselves from the records; books and
        // property are ticked by staff.
        $this->actingAs($this->parent, 'sanctum')->postJson("{$this->host}/parent/transfers/{$id}/consent", ['transfer' => true, 'health' => false])
            ->assertOk()->assertJsonPath('status', 'clearance_pending');

        foreach (['books', 'property'] as $item) {
            $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/{$id}/clearance", ['item' => $item, 'status' => 'cleared'])->assertOk();
        }

        $this->assertSame('pending_approval', Transfer::findOrFail($id)->status);

        foreach (range(1, 3) as $_) {
            $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/{$id}/approve")->assertOk();
        }

        return Transfer::findOrFail($id);
    }

    public function test_school_to_school_transfer_by_code_end_to_end(): void
    {
        $this->approvedTerm();
        $this->closeTerm();

        $transfer = $this->approvedOutgoing();
        $this->assertSame('approved', $transfer->status);
        $this->assertMatchesRegularExpression('/^TRF-\d{4}-\d{6}$/', $transfer->reference);
        $this->assertNotNull($transfer->packages()->first());
        $this->assertArrayNotHasKey('health', $transfer->packages()->first()->content(), 'Health notes travel only with consent.');

        $code = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/{$transfer->id}/code")->assertOk()->json('code');

        // Wrong surname and wrong code get the same neutral answer.
        $this->actingAs($this->otherAdmin, 'sanctum')->postJson("{$this->otherHost}/transfers/incoming/redeem", ['code' => $code, 'surname' => 'Smith'])->assertNotFound();
        $this->actingAs($this->otherAdmin, 'sanctum')->postJson("{$this->otherHost}/transfers/incoming/redeem", ['code' => 'AAAA-BBBB-CCCC', 'surname' => 'Obi'])->assertNotFound();

        $incomingId = $this->actingAs($this->otherAdmin, 'sanctum')->postJson("{$this->otherHost}/transfers/incoming/redeem", ['code' => $code, 'surname' => 'Obi'])
            ->assertCreated()->json('transfer.id');

        // The code works once.
        $this->actingAs($this->otherAdmin, 'sanctum')->postJson("{$this->otherHost}/transfers/incoming/redeem", ['code' => $code, 'surname' => 'Obi'])->assertNotFound();
        $this->assertSame('records_shared', $transfer->fresh()->status);

        $record = PreviousSchoolRecord::withoutGlobalScopes()->where('school_id', $this->other->id)->firstOrFail();
        $this->assertSame('Hilltop College', $record->source_school_name);

        // Acceptance 20: the receiving school cannot edit source records.
        try {
            $record->update(['data' => '{}']);
            $this->fail('A received record was editable.');
        } catch (\LogicException) {
        }

        // Unverified records block admission.
        $otherClass = SchoolClass::create(['school_id' => $this->other->id, 'name' => 'JSS 2']);
        $this->actingAs($this->otherAdmin, 'sanctum')->postJson("{$this->otherHost}/transfers/{$incomingId}/admit", ['name' => 'Ada Obi', 'gender' => 'female', 'class_id' => $otherClass->id])->assertStatus(409);

        $this->actingAs($this->otherAdmin, 'sanctum')->postJson("{$this->otherHost}/previous-records/{$record->id}/verify", ['status' => 'verified'])->assertOk();

        $newStudentId = $this->actingAs($this->otherAdmin, 'sanctum')->postJson("{$this->otherHost}/transfers/{$incomingId}/admit", ['name' => 'Ada Obi', 'gender' => 'female', 'class_id' => $otherClass->id])
            ->assertCreated()->json('student_id');

        $this->assertNotSame($this->ada->id, $newStudentId, 'A new enrolment, never the old record re-pointed.');
        $this->assertTrue(StudentIdentityLink::where('from_student_id', $this->ada->id)->where('to_student_id', $newStudentId)->exists());
        $this->assertSame('accepted', $transfer->fresh()->status);

        // The sending school completes; nothing is deleted (acceptance 11).
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/{$transfer->id}/complete")->assertOk();

        $this->assertSame('transferred', $this->ada->fresh()->status);
        $this->assertSame('transferred_out', StudentEnrollment::where('student_id', $this->ada->id)->value('outcome'));
        $this->assertSame(1, TermSnapshot::withoutGlobalScopes()->where('student_id', $this->ada->id)->count());
        $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/academic-history")->assertOk()->assertJsonCount(1, 'terms');
    }

    public function test_a_parent_refusing_consent_stops_the_transfer(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/outgoing", ['student_id' => $this->ada->id, 'submit' => true])->json('transfer.id');

        $this->actingAs($this->parent, 'sanctum')->postJson("{$this->host}/parent/transfers/{$id}/consent", ['transfer' => false])
            ->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_only_the_childs_guardian_can_consent(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/outgoing", ['student_id' => $this->ada->id, 'submit' => true])->json('transfer.id');
        $stranger = $this->user($this->school, 'Other Parent', 'parent');

        $this->actingAs($stranger, 'sanctum')->postJson("{$this->host}/parent/transfers/{$id}/consent", ['transfer' => true])->assertForbidden();
    }

    /** Acceptance 21: a request that does not match reveals nothing. */
    public function test_a_records_request_answers_the_same_whether_or_not_it_matches(): void
    {
        $miss = $this->actingAs($this->otherAdmin, 'sanctum')->postJson("{$this->otherHost}/transfers/incoming/request", [
            'school_subdomain' => 'hilltop', 'surname' => 'Nobody', 'previous_admission_number' => 'HT/999', 'date_of_birth' => '2013-04-01',
        ])->assertStatus(202)->json();

        $hit = $this->actingAs($this->otherAdmin, 'sanctum')->postJson("{$this->otherHost}/transfers/incoming/request", [
            'school_subdomain' => 'hilltop', 'surname' => 'Obi', 'previous_admission_number' => 'HT/001', 'date_of_birth' => '2013-04-01',
        ])->assertStatus(202)->json();

        $this->assertSame($miss, $hit);
        $this->assertSame(1, Transfer::withoutGlobalScopes()->where('school_id', $this->school->id)->where('status', 'records_requested')->count());
    }

    public function test_unpaid_fees_block_release_only_when_the_school_says_so(): void
    {
        DB::table('invoices')->insert(['school_id' => $this->school->id, 'term_id' => $this->term->id, 'student_id' => $this->ada->id, 'invoice_number' => 'INV-1', 'total_amount' => 150000, 'amount_paid' => 0, 'status' => 'unpaid', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/transfers/settings", ['fees_block_release' => true])->assertOk();

        $id = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/outgoing", ['student_id' => $this->ada->id, 'submit' => true])->json('transfer.id');
        $this->actingAs($this->parent, 'sanctum')->postJson("{$this->host}/parent/transfers/{$id}/consent", ['transfer' => true])->assertJsonPath('status', 'clearance_pending');

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/{$id}/clearance", ['item' => 'fees', 'status' => 'overridden'])->assertStatus(409);

        foreach (['books', 'property'] as $item) {
            $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/{$id}/clearance", ['item' => $item, 'status' => 'cleared'])->assertOk();
        }

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/{$id}/clearance", ['item' => 'fees', 'status' => 'overridden', 'override_reason' => 'Bursar agreed a payment plan; records released.'])
            ->assertOk()->assertJsonPath('transfer.status', 'pending_approval');
    }

    public function test_push_secure_link_and_a_correction_superseding_the_package(): void
    {
        // Many calls from one signed-in user; the link's own limit is not under test.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $bs = $this->approvedTerm(50);
        $this->closeTerm();
        $transfer = $this->approvedOutgoing();

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/{$transfer->id}/push", ['school_subdomain' => 'riverside'])->assertOk();

        $incoming = Transfer::withoutGlobalScopes()->where('school_id', $this->other->id)->firstOrFail();
        $this->assertSame('awaiting_acceptance', $incoming->status);

        $url = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/{$transfer->id}/secure-link")->assertOk()->json('url');
        $token = substr($url, strrpos($url, '/') + 1);
        $this->getJson("{$this->host}/transfer-packages/{$token}")->assertOk()->assertJsonPath('package.reference', $transfer->reference);
        $this->getJson("{$this->host}/transfer-packages/not-a-real-token")->assertNotFound();

        // The sending school corrects a result after sharing (§8, Phase 4).
        $correction = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/broadsheets/{$bs->id}/corrections", [
            'student_id' => $this->ada->id, 'subject_id' => $this->maths->id,
            'components' => ['ca1' => 15, 'ca2' => 15, 'exam' => 40], 'reason' => 'Re-marked exam script.',
        ])->json('correction.id');
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/broadsheet-corrections/{$correction}/decide", ['approve' => true])->assertOk();

        $this->assertNull($this->app->make(\App\Services\History\TransferService::class)->currentPackage($transfer));
        $this->getJson("{$this->host}/transfer-packages/{$token}")->assertNotFound();
        $this->assertTrue(\App\Models\TransferEvent::withoutGlobalScopes()->where('transfer_id', $incoming->id)->where('action', 'source_corrected')->exists());

        // Accepting now fails until the sending school prepares a fresh package.
        $this->actingAs($this->otherAdmin, 'sanctum')->postJson("{$this->otherHost}/transfers/{$incoming->id}/accept")->assertStatus(409);
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/transfers/{$transfer->id}/package")->assertOk();
        $this->actingAs($this->otherAdmin, 'sanctum')->postJson("{$this->otherHost}/transfers/{$incoming->id}/accept")->assertOk()->assertJsonPath('transfer.status', 'under_verification');
    }

    public function test_another_school_cannot_read_a_transfer(): void
    {
        $transfer = $this->approvedOutgoing();

        $this->actingAs($this->otherAdmin, 'sanctum')->getJson("{$this->otherHost}/transfers/{$transfer->id}")->assertNotFound();
    }
}
