<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class StudentSISTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_student_with_encrypted_medical_fields()
    {
        $school = School::create(['name' => 'Test School', 'slug' => 'test-school', 'subdomain' => 'testschool']);
        $admin = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin']);

        $token = $admin->createToken('token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withServerVariables(['HTTP_HOST' => 'testschool.localhost'])
            ->postJson('/api/v1/students', [
                'name' => 'Emeka Okafor',
                'email' => 'emeka@testschool.edu.ng',
                'gender' => 'male',
                'state_of_origin' => 'Anambra',
                'blood_group' => 'O+',
                'allergies' => ['Penicillin', 'Nuts'],
                'medical_notes' => 'Asthmatic, keeps inhaler in bag.',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'student' => ['id', 'admission_number']]);

        $student = Student::first();
        $this->assertEquals('O+', $student->blood_group);
        $this->assertEquals(['Penicillin', 'Nuts'], $student->allergies);
        $this->assertEquals('Asthmatic, keeps inhaler in bag.', $student->medical_notes);
    }

    public function test_bulk_csv_import_handles_valid_and_invalid_rows_gracefully()
    {
        $school = School::create(['name' => 'Test School', 'slug' => 'test-school', 'subdomain' => 'testschool']);
        $admin = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin']);

        // Existing user to trigger duplicate error on row 2
        $existingUser = User::create(['name' => 'Existing User', 'email' => 'existing@testschool.edu.ng', 'password' => 'pass']);
        UserProfile::create(['school_id' => $school->id, 'user_id' => $existingUser->id, 'role' => 'student']);

        $csvContent = "Name,Email,Gender\n" .
            "Fatima Bello,fatima@testschool.edu.ng,female\n" .
            "Bad Row User,existing@testschool.edu.ng,male\n" .
            "Kelechi Iheanacho,kelechi@testschool.edu.ng,male\n";

        $file = UploadedFile::fake()->createWithContent('students.csv', $csvContent);

        $token = $admin->createToken('token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withServerVariables(['HTTP_HOST' => 'testschool.localhost'])
            ->postJson('/api/v1/students/import', [
                'file' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'successful_count' => 2,
            ]);

        $this->assertCount(1, $response->json('errors'));
        $this->assertDatabaseHas('users', ['email' => 'fatima@testschool.edu.ng']);
        $this->assertDatabaseHas('users', ['email' => 'kelechi@testschool.edu.ng']);
    }

    // ─── Promotion / Repeat / Transfer Tests ─────────────────────────

    /**
     * Helper: set up a school, admin, session, two classes, and a student in the first class.
     */
    private function seedPromotionScenario(): array
    {
        $school = School::create(['name' => 'Pilot Academy', 'slug' => 'pilot-academy', 'subdomain' => 'pilotacademy']);
        $admin = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin']);

        $session = AcademicSession::create([
            'school_id' => $school->id,
            'name' => '2025/2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-07-31',
            'is_current' => true,
        ]);

        $jss1 = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 1', 'level_category' => 'junior_secondary', 'order_index' => 1]);
        $jss2 = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 2', 'level_category' => 'junior_secondary', 'order_index' => 2]);

        $studentUser = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $studentUser->id, 'role' => 'student']);
        $student = Student::create([
            'school_id' => $school->id,
            'user_id' => $studentUser->id,
            'class_id' => $jss1->id,
            'gender' => 'male',
            'status' => 'active',
        ]);

        $token = $admin->createToken('token')->plainTextToken;

        return compact('school', 'admin', 'session', 'jss1', 'jss2', 'student', 'token');
    }

    public function test_promote_creates_history_and_updates_class()
    {
        $s = $this->seedPromotionScenario();

        $response = $this->withHeader('Authorization', 'Bearer ' . $s['token'])
            ->withServerVariables(['HTTP_HOST' => 'pilotacademy.localhost'])
            ->postJson('/api/v1/students/promote', [
                'student_ids' => [$s['student']->id],
                'target_class_id' => $s['jss2']->id,
                'session_id' => $s['session']->id,
                'action' => 'promote',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['count' => 1]);

        // Student's class_id should now be JSS 2
        $this->assertEquals($s['jss2']->id, $s['student']->fresh()->class_id);

        // A history row should exist
        $this->assertDatabaseHas('student_class_history', [
            'student_id' => $s['student']->id,
            'from_class_id' => $s['jss1']->id,
            'to_class_id' => $s['jss2']->id,
            'action' => 'promote',
            'session_id' => $s['session']->id,
        ]);
    }

    public function test_promoted_student_prior_records_are_queryable()
    {
        $s = $this->seedPromotionScenario();

        // Promote the student from JSS 1 → JSS 2
        $this->withHeader('Authorization', 'Bearer ' . $s['token'])
            ->withServerVariables(['HTTP_HOST' => 'pilotacademy.localhost'])
            ->postJson('/api/v1/students/promote', [
                'student_ids' => [$s['student']->id],
                'target_class_id' => $s['jss2']->id,
                'session_id' => $s['session']->id,
                'action' => 'promote',
            ]);

        // Query the class-history endpoint
        $response = $this->withHeader('Authorization', 'Bearer ' . $s['token'])
            ->withServerVariables(['HTTP_HOST' => 'pilotacademy.localhost'])
            ->getJson('/api/v1/students/' . $s['student']->id . '/class-history');

        $response->assertStatus(200);

        $history = $response->json('history');
        $this->assertCount(1, $history);
        $this->assertEquals($s['jss1']->id, $history[0]['from_class_id']);
        $this->assertEquals($s['jss2']->id, $history[0]['to_class_id']);
        $this->assertEquals('promote', $history[0]['action']);
    }

    public function test_repeat_keeps_student_in_same_class()
    {
        $s = $this->seedPromotionScenario();

        $response = $this->withHeader('Authorization', 'Bearer ' . $s['token'])
            ->withServerVariables(['HTTP_HOST' => 'pilotacademy.localhost'])
            ->postJson('/api/v1/students/promote', [
                'student_ids' => [$s['student']->id],
                'target_class_id' => $s['jss1']->id, // same class
                'session_id' => $s['session']->id,
                'action' => 'repeat',
            ]);

        $response->assertStatus(200);

        // Student stays in JSS 1
        $this->assertEquals($s['jss1']->id, $s['student']->fresh()->class_id);

        // History records the repeat action with same from/to
        $this->assertDatabaseHas('student_class_history', [
            'student_id' => $s['student']->id,
            'from_class_id' => $s['jss1']->id,
            'to_class_id' => $s['jss1']->id,
            'action' => 'repeat',
        ]);
    }

    public function test_transfer_updates_student_status()
    {
        $s = $this->seedPromotionScenario();

        $response = $this->withHeader('Authorization', 'Bearer ' . $s['token'])
            ->withServerVariables(['HTTP_HOST' => 'pilotacademy.localhost'])
            ->postJson('/api/v1/students/promote', [
                'student_ids' => [$s['student']->id],
                'target_class_id' => $s['jss2']->id,
                'session_id' => $s['session']->id,
                'action' => 'transfer',
                'is_leaving_school' => true,
                'remarks' => 'Transferred at parent request.',
            ]);

        $response->assertStatus(200);

        $fresh = $s['student']->fresh();
        $this->assertEquals('transferred', $fresh->status);
        $this->assertEquals($s['jss2']->id, $fresh->class_id);

        $this->assertDatabaseHas('student_class_history', [
            'student_id' => $s['student']->id,
            'action' => 'transfer',
            'remarks' => 'Transferred at parent request.',
        ]);
    }

    public function test_promote_requires_session_id()
    {
        $s = $this->seedPromotionScenario();

        $response = $this->withHeader('Authorization', 'Bearer ' . $s['token'])
            ->withServerVariables(['HTTP_HOST' => 'pilotacademy.localhost'])
            ->postJson('/api/v1/students/promote', [
                'student_ids' => [$s['student']->id],
                'target_class_id' => $s['jss2']->id,
                'action' => 'promote',
                // session_id intentionally omitted
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('session_id');
    }

    public function test_promote_is_school_scoped()
    {
        $s = $this->seedPromotionScenario();

        // Create a second school with its own admin and student
        $otherSchool = School::create(['name' => 'Other School', 'slug' => 'other-school', 'subdomain' => 'otherschool']);
        $otherStudentUser = User::factory()->create();
        UserProfile::create(['school_id' => $otherSchool->id, 'user_id' => $otherStudentUser->id, 'role' => 'student']);
        $otherStudent = Student::create([
            'school_id' => $otherSchool->id,
            'user_id' => $otherStudentUser->id,
            'class_id' => $s['jss1']->id,
            'gender' => 'female',
            'status' => 'active',
        ]);

        // Admin of Pilot Academy tries to promote the other school's student
        $response = $this->withHeader('Authorization', 'Bearer ' . $s['token'])
            ->withServerVariables(['HTTP_HOST' => 'pilotacademy.localhost'])
            ->postJson('/api/v1/students/promote', [
                'student_ids' => [$otherStudent->id],
                'target_class_id' => $s['jss2']->id,
                'session_id' => $s['session']->id,
                'action' => 'promote',
            ]);

        // Should find no matching students and return 404
        $response->assertStatus(404);

        // Other school's student should not have been moved
        $this->assertEquals($s['jss1']->id, $otherStudent->fresh()->class_id);
        $this->assertDatabaseMissing('student_class_history', [
            'student_id' => $otherStudent->id,
        ]);
    }
}

