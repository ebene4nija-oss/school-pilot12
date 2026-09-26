<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operator's AI remarks monitor: counts per school, no text, no names,
 * and never a change to a remark.
 */
class PlatformAiRemarksTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $teacher;

    private User $operator;

    /** @var ScoreEntry[] */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield']);
        $this->teacher = $this->person('Mr Bello', 'bello@gf.test', 'teacher', $this->school->id);
        $this->operator = $this->person('Ops', 'ops@schoolpilot.test', 'super_admin', null);

        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31']);
        $term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-14', 'end_date' => '2026-12-11']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);

        foreach (['Chidinma Eze', 'Tunde Bakare', 'Amaka Obi', 'Musa Bello', 'Zainab Ali'] as $i => $name) {
            $student = Student::create([
                'school_id' => $this->school->id,
                'user_id' => $this->person($name, "s{$i}@gf.test", 'student', $this->school->id)->id,
                'class_id' => $class->id,
                'admission_number' => "GF/{$i}",
            ]);
            $subject = Subject::create(['school_id' => $this->school->id, 'name' => "Subject {$i}", 'category' => 'core']);

            $this->entries[] = ScoreEntry::create([
                'school_id' => $this->school->id, 'term_id' => $term->id, 'student_id' => $student->id, 'subject_id' => $subject->id,
                'total_score' => 70, 'teacher_comment' => "SECRET-DRAFT-{$i} {$name} works hard.",
                'ai_comment_status' => 'pending_approval', 'ai_comment_generated_at' => now(),
            ]);
        }

        // Teachers review three of five: as written, rewritten, thrown away.
        $this->review(0, ['action' => 'approve']);
        $this->review(1, ['action' => 'edit', 'edited_comment' => 'A careful rewrite.']);
        $this->review(2, ['action' => 'reject']);
    }

    private function person(string $name, string $email, string $role, ?int $schoolId): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $schoolId, 'role' => $role]);

        return $user;
    }

    private function review(int $i, array $body): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/v1/assessment/score/{$this->entries[$i]->id}/review-comment", $body)
            ->assertOk();
    }

    public function test_reviews_are_recorded_by_outcome(): void
    {
        $this->assertSame('approved_as_is', $this->entries[0]->fresh()->ai_comment_review);
        $this->assertSame('edited', $this->entries[1]->fresh()->ai_comment_review);
        $this->assertSame('rejected', $this->entries[2]->fresh()->ai_comment_review);
        $this->assertNotNull($this->entries[1]->fresh()->ai_comment_reviewed_at);
    }

    public function test_the_operator_sees_counts_per_school(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/platform/ai-remarks')
            ->assertOk()
            ->assertJsonPath('totals.generated', 5)
            ->assertJsonPath('totals.approved_as_is', 1)
            ->assertJsonPath('totals.edited', 1)
            ->assertJsonPath('totals.rejected', 1)
            ->assertJsonPath('totals.pending', 2)
            ->assertJsonPath('schools.0.name', 'Greenfield')
            ->assertJsonPath('schools.0.rework_rate', 0.667);
    }

    public function test_the_monitor_carries_no_remark_text_and_no_student(): void
    {
        $body = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/ai-remarks')->assertOk()->getContent();

        $this->assertStringNotContainsString('SECRET-DRAFT', $body);
        $this->assertStringNotContainsString('rewrite', $body);
        foreach (['Chidinma', 'Tunde', 'Amaka', 'Musa', 'Zainab', 'GF/'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    /** Looking never approves: pending drafts stay pending. */
    public function test_reading_the_monitor_changes_nothing(): void
    {
        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/ai-remarks')->assertOk();

        $this->assertSame('pending_approval', $this->entries[3]->fresh()->ai_comment_status);
        $this->assertSame('pending_approval', $this->entries[4]->fresh()->ai_comment_status);
    }

    public function test_only_the_operator_can_read_it(): void
    {
        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/v1/platform/ai-remarks')->assertForbidden();
    }
}
