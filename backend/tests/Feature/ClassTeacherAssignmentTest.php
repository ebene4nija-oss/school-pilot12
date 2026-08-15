<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassTeacherAssignment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassTeacherAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function seedSchool(): array
    {
        $school = School::create(['name' => 'Pilot Academy', 'slug' => 'pilot-academy', 'subdomain' => 'pilotacademy']);

        $admin = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin']);

        $teacher = User::factory()->create(['name' => 'Mrs Adeyemi']);
        UserProfile::create(['school_id' => $school->id, 'user_id' => $teacher->id, 'role' => 'teacher']);

        $session = AcademicSession::create([
            'school_id' => $school->id,
            'name' => '2025/2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-07-31',
            'is_current' => true,
        ]);

        $jss2 = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 2', 'level_category' => 'junior_secondary', 'order_index' => 2]);
        $gold = Arm::create(['school_id' => $school->id, 'class_id' => $jss2->id, 'name' => 'Gold']);

        return [
            'school' => $school,
            'admin' => $admin,
            'teacher' => $teacher,
            'session' => $session,
            'jss2' => $jss2,
            'gold' => $gold,
            'token' => $admin->createToken('t')->plainTextToken,
        ];
    }

    private function assign(array $s, array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $s['token'])
            ->postJson('http://pilotacademy.localhost/api/v1/class-teachers', $payload);
    }

    public function test_admin_can_assign_a_form_teacher_to_an_arm()
    {
        $s = $this->seedSchool();

        $response = $this->assign($s, [
            'session_id' => $s['session']->id,
            'class_id' => $s['jss2']->id,
            'arm_id' => $s['gold']->id,
            'form_teacher_id' => $s['teacher']->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('class_teacher_assignments', [
            'school_id' => $s['school']->id,
            'session_id' => $s['session']->id,
            'class_id' => $s['jss2']->id,
            'arm_id' => $s['gold']->id,
            'form_teacher_id' => $s['teacher']->id,
        ]);
    }

    public function test_reassigning_the_same_placement_replaces_rather_than_duplicates()
    {
        $s = $this->seedSchool();

        $second = User::factory()->create(['name' => 'Mr Bello']);
        UserProfile::create(['school_id' => $s['school']->id, 'user_id' => $second->id, 'role' => 'teacher']);

        $payload = [
            'session_id' => $s['session']->id,
            'class_id' => $s['jss2']->id,
            'arm_id' => $s['gold']->id,
            'form_teacher_id' => $s['teacher']->id,
        ];

        $this->assign($s, $payload)->assertStatus(201);
        $this->assign($s, array_merge($payload, ['form_teacher_id' => $second->id]))->assertStatus(201);

        $this->assertEquals(1, ClassTeacherAssignment::withoutGlobalScopes()->count());
        $this->assertEquals(
            $second->id,
            ClassTeacherAssignment::withoutGlobalScopes()->first()->form_teacher_id
        );
    }

    /**
     * The NULL-arm case the unique index cannot hold on its own.
     */
    public function test_unstreamed_class_cannot_take_two_form_teachers()
    {
        $s = $this->seedSchool();

        $second = User::factory()->create();
        UserProfile::create(['school_id' => $s['school']->id, 'user_id' => $second->id, 'role' => 'teacher']);

        $payload = [
            'session_id' => $s['session']->id,
            'class_id' => $s['jss2']->id,
            'arm_id' => null,
            'form_teacher_id' => $s['teacher']->id,
        ];

        $this->assign($s, $payload)->assertStatus(201);
        $this->assign($s, array_merge($payload, ['form_teacher_id' => $second->id]))->assertStatus(201);

        $this->assertEquals(1, ClassTeacherAssignment::withoutGlobalScopes()->count());
    }

    public function test_a_parent_cannot_be_made_a_form_teacher()
    {
        $s = $this->seedSchool();

        $parent = User::factory()->create();
        UserProfile::create(['school_id' => $s['school']->id, 'user_id' => $parent->id, 'role' => 'parent']);

        $this->assign($s, [
            'session_id' => $s['session']->id,
            'class_id' => $s['jss2']->id,
            'arm_id' => $s['gold']->id,
            'form_teacher_id' => $parent->id,
        ])->assertStatus(422)->assertJsonValidationErrors('form_teacher_id');
    }

    public function test_another_schools_teacher_cannot_be_assigned()
    {
        $s = $this->seedSchool();

        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);
        $outsider = User::factory()->create();
        UserProfile::create(['school_id' => $other->id, 'user_id' => $outsider->id, 'role' => 'teacher']);

        $this->assign($s, [
            'session_id' => $s['session']->id,
            'class_id' => $s['jss2']->id,
            'arm_id' => $s['gold']->id,
            'form_teacher_id' => $outsider->id,
        ])->assertStatus(422)->assertJsonValidationErrors('form_teacher_id');
    }

    public function test_another_schools_class_cannot_be_assigned()
    {
        $s = $this->seedSchool();

        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);
        $foreignClass = SchoolClass::create(['school_id' => $other->id, 'name' => 'JSS 3', 'order_index' => 3]);

        $this->assign($s, [
            'session_id' => $s['session']->id,
            'class_id' => $foreignClass->id,
            'form_teacher_id' => $s['teacher']->id,
        ])->assertStatus(422)->assertJsonValidationErrors('class_id');
    }

    public function test_listing_shows_assigned_and_unassigned_placements_with_head_counts()
    {
        $s = $this->seedSchool();

        Arm::create(['school_id' => $s['school']->id, 'class_id' => $s['jss2']->id, 'name' => 'Silver']);

        $studentUser = User::factory()->create();
        UserProfile::create(['school_id' => $s['school']->id, 'user_id' => $studentUser->id, 'role' => 'student']);
        Student::create([
            'school_id' => $s['school']->id,
            'user_id' => $studentUser->id,
            'class_id' => $s['jss2']->id,
            'arm_id' => $s['gold']->id,
            'gender' => 'female',
            'status' => 'active',
        ]);

        $this->assign($s, [
            'session_id' => $s['session']->id,
            'class_id' => $s['jss2']->id,
            'arm_id' => $s['gold']->id,
            'form_teacher_id' => $s['teacher']->id,
        ])->assertStatus(201);

        $response = $this->withHeader('Authorization', 'Bearer ' . $s['token'])
            ->getJson('http://pilotacademy.localhost/api/v1/class-teachers?session_id=' . $s['session']->id);

        $response->assertStatus(200)
            ->assertJsonPath('meta.total_placements', 2)
            ->assertJsonPath('meta.unassigned', 1);

        $gold = collect($response->json('data'))->firstWhere('arm_name', 'Gold');
        $this->assertEquals('Mrs Adeyemi', $gold['form_teacher']['name']);
        $this->assertEquals(1, $gold['student_count']);
    }

    public function test_teacher_sees_only_their_own_classes()
    {
        $s = $this->seedSchool();

        /*
         * Seeded directly rather than through the assign endpoint. Two
         * authenticated requests in one test share a resolved auth guard, so
         * the second call would still be acting as the admin — and this test
         * is about what the *teacher* sees.
         */
        ClassTeacherAssignment::create([
            'school_id' => $s['school']->id,
            'session_id' => $s['session']->id,
            'class_id' => $s['jss2']->id,
            'arm_id' => $s['gold']->id,
            'form_teacher_id' => $s['teacher']->id,
        ]);

        $teacherToken = $s['teacher']->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $teacherToken)
            ->getJson('http://pilotacademy.localhost/api/v1/teacher/my-classes');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('JSS 2', $data[0]['class_name']);
        $this->assertEquals('form_teacher', $data[0]['role']);
    }

    public function test_teacher_cannot_assign_form_teachers()
    {
        $s = $this->seedSchool();

        $teacherToken = $s['teacher']->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $teacherToken)
            ->postJson('http://pilotacademy.localhost/api/v1/class-teachers', [
                'session_id' => $s['session']->id,
                'class_id' => $s['jss2']->id,
                'arm_id' => $s['gold']->id,
                'form_teacher_id' => $s['teacher']->id,
            ])->assertStatus(403);
    }
}
