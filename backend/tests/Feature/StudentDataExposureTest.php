<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NDPA (doc §12): the four encrypted health columns are encrypted at rest,
 * which protects a stolen dump and nothing else — Eloquent decrypts on access,
 * so returning the model handed the plaintext straight back to any teacher who
 * listed the roster.
 */
class StudentDataExposureTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $teacher;
    private Student $student;

    private const MEDICAL_KEYS = ['blood_group', 'allergies', 'medical_notes', 'emergency_contacts'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Privacy Academy',
            'slug' => 'privacy',
            'subdomain' => 'privacy',
            'domain' => 'privacy.schoolpilot.test',
        ]);

        $this->admin = $this->user('Admin', 'admin@privacy.test', 'school_admin');
        $this->teacher = $this->user('Teacher', 'teacher@privacy.test', 'teacher');

        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $studentUser = $this->user('Sick Child', 'child@privacy.test', 'student');

        $this->student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $studentUser->id,
            'class_id' => $class->id,
            'admission_number' => 'ADM500',
            'blood_group' => 'O+',
            'allergies' => ['penicillin', 'groundnuts'],
            'medical_notes' => 'Severe asthma; inhaler kept in the school office.',
            'emergency_contacts' => [['name' => 'Aunt Ngozi', 'phone' => '08030000000']],
        ]);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function url(string $path): string
    {
        return 'http://privacy.schoolpilot.test/api/v1' . $path;
    }

    /** The headline defect. */
    public function test_roster_listing_does_not_leak_medical_data_to_teachers()
    {
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson($this->url('/students'))
            ->assertStatus(200);

        $body = $response->getContent();

        foreach (self::MEDICAL_KEYS as $key) {
            $this->assertStringNotContainsString($key, $body, "Roster response exposed '{$key}'.");
        }

        // The actual plaintext, not just the key names.
        $this->assertStringNotContainsString('Severe asthma', $body);
        $this->assertStringNotContainsString('penicillin', $body);

        // It still returns the roster it is supposed to.
        $response->assertJsonPath('data.0.admission_number', 'ADM500');
    }

    public function test_student_detail_does_not_leak_medical_data()
    {
        $body = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url("/students/{$this->student->id}"))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString('Severe asthma', $body);
        $this->assertStringNotContainsString('groundnuts', $body);
    }

    /** Even a direct model serialisation is safe, since 41 call sites touch it. */
    public function test_model_does_not_serialise_health_fields_by_default()
    {
        $array = $this->student->fresh()->toArray();

        foreach (self::MEDICAL_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $array);
        }
    }

    /** The data is still stored and readable in code — hiding, not dropping. */
    public function test_health_data_is_still_stored_and_readable_internally()
    {
        $fresh = $this->student->fresh();

        $this->assertSame('O+', $fresh->blood_group);
        $this->assertContains('penicillin', $fresh->allergies);
        $this->assertStringContainsString('asthma', $fresh->medical_notes);
    }

    public function test_admin_can_read_medical_record_through_the_dedicated_endpoint()
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url("/students/{$this->student->id}/medical"))
            ->assertStatus(200)
            ->assertJsonPath('medical.blood_group', 'O+')
            ->assertJsonPath('medical.allergies.0', 'penicillin')
            ->assertJsonPath('medical.emergency_contacts.0.name', 'Aunt Ngozi');
    }

    /** Every read of a child's health record is auditable. */
    public function test_medical_access_is_audited()
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url("/students/{$this->student->id}/medical"))
            ->assertStatus(200);

        $log = AuditLog::where('action', 'student.medical_accessed')->first();

        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame($this->student->id, (int) $log->auditable_id);
    }

    public function test_teacher_cannot_reach_the_medical_endpoint()
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->getJson($this->url("/students/{$this->student->id}/medical"))
            ->assertStatus(403);
    }

    /** A guardian sees their own child's record and no one else's. */
    public function test_guardian_sees_only_their_own_childs_medical_record()
    {
        $parent = $this->user('Parent', 'parent@privacy.test', 'parent');

        Guardian::create([
            'school_id' => $this->school->id,
            'user_id' => $parent->id,
            'full_name' => 'Parent',
            'phone' => '08031111111',
        ]);

        $guardian = Guardian::where('user_id', $parent->id)->first();
        $this->student->guardians()->attach($guardian->id);

        $this->actingAs($parent, 'sanctum')
            ->getJson($this->url("/students/{$this->student->id}/medical"))
            ->assertStatus(200)
            ->assertJsonPath('medical.blood_group', 'O+');

        $otherUser = $this->user('Other Child', 'other@privacy.test', 'student');
        $otherStudent = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $otherUser->id,
            'admission_number' => 'ADM501',
            'medical_notes' => 'Another private note.',
        ]);

        $this->actingAs($parent, 'sanctum')
            ->getJson($this->url("/students/{$otherStudent->id}/medical"))
            ->assertStatus(403);
    }
}
