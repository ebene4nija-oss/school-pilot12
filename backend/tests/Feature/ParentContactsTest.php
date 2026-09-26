<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\ClassTeacherAssignment;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Who a parent can message about their child. */
class ParentContactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_parent_sees_the_form_teacher_and_subject_teachers_of_their_own_child_only(): void
    {
        $school = School::create(['name' => 'Greenfield', 'slug' => 'gf', 'subdomain' => 'gf']);
        $person = function (string $name, string $role) use ($school) {
            $u = User::create(['name' => $name, 'email' => str_replace(' ', '.', strtolower($name)).'@gf.test', 'password' => 'x']);
            $u->userProfile()->create(['school_id' => $school->id, 'role' => $role]);

            return $u;
        };

        $session = AcademicSession::create(['school_id' => $school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonths(8)->toDateString()]);
        $jss1 = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 1']);
        $jss2 = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 2']);
        $maths = Subject::create(['school_id' => $school->id, 'name' => 'Mathematics', 'category' => 'core']);
        $english = Subject::create(['school_id' => $school->id, 'name' => 'English', 'category' => 'core']);

        $formTeacher = $person('Mrs Form', 'teacher');
        $mathsTeacher = $person('Mr Maths', 'teacher');
        $otherTeacher = $person('Mr Elsewhere', 'teacher');

        ClassTeacherAssignment::create(['school_id' => $school->id, 'session_id' => $session->id, 'class_id' => $jss1->id, 'form_teacher_id' => $formTeacher->id]);
        DB::table('teacher_subjects')->insert([
            ['school_id' => $school->id, 'teacher_id' => $mathsTeacher->id, 'subject_id' => $maths->id, 'class_id' => $jss1->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $school->id, 'teacher_id' => $formTeacher->id, 'subject_id' => $english->id, 'class_id' => $jss1->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $school->id, 'teacher_id' => $otherTeacher->id, 'subject_id' => $maths->id, 'class_id' => $jss2->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $child = Student::create(['school_id' => $school->id, 'user_id' => $person('Ada Child', 'student')->id, 'class_id' => $jss1->id, 'admission_number' => 'GF/1']);
        $parent = $person('Mrs Parent', 'parent');
        $guardian = Guardian::create(['school_id' => $school->id, 'user_id' => $parent->id, 'relationship' => 'mother']);
        $child->guardians()->attach($guardian->id, ['is_primary' => true]);

        $res = $this->actingAs($parent, 'sanctum')->getJson("/api/v1/parent/children/{$child->id}/contacts")->assertOk();

        $byName = collect($res->json('data'))->keyBy('name');
        $this->assertSame('Form teacher, English teacher', $byName['Mrs Form']['role']);
        $this->assertSame('Mathematics teacher', $byName['Mr Maths']['role']);
        $this->assertArrayNotHasKey('Mr Elsewhere', $byName->all());

        // Someone else's child.
        $stranger = $person('Mr Stranger', 'parent');
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/parent/children/{$child->id}/contacts")->assertForbidden();
    }
}
