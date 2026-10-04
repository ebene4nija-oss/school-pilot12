<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AdmissionApplication;
use App\Models\AdmissionSetting;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SectionHeadAssignment;
use App\Models\Student;
use App\Models\User;
use App\Support\TeacherScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admissions: the sections baseline D4 pipeline and its acceptance checks 4 and 5. */
class AdmissionsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private SchoolClass $jss1;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        TeacherScope::forget();

        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $this->admin = $this->user('Admin', 'school_admin');
        AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addYear(), 'is_current' => true]);
        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1', 'level_category' => 'junior_secondary']);
    }

    private function user(string $name, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@hilltop.test', 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function apply(array $overrides = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->postJson("{$this->host}/admissions", array_merge([
            'section' => 'secondary',
            'intended_class_id' => $this->jss1->id,
            'applicant_name' => 'Tobi Bello',
            'date_of_birth' => '2014-05-01',
            'gender' => 'male',
            'previous_school' => 'Sunshine Primary',
            'guardians' => [['name' => 'Mrs Bello', 'phone' => '08055550000', 'relationship' => 'mother']],
        ], $overrides))->assertCreated()->json('application');
    }

    private function receiveDocuments(int $id): void
    {
        foreach (array_keys(AdmissionSetting::DOCUMENTS) as $type) {
            $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/admissions/{$id}/documents", ['type' => $type, 'status' => 'verified'])->assertOk();
        }
    }

    public function test_the_pipeline_from_application_to_enrolment(): void
    {
        $app = $this->apply();
        $this->assertMatchesRegularExpression('/^ADM-\d{4}-\d{5}$/', $app['reference']);
        $this->assertSame(0, Student::count(), 'An applicant is not a student.');

        // No offer until the checklist is complete.
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/admissions/{$app['id']}/decision", ['decision' => 'offer', 'class_id' => $this->jss1->id])->assertStatus(409);

        $this->receiveDocuments($app['id']);

        $test = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/admissions/{$app['id']}/assessments", ['kind' => 'entrance_test', 'scheduled_at' => now()->addDay()->toDateTimeString(), 'location' => 'Hall'])->assertCreated()->json('assessment.id');
        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/admission-assessments/{$test}", ['score' => 62, 'max_score' => 100])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/admissions/{$app['id']}/decision", ['decision' => 'offer', 'class_id' => $this->jss1->id])
            ->assertOk()->assertJsonPath('application.status', 'offered');

        $this->actingAs($this->admin, 'sanctum')->get("{$this->host}/admissions/{$app['id']}/offer-letter")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/admissions/{$app['id']}/response", ['accepted' => true])->assertOk()->assertJsonPath('application.status', 'accepted');

        // The View Students "Pending" tab shows accepted applicants not yet enrolled.
        $pending = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/student-directory?tab=pending")->assertOk()->json('data');
        $this->assertSame(['Tobi Bello'], array_column($pending, 'name'));

        $studentId = $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/admissions/{$app['id']}/enrol")->assertCreated()->json('student_id');

        $student = Student::with('user')->findOrFail($studentId);
        $this->assertSame('Tobi Bello', $student->user->name);
        $this->assertSame($this->jss1->id, (int) $student->class_id);
        $this->assertNotEmpty($student->admission_number);
        $this->assertSame(1, $student->guardians()->count());
        $this->assertSame('enrolled', AdmissionApplication::find($app['id'])->status);
    }

    /** Acceptance check 4: no duplicate profile, and nothing merges by itself. */
    public function test_enrolment_pauses_on_a_likely_duplicate_until_a_person_confirms(): void
    {
        $existing = $this->user('Tobi Bello', 'student');
        Student::create(['school_id' => $this->school->id, 'user_id' => $existing->id, 'class_id' => $this->jss1->id, 'admission_number' => 'HT/1', 'date_of_birth' => '2014-05-01']);

        $app = $this->apply();
        $this->receiveDocuments($app['id']);
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/admissions/{$app['id']}/decision", ['decision' => 'offer', 'class_id' => $this->jss1->id])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/admissions/{$app['id']}/response", ['accepted' => true])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/admissions/{$app['id']}/enrol")
            ->assertStatus(409)->assertJsonPath('code', 'possible_duplicate')->assertJsonCount(1, 'possible_duplicates');
        $this->assertSame(1, Student::count());

        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/admissions/{$app['id']}/enrol", ['confirm_not_duplicate' => true])->assertCreated();
        $this->assertSame(2, Student::count());
    }

    /** Acceptance check 5. */
    public function test_unaccepted_applicants_lose_their_details_after_retention(): void
    {
        $rejected = $this->apply(['applicant_name' => 'Old Applicant']);
        $this->actingAs($this->admin, 'sanctum')->postJson("{$this->host}/admissions/{$rejected['id']}/decision", ['decision' => 'reject', 'reason' => 'Class full'])->assertOk();
        $open = $this->apply(['applicant_name' => 'Still Waiting']);

        AdmissionApplication::whereKey($rejected['id'])->update(['decided_at' => now()->subDays(400)]);
        AdmissionApplication::whereKey($open['id'])->update(['created_at' => now()->subDays(400)]);

        $this->artisan('admissions:purge')->assertSuccessful();

        $purged = AdmissionApplication::find($rejected['id']);
        $this->assertNull($purged->applicant_name);
        $this->assertNull($purged->date_of_birth);
        $this->assertSame([], $purged->guardians);
        $this->assertSame('rejected', $purged->status, 'The outcome survives for statistics.');

        $this->assertSame('Still Waiting', AdmissionApplication::find($open['id'])->applicant_name, 'Open applications are kept.');
    }

    public function test_the_public_form_is_off_until_the_school_turns_it_on(): void
    {
        $payload = ['section' => 'primary', 'applicant_name' => 'Amaka Eze', 'date_of_birth' => '2019-02-01', 'gender' => 'female', 'guardians' => [['name' => 'Mr Eze', 'phone' => '08033334444']]];

        $this->postJson("{$this->host}/public/admissions/apply", $payload)->assertNotFound();

        AdmissionSetting::create(['school_id' => $this->school->id, 'public_form_enabled' => true, 'open_sections' => ['primary']]);

        $ref = $this->postJson("{$this->host}/public/admissions/apply", $payload)->assertCreated()->json('reference');
        $this->assertSame('online', AdmissionApplication::first()->source);

        $this->postJson("{$this->host}/public/admissions/status", ['reference' => $ref, 'phone' => '0803 333 4444'])->assertOk()->assertJsonPath('status', 'Submitted');
        $this->postJson("{$this->host}/public/admissions/status", ['reference' => $ref, 'phone' => '08000000000'])->assertNotFound();
        $this->postJson("{$this->host}/public/admissions/apply", ['section' => 'secondary'] + $payload)->assertStatus(422);
    }

    public function test_a_section_head_handles_only_their_own_section(): void
    {
        $head = $this->user('Primary Head', 'teacher');
        SectionHeadAssignment::create(['school_id' => $this->school->id, 'position' => 'level_head', 'level_group' => 'primary', 'user_id' => $head->id, 'started_at' => now()]);

        $secondary = $this->apply();
        $primary = $this->apply(['section' => 'primary', 'intended_class_id' => null, 'applicant_name' => 'Little One']);

        $listed = $this->actingAs($head, 'sanctum')->getJson("{$this->host}/admissions")->assertOk()->json('data');
        $this->assertSame([$primary['id']], array_column($listed, 'id'));

        $this->actingAs($head, 'sanctum')->getJson("{$this->host}/admissions/{$secondary['id']}")->assertNotFound();

        $this->actingAs($head, 'sanctum')->postJson("{$this->host}/admissions", [
            'section' => 'secondary', 'applicant_name' => 'X Y', 'date_of_birth' => '2014-01-01', 'gender' => 'male', 'guardians' => [['name' => 'P', 'phone' => '08011112222']],
        ])->assertForbidden();

        $teacher = $this->user('Plain Teacher', 'teacher');
        $this->actingAs($teacher, 'sanctum')->getJson("{$this->host}/admissions")->assertOk()->assertJsonCount(0, 'data');
    }
}
