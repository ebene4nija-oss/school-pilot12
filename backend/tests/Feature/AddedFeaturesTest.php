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

    public function test_can_verify_public_result_token()
    {
        $response = $this->getJson('/api/v1/verify-result/SAMPLE_TOKEN_1234567890', ['Host' => 'testacademy.schoolpilot.test']);

        $response->assertStatus(200)
            ->assertJsonPath('valid', true);
    }
}
