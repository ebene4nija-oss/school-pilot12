<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The AI comment review queue.
 *
 * An AI-generated remark never reaches a report card unreviewed. That was
 * enforced on the write side and nowhere else: there was no endpoint that could
 * list what was waiting, so the admin dashboard displayed two invented students
 * and the real queue was unreachable from the portal. A gate nobody can see
 * through is one that gets worked around at term end.
 */
class PendingAiCommentQueueTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $teacher;

    private Term $term;

    private Subject $maths;

    private Subject $english;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Greenfield Academy',
            'slug' => 'greenfield',
            'subdomain' => 'greenfield',
            'status' => 'active',
        ]);

        $this->admin = $this->makeUser('Greenfield Admin', 'admin@greenfield.test', 'school_admin');
        $this->teacher = $this->makeUser('Mr Chidi', 'chidi@greenfield.test', 'teacher');

        $session = DB::table('academic_sessions')->insertGetId([
            'school_id' => $this->school->id,
            'name' => '2025/2026',
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'is_current' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->term = Term::create([
            'school_id' => $this->school->id,
            'session_id' => $session,
            'name' => 'First Term',
            'start_date' => now()->subMonths(2)->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'is_current' => true,
        ]);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 3', 'level' => 'JSS']);

        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);
        $this->english = Subject::create(['school_id' => $this->school->id, 'name' => 'English', 'code' => 'ENG']);

        $studentUser = User::create([
            'name' => 'Chidi Nnamdi', 'email' => 'student@greenfield.test', 'password' => bcrypt('password'),
        ]);
        $this->student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $studentUser->id,
            'class_id' => $class->id,
            'admission_number' => 'GRN-001',
            'status' => 'active',
        ]);
    }

    private function makeUser(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function score(Subject $subject, string $status, string $comment = 'Shows real promise.'): ScoreEntry
    {
        return ScoreEntry::create([
            'school_id' => $this->school->id,
            'term_id' => $this->term->id,
            'student_id' => $this->student->id,
            'subject_id' => $subject->id,
            'first_ca' => 18,
            'second_ca' => 17,
            'exam' => 54,
            'total_score' => 89,
            'grade' => 'A1',
            'teacher_comment' => $comment,
            'ai_comment_status' => $status,
        ]);
    }

    public function test_queue_lists_only_comments_awaiting_review(): void
    {
        $this->score($this->maths, 'pending_approval', 'Exceptional problem-solving.');
        $this->score($this->english, 'approved', 'Already signed off.');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/assessment/ai-comments/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.comment', 'Exceptional problem-solving.')
            ->assertJsonPath('data.0.student_name', 'Chidi Nnamdi')
            ->assertJsonPath('data.0.subject', 'Mathematics')
            ->assertJsonPath('data.0.class', 'JSS 3')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_queue_is_empty_when_nothing_is_waiting(): void
    {
        $this->score($this->maths, 'approved');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/assessment/ai-comments/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * Approving a remark is a judgement about a child's work, so it belongs to
     * whoever taught them — not to whoever opens the queue first.
     */
    public function test_a_teacher_sees_only_the_subjects_they_teach(): void
    {
        $this->score($this->maths, 'pending_approval', 'Maths remark.');
        $this->score($this->english, 'pending_approval', 'English remark.');

        DB::table('teacher_subjects')->insert([
            'school_id' => $this->school->id,
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->maths->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/v1/assessment/ai-comments/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.comment', 'Maths remark.');
    }

    public function test_approving_from_the_queue_removes_it_from_the_queue(): void
    {
        $entry = $this->score($this->maths, 'pending_approval');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/assessment/score/{$entry->id}/review-comment", ['action' => 'approve'])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/assessment/ai-comments/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_queue_can_be_narrowed_to_one_term(): void
    {
        $this->score($this->maths, 'pending_approval');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/assessment/ai-comments/pending?term_id={$this->term->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/assessment/ai-comments/pending?term_id=999999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_queue_does_not_cross_the_tenant_boundary(): void
    {
        $this->score($this->maths, 'pending_approval');

        $rival = School::create([
            'name' => 'Rival Academy', 'slug' => 'rival', 'subdomain' => 'rival', 'status' => 'active',
        ]);
        $rivalAdmin = User::create([
            'name' => 'Rival Admin', 'email' => 'admin@rival.test', 'password' => bcrypt('password'),
        ]);
        $rivalAdmin->userProfile()->create(['school_id' => $rival->id, 'role' => 'school_admin']);

        $this->actingAs($rivalAdmin, 'sanctum')
            ->getJson('/api/v1/assessment/ai-comments/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_student_cannot_read_the_review_queue(): void
    {
        $this->score($this->maths, 'pending_approval');

        $studentUser = User::find($this->student->user_id);
        $studentUser->userProfile()->create(['school_id' => $this->school->id, 'role' => 'student']);

        $this->actingAs($studentUser, 'sanctum')
            ->getJson('/api/v1/assessment/ai-comments/pending')
            ->assertForbidden();
    }
}
