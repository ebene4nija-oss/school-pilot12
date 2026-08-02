<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase1And2Test extends TestCase
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

    public function test_can_create_scholarship()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/finance/scholarships', [
                'name' => 'Merit Excellence Award',
                'type' => 'percentage',
                'value' => 50,
                'description' => '50% tuition waiver',
            ], ['Host' => 'testacademy.schoolpilot.test']);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Scholarship created');
    }

    public function test_can_update_hardware_free_bus_location()
    {
        $busId = DB::table('buses')->insertGetId([
            'school_id' => $this->school->id,
            'bus_number' => 'BUS-01',
            'driver_name' => 'Driver John',
            'driver_phone' => '08012345678',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bus_routes')->insert([
            'school_id' => $this->school->id,
            'bus_id' => $busId,
            'route_name' => 'Lekki Route',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/transport/bus/{$busId}/gps-ping", [
                'lat' => 6.458985,
                'lng' => 3.424355,
            ], ['Host' => 'testacademy.schoolpilot.test']);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Bus GPS location updated');
    }

    public function test_can_record_clinic_visit()
    {
        $studentUser = User::create([
            'name' => 'Jane Student',
            'email' => 'jane@testacademy.com',
            'password' => bcrypt('password123'),
        ]);

        $student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $studentUser->id,
            'admission_number' => 'ADM002',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/health/clinic-visit', [
                'student_id' => $student->id,
                'symptoms' => 'Mild fever and headache',
                'treatment' => 'Administered Paracetamol 500mg',
                'attending_nurse' => 'Nurse Grace',
            ], ['Host' => 'testacademy.schoolpilot.test']);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Clinic visit recorded successfully');
    }
}
