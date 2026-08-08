<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Models\Student;
use App\Models\Guardian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddedFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected $school;
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Test Academy',
            'slug' => 'testacademy',
            'subdomain' => 'testacademy',
            'domain' => 'testacademy.schoolpilot.test',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@testacademy.com',
            'password' => bcrypt('password123'),
        ]);

        $this->admin->userProfile()->create([
            'school_id' => $this->school->id,
            'role' => 'school_admin',
        ]);
    }

    public function test_can_record_and_withdraw_parental_consent()
    {
        $studentUser = User::create([
            'name' => 'John Student',
            'email' => 'john@testacademy.com',
            'password' => bcrypt('password123'),
        ]);
        $studentUser->userProfile()->create(['school_id' => $this->school->id, 'role' => 'student']);

        $student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $studentUser->id,
            'admission_number' => 'ADM001',
        ]);

        $guardianUser = User::create([
            'name' => 'Mary Parent',
            'email' => 'mary@testacademy.com',
            'password' => bcrypt('password123'),
        ]);
        $guardianUser->userProfile()->create(['school_id' => $this->school->id, 'role' => 'parent']);

        $guardian = Guardian::create([
            'school_id' => $this->school->id,
            'user_id' => $guardianUser->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/compliance/parental-consent', [
                'guardian_id' => $guardian->id,
                'student_id' => $student->id,
                'consent_given' => true,
                'notes' => 'Parent agreed via mobile app',
            ], ['Host' => 'testacademy.schoolpilot.test']);

        $response->assertStatus(200)
            ->assertJsonPath('data.consent_given', true);

        $consentId = $response->json('data.id');

        $withdrawResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/compliance/parental-consent/{$consentId}/withdraw", [], ['Host' => 'testacademy.schoolpilot.test']);

        $withdrawResponse->assertStatus(200)
            ->assertJsonPath('data.consent_given', false);
    }

    public function test_can_export_school_data()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/export-data', [], ['Host' => 'testacademy.schoolpilot.test']);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_can_send_and_retrieve_teacher_parent_messages()
    {
        $parentUser = User::create([
            'name' => 'Parent User',
            'email' => 'parent@testacademy.com',
            'password' => bcrypt('password123'),
        ]);
        $parentUser->userProfile()->create(['school_id' => $this->school->id, 'role' => 'parent']);

        $sendResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/messages/send', [
                'recipient_id' => $parentUser->id,
                'subject' => 'Academic Update',
                'body' => 'Hello, please check your child homework.',
            ], ['Host' => 'testacademy.schoolpilot.test']);

        $sendResponse->assertStatus(201)
            ->assertJsonPath('data.body', 'Hello, please check your child homework.');

        $threadsResponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/messages/threads', ['Host' => 'testacademy.schoolpilot.test']);

        $threadsResponse->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /**
     * A token that was actually issued verifies, and reports the school that
     * issued it.
     */
    public function test_issued_report_card_token_verifies()
    {
        [$student, $term] = $this->makeStudentAndTerm();

        $token = \App\Models\ReportCardToken::issueFor($this->school->id, $student->id, $term->id);

        $this->getJson("http://testacademy.schoolpilot.test/api/v1/verify-result/{$token->qr_token}")
            ->assertStatus(200)
            ->assertJsonPath('valid', true)
            ->assertJsonPath('school_name', 'Test Academy')
            ->assertJsonPath('student_name', 'Verifiable Student');
    }

    /**
     * The old implementation answered `valid: true` with an invented student
     * and school for *any* string while APP_ENV was 'testing'. An unissued
     * token must be a flat 404.
     */
    public function test_unissued_token_is_rejected_rather_than_fabricated()
    {
        $this->getJson('http://testacademy.schoolpilot.test/api/v1/verify-result/SAMPLE_TOKEN_1234567890')
            ->assertStatus(404)
            ->assertJsonPath('valid', false);
    }

    /**
     * The enumeration hole: `SP_VERIFY_5` used to be rewritten to
     * `score_entries.id = 5` with no tenant scope, so results were walkable by
     * integer from an unauthenticated endpoint.
     */
    public function test_score_entry_ids_are_not_enumerable_through_the_verifier()
    {
        [$student, $term] = $this->makeStudentAndTerm();
        $subject = \App\Models\Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);

        $entry = \App\Models\ScoreEntry::create([
            'school_id' => $this->school->id,
            'term_id' => $term->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'first_ca' => 18, 'second_ca' => 17, 'exam' => 50,
            'total_score' => 85, 'grade' => 'A1',
        ]);

        foreach (["SP_VERIFY_{$entry->id}", "TOKEN_{$entry->id}", (string) $entry->id] as $probe) {
            $this->getJson("http://testacademy.schoolpilot.test/api/v1/verify-result/{$probe}")
                ->assertStatus(404);
        }
    }

    /** A withdrawn card stops verifying. */
    public function test_revoked_token_no_longer_verifies()
    {
        [$student, $term] = $this->makeStudentAndTerm();

        $token = \App\Models\ReportCardToken::issueFor($this->school->id, $student->id, $term->id);
        $token->update(['is_valid' => false]);

        $this->getJson("http://testacademy.schoolpilot.test/api/v1/verify-result/{$token->qr_token}")
            ->assertStatus(404);
    }

    /** @return array{0: Student, 1: \App\Models\Term} */
    private function makeStudentAndTerm(): array
    {
        $studentUser = User::create([
            'name' => 'Verifiable Student',
            'email' => 'verify@testacademy.com',
            'password' => bcrypt('password123'),
        ]);
        $studentUser->userProfile()->create(['school_id' => $this->school->id, 'role' => 'student']);

        $student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $studentUser->id,
            'admission_number' => 'ADM-VER-1',
        ]);

        $session = \App\Models\AcademicSession::create([
            'school_id' => $this->school->id,
            'name' => '2025/2026',
            'start_date' => now(),
            'end_date' => now()->addYear(),
        ]);

        $term = \App\Models\Term::create([
            'school_id' => $this->school->id,
            'session_id' => $session->id,
            'name' => 'First Term',
            'start_date' => now(),
            'end_date' => now()->addMonths(3),
        ]);

        return [$student, $term];
    }
}
