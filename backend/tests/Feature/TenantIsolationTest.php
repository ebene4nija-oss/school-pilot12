<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_query_cannot_return_another_schools_data()
    {
        // 1. Create School A
        $schoolA = School::create([
            'name' => 'School A',
            'slug' => 'school-a',
            'subdomain' => 'schoola',
        ]);

        $userA = User::factory()->create();
        UserProfile::create([
            'school_id' => $schoolA->id,
            'user_id' => $userA->id,
            'role' => 'school_admin',
        ]);

        $studentA = Student::create([
            'school_id' => $schoolA->id,
            'user_id' => $userA->id,
            'blood_group' => 'O+',
            'allergies' => ['Peanuts'],
        ]);

        // 2. Create School B
        $schoolB = School::create([
            'name' => 'School B',
            'slug' => 'school-b',
            'subdomain' => 'schoolb',
        ]);

        $userB = User::factory()->create();
        UserProfile::create([
            'school_id' => $schoolB->id,
            'user_id' => $userB->id,
            'role' => 'school_admin',
        ]);

        $studentB = Student::create([
            'school_id' => $schoolB->id,
            'user_id' => $userB->id,
            'blood_group' => 'A+',
            'allergies' => ['Dust'],
        ]);

        // 3. Act as User A (School A Admin)
        $this->actingAs($userA);

        $students = Student::all();

        // 4. Assert School A only sees Student A, not Student B
        $this->assertCount(1, $students);
        $this->assertEquals($studentA->id, $students->first()->id);
        $this->assertNotEquals($studentB->id, $students->first()->id);
    }
}
