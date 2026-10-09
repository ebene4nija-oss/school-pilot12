<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\Academics\TeachingAssignments;
use App\Support\TeacherScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Who teaches what, by section.
 *
 * Nursery and primary are taught by the class teacher, with the odd
 * specialist; secondary by subject teachers, who can differ between arms.
 * Score entry, broadsheet hand-in and who a teacher may see all follow it.
 */
class TeachingAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private AcademicSession $session;
    private Term $term;
    private SchoolClass $pry3;
    private Arm $pry3a;
    private SchoolClass $ss2;
    private Arm $ss2a;
    private Arm $ss2b;
    private Subject $maths;
    private Subject $french;
    private Subject $physics;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        TeacherScope::forget();

        $this->school = School::create(['name' => 'Kings College', 'slug' => 'kings', 'subdomain' => 'kings']);
        $this->admin = $this->user('school_admin');

        $this->session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(10)]);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $this->session->id, 'name' => 'First Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2)]);

        $this->pry3 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'Primary 3', 'level_category' => 'primary']);
        $this->pry3a = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->pry3->id, 'name' => 'A']);
        $this->ss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'SS 2', 'level_category' => 'senior_secondary']);
        $this->ss2a = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->ss2->id, 'name' => 'A', 'stream' => 'science']);
        $this->ss2b = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->ss2->id, 'name' => 'B', 'stream' => 'science']);

        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);
        $this->french = Subject::create(['school_id' => $this->school->id, 'name' => 'French']);
        $this->physics = Subject::create(['school_id' => $this->school->id, 'name' => 'Physics']);

        foreach ([[$this->pry3, $this->maths], [$this->pry3, $this->french], [$this->ss2, $this->maths], [$this->ss2, $this->physics]] as [$class, $subject]) {
            DB::table('class_subjects')->insert(['school_id' => $this->school->id, 'class_id' => $class->id, 'subject_id' => $subject->id, 'is_compulsory' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => ucfirst($role).' '.++$this->n, 'email' => "{$role}{$this->n}@kings.test", 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function pupil(SchoolClass $class, Arm $arm): Student
    {
        return Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('student')->id, 'class_id' => $class->id, 'arm_id' => $arm->id, 'admission_number' => 'K'.$this->n, 'status' => 'active']);
    }

    private function formTeacher(SchoolClass $class, ?Arm $arm, User $teacher): void
    {
        DB::table('class_teacher_assignments')->insert(['school_id' => $this->school->id, 'session_id' => $this->session->id, 'class_id' => $class->id, 'arm_id' => $arm?->id, 'form_teacher_id' => $teacher->id, 'created_at' => now(), 'updated_at' => now()]);
        TeacherScope::forget();
    }

    private function assign(User $teacher, Subject $subject, SchoolClass $class, ?Arm $arm = null, bool $replace = false)
    {
        $res = $this->actingAs($this->admin, 'sanctum')->withServerVariables(['HTTP_HOST' => 'kings.localhost'])
            ->postJson('/api/v1/subjects/assign-teacher', array_filter(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'class_id' => $class->id, 'arm_id' => $arm?->id, 'replace' => $replace ?: null]))
            ->assertOk();
        TeacherScope::forget();

        return $res;
    }

    private function score(User $teacher, Student $student, Subject $subject)
    {
        return $this->actingAs($teacher, 'sanctum')->withServerVariables(['HTTP_HOST' => 'kings.localhost'])
            ->postJson('/api/v1/assessment/score', ['term_id' => $this->term->id, 'student_id' => $student->id, 'subject_id' => $subject->id, 'first_ca' => 10, 'second_ca' => 10, 'exam' => 40]);
    }

    public function test_a_primary_class_teacher_teaches_every_subject_of_their_class()
    {
        $teacher = $this->user('teacher');
        $stranger = $this->user('teacher');
        $pupil = $this->pupil($this->pry3, $this->pry3a);
        $this->formTeacher($this->pry3, $this->pry3a, $teacher);

        $this->score($teacher, $pupil, $this->maths)->assertOk();
        $this->score($teacher, $pupil, $this->french)->assertOk();
        $this->score($stranger, $pupil, $this->maths)->assertForbidden()->assertJsonPath('code', 'not_your_subject');

        $this->assertSame([$teacher->id], app(TeachingAssignments::class)->teachersFor($this->school->id, $this->pry3->id, null, $this->maths->id));
    }

    public function test_a_specialist_takes_their_subject_from_the_class_teacher()
    {
        $teacher = $this->user('teacher');
        $frenchTeacher = $this->user('teacher');
        $pupil = $this->pupil($this->pry3, $this->pry3a);
        $this->formTeacher($this->pry3, $this->pry3a, $teacher);
        $this->assign($frenchTeacher, $this->french, $this->pry3);

        $this->score($frenchTeacher, $pupil, $this->french)->assertOk();
        $this->score($teacher, $pupil, $this->french)->assertForbidden();
        $this->score($teacher, $pupil, $this->maths)->assertOk();
    }

    public function test_secondary_subject_teachers_can_differ_by_arm()
    {
        $bello = $this->user('teacher');
        $okon = $this->user('teacher');
        $inA = $this->pupil($this->ss2, $this->ss2a);
        $inB = $this->pupil($this->ss2, $this->ss2b);

        $this->assign($bello, $this->physics, $this->ss2, $this->ss2a);
        $this->assign($okon, $this->physics, $this->ss2, $this->ss2b);

        $this->score($bello, $inA, $this->physics)->assertOk();
        $this->score($bello, $inB, $this->physics)->assertForbidden();
        $this->score($okon, $inB, $this->physics)->assertOk();

        // And Mr Bello sees SS 2A's students, not SS 2B's.
        $this->assertTrue(TeacherScope::teaches($bello, $inA));
        $this->assertFalse(TeacherScope::teaches($bello, $inB));
    }

    public function test_a_secondary_form_teacher_is_not_every_subjects_teacher()
    {
        $form = $this->user('teacher');
        $pupil = $this->pupil($this->ss2, $this->ss2a);
        $this->formTeacher($this->ss2, $this->ss2a, $form);

        $this->score($form, $pupil, $this->physics)->assertForbidden();
    }

    public function test_switching_a_section_to_subject_teachers_ends_class_teacher_rights()
    {
        $teacher = $this->user('teacher');
        $pupil = $this->pupil($this->pry3, $this->pry3a);
        $this->formTeacher($this->pry3, $this->pry3a, $teacher);

        $this->actingAs($this->admin, 'sanctum')->withServerVariables(['HTTP_HOST' => 'kings.localhost'])
            ->putJson('/api/v1/sections/settings', ['teaching_models' => ['primary' => 'subject_teacher']])
            ->assertOk()
            ->assertJsonPath('teaching_models.primary', 'subject_teacher')
            ->assertJsonPath('teaching_models.nursery', 'class_teacher');

        $this->score($teacher, $pupil, $this->maths)->assertForbidden();
    }

    public function test_replace_swaps_the_teacher_for_one_arm_only()
    {
        $old = $this->user('teacher');
        $new = $this->user('teacher');
        $other = $this->user('teacher');
        $this->assign($old, $this->physics, $this->ss2, $this->ss2a);
        $this->assign($other, $this->physics, $this->ss2, $this->ss2b);

        $this->assign($new, $this->physics, $this->ss2, $this->ss2a, true);

        $service = app(TeachingAssignments::class);
        $this->assertSame([$new->id], $service->teachersFor($this->school->id, $this->ss2->id, $this->ss2a->id, $this->physics->id));
        $this->assertSame([$other->id], $service->teachersFor($this->school->id, $this->ss2->id, $this->ss2b->id, $this->physics->id));
    }

    public function test_my_subjects_include_what_a_class_teacher_teaches()
    {
        $teacher = $this->user('teacher');
        $this->formTeacher($this->pry3, $this->pry3a, $teacher);

        $rows = collect($this->actingAs($teacher, 'sanctum')->withServerVariables(['HTTP_HOST' => 'kings.localhost'])
            ->getJson("/api/v1/teachers/{$teacher->id}/subjects")->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing(['French', 'Mathematics'], $rows->pluck('subject_name')->all());
        $this->assertSame(['class_teacher'], $rows->pluck('source')->unique()->values()->all());
        $this->assertSame(['A'], $rows->first()['arm_names']);
    }

    public function test_the_overview_shows_teachers_gaps_and_loads()
    {
        $bello = $this->user('teacher');
        $this->assign($bello, $this->physics, $this->ss2, $this->ss2a);
        $this->assign($bello, $this->maths, $this->ss2);

        $res = $this->actingAs($this->admin, 'sanctum')->withServerVariables(['HTTP_HOST' => 'kings.localhost'])
            ->getJson('/api/v1/teaching/overview?section=secondary')->assertOk();

        $res->assertJsonPath('teaching_model', 'subject_teacher');
        $physics = collect($res->json('classes.0.subjects'))->firstWhere('name', 'Physics');
        $cells = collect($physics['cells'])->keyBy('arm_id');
        $this->assertSame($bello->id, $cells[$this->ss2a->id]['teachers'][0]['id']);
        $this->assertTrue($cells[$this->ss2b->id]['missing']);
        $this->assertSame(1, $res->json('gaps'));

        $load = collect($res->json('loads'))->firstWhere('teacher_id', $bello->id);
        $this->assertSame(2, $load['subjects']);
        $this->assertSame(1, $load['classes']);

        $this->actingAs($bello, 'sanctum')->withServerVariables(['HTTP_HOST' => 'kings.localhost'])
            ->getJson('/api/v1/teaching/overview?section=secondary')->assertForbidden();
    }
}
