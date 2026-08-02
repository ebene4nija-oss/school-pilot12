<?php

namespace Tests\Feature;

use App\Models\School;
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
        User::create(['name' => 'Existing User', 'email' => 'existing@testschool.edu.ng', 'password' => 'pass']);

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
}
