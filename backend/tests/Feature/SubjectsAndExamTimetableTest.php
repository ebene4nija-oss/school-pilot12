<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The subjects screen's API gaps, and exam timetables with clash detection.
 */
class SubjectsAndExamTimetableTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $teacher;

    private User $otherTeacher;

    private User $student;

    private SchoolClass $jss2;

    private Arm $gold;

    private Arm $blue;

    private Subject $maths;

    private Subject $english;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield']);
        $rival = School::create(['name' => 'Rival', 'slug' => 'rival', 'subdomain' => 'rival']);

        $this->admin = $this->person('Head Admin', 'admin@gf.test', 'school_admin', $this->school);
        $this->teacher = $this->person('Mr Bello', 'bello@gf.test', 'teacher', $this->school);
        $this->otherTeacher = $this->person('Mr Stranger', 'stranger@rival.test', 'teacher', $rival);
        $this->student = $this->person('Ada', 'ada@gf.test', 'student', $this->school);

        $this->jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $this->gold = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->jss2->id, 'name' => 'Gold']);
        $this->blue = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->jss2->id, 'name' => 'Blue']);

        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH', 'category' => 'core']);
        $this->english = Subject::create(['school_id' => $this->school->id, 'name' => 'English Language', 'code' => 'ENG', 'category' => 'core']);

        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31']);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-14', 'end_date' => '2026-12-11']);
    }

    private function person(string $name, string $email, string $role, School $school): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $school->id, 'role' => $role]);

        return $user;
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    // --------------------------------------------------------------- subjects

    public function test_a_deactivated_subject_leaves_the_pickers_but_not_the_subjects_screen(): void
    {
        $this->as($this->admin)->putJson("/api/v1/subjects/{$this->maths->id}", ['is_active' => false])->assertOk();

        $this->as($this->teacher)->getJson('/api/v1/subjects')->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->admin)->getJson('/api/v1/subjects?include_inactive=1')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_subject_code_stays_unique_on_rename(): void
    {
        $this->as($this->admin)->putJson("/api/v1/subjects/{$this->english->id}", ['code' => 'MTH'])->assertStatus(422);
        $this->as($this->admin)->putJson("/api/v1/subjects/{$this->english->id}", ['name' => 'English', 'code' => 'ENG'])->assertOk();
    }

    public function test_only_this_schools_staff_can_be_assigned_to_teach(): void
    {
        $this->as($this->admin)->postJson('/api/v1/subjects/assign-teacher', [
            'teacher_id' => $this->otherTeacher->id, 'subject_id' => $this->maths->id,
        ])->assertStatus(422);

        $this->as($this->admin)->postJson('/api/v1/subjects/assign-teacher', [
            'teacher_id' => $this->student->id, 'subject_id' => $this->maths->id,
        ])->assertStatus(422);

        $this->as($this->admin)->postJson('/api/v1/subjects/assign-teacher', [
            'teacher_id' => $this->teacher->id, 'subject_id' => $this->maths->id, 'class_id' => $this->jss2->id,
        ])->assertOk();

        $this->as($this->admin)->getJson('/api/v1/subjects/assignments')
            ->assertOk()
            ->assertJsonPath('data.0.teacher_name', 'Mr Bello')
            ->assertJsonPath('data.0.class_name', 'JSS 2');
    }

    public function test_a_subject_can_be_taken_off_a_class(): void
    {
        $this->as($this->admin)->postJson('/api/v1/classes/subjects', [
            'class_id' => $this->jss2->id,
            'subjects' => [['subject_id' => $this->maths->id, 'is_compulsory' => true], ['subject_id' => $this->english->id, 'is_compulsory' => true]],
        ])->assertOk();

        $this->as($this->admin)->deleteJson("/api/v1/classes/{$this->jss2->id}/subjects/{$this->maths->id}")->assertOk();

        $this->as($this->admin)->getJson("/api/v1/classes/{$this->jss2->id}/subjects")->assertJsonPath('total_count', 1);
    }

    public function test_removing_the_every_class_assignment_keeps_class_ones(): void
    {
        foreach ([null, $this->jss2->id] as $classId) {
            $this->as($this->admin)->postJson('/api/v1/subjects/assign-teacher', [
                'teacher_id' => $this->teacher->id, 'subject_id' => $this->maths->id, 'class_id' => $classId,
            ])->assertOk();
        }

        $this->as($this->admin)->postJson('/api/v1/subjects/remove-teacher', [
            'teacher_id' => $this->teacher->id, 'subject_id' => $this->maths->id, 'class_id' => null,
        ])->assertOk();

        $this->as($this->admin)->getJson('/api/v1/subjects/assignments')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.class_name', 'JSS 2');
    }

    // -------------------------------------------------------- exam timetable

    private function timetable(): int
    {
        return $this->as($this->admin)
            ->postJson('/api/v1/exam-timetables', ['name' => 'First Term Examination', 'term_id' => $this->term->id])
            ->assertCreated()
            ->json('data.id');
    }

    private function paper(int $timetable, array $overrides = [])
    {
        return $this->as($this->admin)->postJson("/api/v1/exam-timetables/{$timetable}/sittings", array_merge([
            'class_id' => $this->jss2->id,
            'subject_id' => $this->maths->id,
            'exam_date' => '2026-12-01',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'venue' => 'Main hall',
            'invigilator_id' => $this->teacher->id,
        ], $overrides));
    }

    public function test_a_class_cannot_sit_two_papers_at_once(): void
    {
        $id = $this->timetable();
        $this->paper($id)->assertCreated();

        $this->paper($id, ['subject_id' => $this->english->id, 'start_time' => '10:30', 'end_time' => '12:00', 'invigilator_id' => null])
            ->assertStatus(422)
            ->assertJsonPath('message', 'JSS 2 is already sitting Mathematics from 09:00 to 11:00 that day.');

        // Back to back is fine.
        $this->paper($id, ['subject_id' => $this->english->id, 'start_time' => '11:00', 'end_time' => '12:30', 'invigilator_id' => null])
            ->assertCreated();
    }

    public function test_different_arms_may_sit_different_papers_at_once_but_not_against_the_whole_class(): void
    {
        $id = $this->timetable();

        $this->paper($id, ['arm_id' => $this->gold->id, 'invigilator_id' => null])->assertCreated();
        $this->paper($id, ['arm_id' => $this->blue->id, 'subject_id' => $this->english->id, 'invigilator_id' => null])->assertCreated();

        // The whole class clashes with either arm.
        $this->paper($id, ['arm_id' => null, 'subject_id' => $this->english->id, 'invigilator_id' => null])->assertStatus(422);
    }

    public function test_one_teacher_cannot_invigilate_two_rooms_at_once(): void
    {
        $id = $this->timetable();
        $ss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'SS 1']);

        $this->paper($id)->assertCreated();

        $this->paper($id, ['class_id' => $ss1->id, 'start_time' => '10:00', 'end_time' => '12:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Mr Bello is already invigilating JSS 2 Mathematics from 09:00 to 11:00 that day.');
    }

    /** A mock running alongside the main exam clashes just the same. */
    public function test_clashes_are_checked_across_timetables(): void
    {
        $this->paper($this->timetable())->assertCreated();
        $this->paper($this->timetable(), ['subject_id' => $this->english->id, 'invigilator_id' => null])->assertStatus(422);
    }

    public function test_moving_a_paper_does_not_clash_with_itself(): void
    {
        $id = $this->timetable();
        $sitting = $this->paper($id)->json('data.id');

        $this->as($this->admin)->putJson("/api/v1/exam-sittings/{$sitting}", [
            'class_id' => $this->jss2->id, 'subject_id' => $this->maths->id,
            'exam_date' => '2026-12-01', 'start_time' => '09:30', 'end_time' => '11:30',
            'invigilator_id' => $this->teacher->id,
        ])->assertOk()->assertJsonPath('data.start_time', '09:30');
    }

    public function test_the_invigilator_must_be_staff_here(): void
    {
        $this->paper($this->timetable(), ['invigilator_id' => $this->otherTeacher->id])->assertStatus(422);
        $this->paper($this->timetable(), ['invigilator_id' => $this->student->id])->assertStatus(422);
    }

    public function test_drafts_are_hidden_from_everyone_but_admins_until_published(): void
    {
        $id = $this->timetable();
        $this->paper($id)->assertCreated();

        $this->as($this->student)->getJson('/api/v1/exam-timetables')->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->student)->getJson("/api/v1/exam-timetables/{$id}")->assertNotFound();

        $this->as($this->admin)->putJson("/api/v1/exam-timetables/{$id}", ['status' => 'published'])->assertOk();

        $this->as($this->student)->getJson('/api/v1/exam-timetables')
            ->assertOk()
            ->assertJsonPath('data.0.sittings', 1)
            ->assertJsonPath('data.0.starts_on', '2026-12-01');

        $this->as($this->teacher)->getJson("/api/v1/exam-timetables/{$id}?mine=1")
            ->assertOk()
            ->assertJsonCount(1, 'sittings')
            ->assertJsonPath('sittings.0.invigilator', 'Mr Bello');
    }

    public function test_an_empty_timetable_cannot_be_published_and_a_published_one_cannot_be_deleted(): void
    {
        $id = $this->timetable();
        $this->as($this->admin)->putJson("/api/v1/exam-timetables/{$id}", ['status' => 'published'])->assertStatus(422);

        $this->paper($id)->assertCreated();
        $this->as($this->admin)->putJson("/api/v1/exam-timetables/{$id}", ['status' => 'published'])->assertOk();
        $this->as($this->admin)->deleteJson("/api/v1/exam-timetables/{$id}")->assertStatus(409);
    }

    public function test_only_admins_build_timetables(): void
    {
        $this->as($this->teacher)->postJson('/api/v1/exam-timetables', ['name' => 'X', 'term_id' => $this->term->id])->assertForbidden();
    }
}
