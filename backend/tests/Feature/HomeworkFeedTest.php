<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `GET /academics/homework` — gap G5.
 *
 * Homework could be set and submitted, and could not be *found*: the only way
 * to reach an assignment was to already hold its id. A student could post to
 * `/homework/{id}/submit` with no route that would ever tell them an id, while
 * the parent feed carried a homework panel — so a guardian could see work their
 * own child could not.
 */
class HomeworkFeedTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private SchoolClass $jss2;
    private SchoolClass $ss1;
    private Subject $maths;
    private User $teacher;
    private User $parent;
    private Student $chidi;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15');

        $this->school = School::create([
            'name' => 'Graceland College', 'slug' => 'graceland', 'subdomain' => 'graceland',
        ]);

        $this->jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $this->ss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'SS 1']);
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);

        $this->teacher = $this->user('Mr Bello', 'bello@graceland.test', 'teacher');
        $this->parent = $this->user('Mrs Okeke', 'okeke@graceland.test', 'parent');

        $this->chidi = $this->student('Chidi Okeke', $this->jss2);

        $guardian = Guardian::create([
            'school_id' => $this->school->id,
            'user_id' => $this->parent->id,
            'relationship' => 'mother',
        ]);
        $guardian->students()->attach($this->chidi->id, ['is_primary' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        UserProfile::create(['user_id' => $user->id, 'school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function student(string $name, SchoolClass $class): Student
    {
        $email = str_replace(' ', '', strtolower($name)) . '@graceland.test';

        return Student::create([
            'school_id' => $this->school->id,
            'user_id' => $this->user($name, $email, 'student')->id,
            'class_id' => $class->id,
            'admission_number' => 'GC/' . $class->id . '/' . substr(md5($name), 0, 5),
        ]);
    }

    private function homework(string $title, SchoolClass $class, string $due, ?User $teacher = null): Homework
    {
        return Homework::create([
            'school_id' => $this->school->id,
            'class_id' => $class->id,
            'subject_id' => $this->maths->id,
            'teacher_id' => ($teacher ?? $this->teacher)->id,
            'title' => $title,
            'description' => 'Exercise 4, questions 1 to 10.',
            'due_date' => $due,
        ]);
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    // ------------------------------------------------------------- Student

    public function test_a_student_sees_the_work_set_for_their_own_class()
    {
        $mine = $this->homework('Quadratics', $this->jss2, '2026-10-20');
        $this->homework('Calculus', $this->ss1, '2026-10-20');

        $response = $this->as($this->chidi->user)
            ->getJson('/api/v1/academics/homework')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine->id, $response->json('data.0.id'));
        $this->assertSame('Mathematics', $response->json('data.0.subject'));
        $this->assertSame('Mr Bello', $response->json('data.0.teacher'));
    }

    public function test_a_students_own_submission_state_rides_along()
    {
        $done = $this->homework('Submitted work', $this->jss2, '2026-10-20');
        $this->homework('Outstanding work', $this->jss2, '2026-10-22');

        HomeworkSubmission::create([
            'school_id' => $this->school->id,
            'homework_id' => $done->id,
            'student_id' => $this->chidi->id,
            'body' => 'Here it is.',
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);

        $rows = collect(
            $this->as($this->chidi->user)->getJson('/api/v1/academics/homework')->json('data')
        )->keyBy('title');

        $this->assertTrue($rows['Submitted work']['submitted']);
        $this->assertSame('submitted', $rows['Submitted work']['status']);
        $this->assertFalse($rows['Outstanding work']['submitted']);
        $this->assertSame('not_submitted', $rows['Outstanding work']['status']);
    }

    /**
     * Overdue is decided server-side so three clients cannot each decide
     * whether work due this afternoon already counts as late.
     */
    public function test_overdue_matches_the_lateness_rule_submissions_use()
    {
        $this->homework('Due today', $this->jss2, '2026-10-15');
        $this->homework('Due yesterday', $this->jss2, '2026-10-14');

        $rows = collect(
            $this->as($this->chidi->user)->getJson('/api/v1/academics/homework')->json('data')
        )->keyBy('title');

        // A submission is late only after the end of the due day, so work due
        // today is not overdue this morning.
        $this->assertFalse($rows['Due today']['overdue']);
        $this->assertTrue($rows['Due yesterday']['overdue']);
    }

    /** Nine years of assignments is not the question a pupil is asking. */
    public function test_long_finished_work_is_left_out_unless_asked_for()
    {
        $this->homework('Last term', $this->jss2, '2026-06-01');
        $this->homework('This week', $this->jss2, '2026-10-16');

        $this->as($this->chidi->user)
            ->getJson('/api/v1/academics/homework')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->as($this->chidi->user)
            ->getJson('/api/v1/academics/homework?include_past=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // -------------------------------------------------------------- Parent

    public function test_a_guardian_sees_their_own_childs_work()
    {
        $this->homework('Quadratics', $this->jss2, '2026-10-20');
        $this->homework('Calculus', $this->ss1, '2026-10-20');

        $response = $this->as($this->parent)
            ->getJson('/api/v1/academics/homework')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Quadratics', $response->json('data.0.title'));
    }

    public function test_a_guardian_cannot_ask_for_a_child_who_is_not_theirs()
    {
        $stranger = $this->student('Tunde Bello', $this->ss1);
        $this->homework('Calculus', $this->ss1, '2026-10-20');

        // Not a 403: the pivot simply does not link them, so there is no child
        // to answer about.
        $this->as($this->parent)
            ->getJson("/api/v1/academics/homework?student_id={$stranger->id}")
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_a_guardian_with_two_children_can_choose_between_them()
    {
        $ada = $this->student('Ada Okeke', $this->ss1);
        Guardian::where('user_id', $this->parent->id)->first()
            ->students()->attach($ada->id, ['is_primary' => false]);

        $this->homework('Quadratics', $this->jss2, '2026-10-20');
        $this->homework('Calculus', $this->ss1, '2026-10-20');

        $this->as($this->parent)
            ->getJson("/api/v1/academics/homework?student_id={$ada->id}")
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Calculus');
    }

    public function test_an_unlinked_parent_is_told_rather_than_shown_an_error()
    {
        $unlinked = $this->user('New Parent', 'new@graceland.test', 'parent');
        $this->homework('Quadratics', $this->jss2, '2026-10-20');

        $this->as($unlinked)
            ->getJson('/api/v1/academics/homework')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    // ------------------------------------------------------------- Teacher

    public function test_a_teacher_sees_their_own_assignments_by_default()
    {
        $mine = $this->homework('Mine', $this->jss2, '2026-10-20');
        $colleague = $this->user('Mrs Adeyemi', 'adeyemi@graceland.test', 'teacher');
        $this->homework('Theirs', $this->jss2, '2026-10-20', $colleague);

        $response = $this->as($this->teacher)
            ->getJson('/api/v1/academics/homework')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    /** Covering a colleague's class is staff business, unlike a child's answer. */
    public function test_a_teacher_can_widen_to_a_whole_class()
    {
        $this->homework('Mine', $this->jss2, '2026-10-20');
        $colleague = $this->user('Mrs Adeyemi', 'adeyemi@graceland.test', 'teacher');
        $this->homework('Theirs', $this->jss2, '2026-10-20', $colleague);

        $this->as($this->teacher)
            ->getJson("/api/v1/academics/homework?class_id={$this->jss2->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /** Staff see the assignment, never one named child's answer to it. */
    public function test_a_teachers_listing_carries_no_pupils_submission()
    {
        $work = $this->homework('Quadratics', $this->jss2, '2026-10-20');

        HomeworkSubmission::create([
            'school_id' => $this->school->id,
            'homework_id' => $work->id,
            'student_id' => $this->chidi->id,
            'body' => 'My private answer.',
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);

        $body = $this->as($this->teacher)->getJson('/api/v1/academics/homework')->getContent();

        $this->assertStringNotContainsString('My private answer.', $body);
        $this->assertStringNotContainsString('submitted_at', $body);
    }

    // ------------------------------------------------------ Tenancy & shape

    public function test_another_schools_homework_is_never_listed()
    {
        $rival = School::create(['name' => 'Rival', 'slug' => 'rival', 'subdomain' => 'rival']);
        $rivalClass = SchoolClass::create(['school_id' => $rival->id, 'name' => 'JSS 2']);
        $rivalSubject = Subject::create(['school_id' => $rival->id, 'name' => 'Maths', 'code' => 'M']);

        Homework::create([
            'school_id' => $rival->id,
            'class_id' => $rivalClass->id,
            'subject_id' => $rivalSubject->id,
            'teacher_id' => $this->teacher->id,
            'title' => 'Their work',
            'description' => 'x',
            'due_date' => '2026-10-20',
        ]);

        $this->homework('Our work', $this->jss2, '2026-10-20');

        $this->as($this->chidi->user)
            ->getJson('/api/v1/academics/homework')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Our work');
    }

    /** Flat `data` plus a sibling `meta` — never rows at `data.data.N`. */
    public function test_the_list_is_paginated_in_the_shape_the_other_lists_use()
    {
        foreach (range(1, 5) as $i) {
            $this->homework("Work {$i}", $this->jss2, '2026-10-20');
        }

        $this->as($this->chidi->user)
            ->getJson('/api/v1/academics/homework?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_the_route_requires_authentication()
    {
        $this->getJson('/api/v1/academics/homework')->assertStatus(401);
    }
}
