<?php

namespace Tests\Feature;

use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Homework was one-directional before this: assignable, never returnable.
 */
class HomeworkSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private SchoolClass $class;
    private Subject $subject;
    private Homework $homework;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Bright Star',
            'slug' => 'brightstar',
            'subdomain' => 'brightstar',
            'domain' => 'brightstar.schoolpilot.test',
        ]);

        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->subject = Subject::create(['school_id' => $this->school->id, 'name' => 'English']);
        $this->teacher = $this->user('Mr Ade', 'ade@bright.test', 'teacher');
        $this->student = $this->student('Kemi Pupil', 'ADM300');

        $this->homework = Homework::create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'title' => 'Comprehension exercise',
            'description' => 'Read chapter 4 and answer the questions.',
            'due_date' => now()->addDays(3),
        ]);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function student(string $name, string $admission, ?SchoolClass $class = null): Student
    {
        $user = $this->user($name, strtolower(str_replace(' ', '', $name)) . '@bright.test', 'student');

        return Student::create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'class_id' => ($class ?? $this->class)->id,
            'admission_number' => $admission,
        ]);
    }

    private function url(string $path): string
    {
        return 'http://brightstar.schoolpilot.test/api/v1' . $path;
    }

    public function test_student_can_submit_homework()
    {
        $this->actingAs($this->student->user, 'sanctum')
            ->postJson($this->url("/academics/homework/{$this->homework->id}/submit"), [
                'body' => 'My answers are 1) a 2) b 3) c',
            ])
            ->assertStatus(201)
            ->assertJsonPath('submission.status', 'submitted')
            ->assertJsonPath('submission.is_late', false);

        $this->assertDatabaseCount('homework_submissions', 1);
    }

    public function test_resubmitting_replaces_rather_than_duplicates()
    {
        $submit = fn (string $body) => $this->actingAs($this->student->user, 'sanctum')
            ->postJson($this->url("/academics/homework/{$this->homework->id}/submit"), ['body' => $body]);

        $submit('First attempt')->assertStatus(201);
        $submit('Corrected attempt')->assertStatus(200);

        $this->assertDatabaseCount('homework_submissions', 1);
        $this->assertSame('Corrected attempt', HomeworkSubmission::first()->body);
    }

    public function test_submission_after_the_due_date_is_marked_late()
    {
        $this->homework->update(['due_date' => now()->subDays(2)]);

        $this->actingAs($this->student->user, 'sanctum')
            ->postJson($this->url("/academics/homework/{$this->homework->id}/submit"), ['body' => 'Late work'])
            ->assertStatus(201)
            ->assertJsonPath('submission.is_late', true);
    }

    /** Extending a deadline must not retroactively clear a late mark. */
    public function test_lateness_is_fixed_at_submission_time()
    {
        $this->homework->update(['due_date' => now()->subDays(2)]);

        $this->actingAs($this->student->user, 'sanctum')
            ->postJson($this->url("/academics/homework/{$this->homework->id}/submit"), ['body' => 'Late work']);

        $this->homework->update(['due_date' => now()->addWeek()]);

        $this->actingAs($this->student->user, 'sanctum')
            ->postJson($this->url("/academics/homework/{$this->homework->id}/submit"), ['body' => 'Revised'])
            ->assertJsonPath('submission.is_late', true);
    }

    public function test_student_from_another_class_cannot_submit()
    {
        $otherClass = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'SS 3']);
        $outsider = $this->student('Other Pupil', 'ADM301', $otherClass);

        $this->actingAs($outsider->user, 'sanctum')
            ->postJson($this->url("/academics/homework/{$this->homework->id}/submit"), ['body' => 'Not my class'])
            ->assertStatus(403);
    }

    /** The teacher's list must show who has *not* handed in. */
    public function test_teacher_list_includes_students_who_have_not_submitted()
    {
        $this->student('Second Pupil', 'ADM302');

        $this->actingAs($this->student->user, 'sanctum')
            ->postJson($this->url("/academics/homework/{$this->homework->id}/submit"), ['body' => 'Done']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson($this->url("/academics/homework/{$this->homework->id}/submissions"))
            ->assertStatus(200);

        $response->assertJsonPath('summary.class_size', 2)
            ->assertJsonPath('summary.submitted', 1)
            ->assertJsonPath('summary.outstanding', 1);

        $statuses = collect($response->json('submissions'))->pluck('status');
        $this->assertTrue($statuses->contains('not_submitted'));
    }

    public function test_teacher_can_grade_a_submission()
    {
        $this->actingAs($this->student->user, 'sanctum')
            ->postJson($this->url("/academics/homework/{$this->homework->id}/submit"), ['body' => 'Done']);

        $submission = HomeworkSubmission::first();

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url("/academics/submissions/{$submission->id}/grade"), [
                'marks_awarded' => 8,
                'marks_available' => 10,
                'teacher_feedback' => 'Good work, watch your spelling.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('submission.status', 'graded');

        $this->assertEquals(8.0, (float) HomeworkSubmission::first()->marks_awarded);
    }

    public function test_cannot_award_more_marks_than_available()
    {
        $this->actingAs($this->student->user, 'sanctum')
            ->postJson($this->url("/academics/homework/{$this->homework->id}/submit"), ['body' => 'Done']);

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url("/academics/submissions/" . HomeworkSubmission::first()->id . "/grade"), [
                'marks_awarded' => 15,
                'marks_available' => 10,
            ])
            ->assertStatus(422);
    }

    /** Role middleware alone would let any teacher mark a colleague's class. */
    public function test_a_teacher_cannot_mark_another_teachers_assignment()
    {
        $otherTeacher = $this->user('Mrs Nnamdi', 'nnamdi@bright.test', 'teacher');

        $this->actingAs($this->student->user, 'sanctum')
            ->postJson($this->url("/academics/homework/{$this->homework->id}/submit"), ['body' => 'Done']);

        $this->actingAs($otherTeacher, 'sanctum')
            ->postJson($this->url("/academics/submissions/" . HomeworkSubmission::first()->id . "/grade"), [
                'marks_awarded' => 10,
                'marks_available' => 10,
            ])
            ->assertStatus(403);

        $this->actingAs($otherTeacher, 'sanctum')
            ->getJson($this->url("/academics/homework/{$this->homework->id}/submissions"))
            ->assertStatus(403);
    }

    public function test_a_marked_submission_cannot_be_edited_by_the_student()
    {
        $this->actingAs($this->student->user, 'sanctum')
            ->postJson($this->url("/academics/homework/{$this->homework->id}/submit"), ['body' => 'Done']);

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url("/academics/submissions/" . HomeworkSubmission::first()->id . "/grade"), [
                'marks_awarded' => 5, 'marks_available' => 10,
            ]);

        $this->actingAs($this->student->user, 'sanctum')
            ->postJson($this->url("/academics/homework/{$this->homework->id}/submit"), ['body' => 'Sneaky rewrite'])
            ->assertStatus(409);

        $this->assertSame('Done', HomeworkSubmission::first()->body);
    }

    public function test_teacher_can_bulk_grade_a_class()
    {
        $second = $this->student('Bulk Two', 'ADM310');

        foreach ([$this->student, $second] as $pupil) {
            $this->actingAs($pupil->user, 'sanctum')
                ->postJson($this->url("/academics/homework/{$this->homework->id}/submit"), ['body' => 'Done']);
        }

        $marks = HomeworkSubmission::all()->map(fn ($s) => [
            'submission_id' => $s->id,
            'marks_awarded' => 7,
        ])->all();

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url("/academics/homework/{$this->homework->id}/bulk-grade"), [
                'marks_available' => 10,
                'marks' => $marks,
            ])
            ->assertStatus(200)
            ->assertJsonPath('graded', 2);

        $this->assertSame(2, HomeworkSubmission::where('status', 'graded')->count());
    }
}
