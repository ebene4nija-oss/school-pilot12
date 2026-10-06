<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\ResultRelease;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI Studio (doc §7.10). These endpoints used to return canned text labelled
 * as AI output; they now call Claude, return drafts for the teacher, never
 * send a child's identity to the model, and respect who may see whom.
 */
class AiStudioTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private Student $ada;
    private Subject $maths;
    private Term $term;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31', 'is_current' => true]);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-08', 'end_date' => '2026-12-18']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);
        $this->teacher = $this->user('Mr Chidi', 'teacher');
        DB::table('teacher_subjects')->insert(['school_id' => $this->school->id, 'teacher_id' => $this->teacher->id, 'subject_id' => $this->maths->id, 'class_id' => $class->id]);
        $this->ada = Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('Ada Okonkwo', 'student')->id, 'class_id' => $class->id, 'admission_number' => 'ADA001', 'status' => 'active']);
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function claudeReplies(array $json): void
    {
        config(['services.anthropic.api_key' => 'sk-ant-test', 'services.anthropic.stub' => false]);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($json)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 200],
        ])]);
    }

    public function test_without_a_key_the_studio_returns_a_labelled_stub_not_fake_ai_output(): void
    {
        $plan = $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/ai/lesson-plan", ['subject' => 'Mathematics', 'topic' => 'Fractions', 'class_level' => 'JSS 1'])
            ->assertOk()->json('lesson_plan');

        $this->assertTrue($plan['stub'], 'A placeholder says it is one.');
    }

    public function test_question_drafts_come_from_claude_and_broken_ones_are_dropped(): void
    {
        $this->claudeReplies(['questions' => [
            ['question' => 'What is 1/2 + 1/4?', 'options' => ['A' => '3/4', 'B' => '2/6', 'C' => '1/8', 'D' => '1'], 'correct_answer' => 'A', 'topic' => 'Fractions', 'difficulty' => 'easy', 'explanation' => 'Common denominator.'],
            ['question' => 'Broken key', 'options' => ['A' => 'x', 'B' => 'y'], 'correct_answer' => 'E'],
        ]]);

        $exam = $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/ai/exam-generation", ['subject' => 'Mathematics', 'topic' => 'Fractions', 'count' => 2, 'class_level' => 'JSS 1'])
            ->assertOk()->json('exam');

        $this->assertSame(1, $exam['question_count']);
        $this->assertSame('What is 1/2 + 1/4?', $exam['questions'][0]['question']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['messages'][0]['content'], 'Fractions') && str_contains((string) $r['system'], 'Nigerian'));
    }

    public function test_a_performance_summary_never_sends_the_childs_name_and_uses_released_terms_only(): void
    {
        ScoreEntry::create(['school_id' => $this->school->id, 'term_id' => $this->term->id, 'student_id' => $this->ada->id, 'subject_id' => $this->maths->id, 'total_score' => 72, 'grade' => 'B']);

        $this->actingAs($this->teacher, 'sanctum')->getJson("{$this->host}/ai/performance-summary/{$this->ada->id}")
            ->assertStatus(403)->assertJsonPath('error', 'This student has no released results to summarise yet.');

        ResultRelease::create(['school_id' => $this->school->id, 'term_id' => $this->term->id, 'is_released' => true, 'released_at' => now()]);
        $this->claudeReplies(['summary' => 'The student is doing well in Mathematics.', 'strengths' => ['Mathematics'], 'concerns' => [], 'recommendations' => ['Keep practising']]);

        $res = $this->actingAs($this->teacher, 'sanctum')->getJson("{$this->host}/ai/performance-summary/{$this->ada->id}")->assertOk();
        $this->assertSame('The student is doing well in Mathematics.', $res->json('summary.summary'));

        Http::assertSent(function (HttpRequest $r) {
            $body = json_encode($r->data());

            return str_contains($body, 'Mathematics 72') && ! str_contains($body, 'Ada') && ! str_contains($body, 'Okonkwo') && ! str_contains($body, 'ADA001');
        });
    }

    public function test_a_teacher_cannot_summarise_a_student_they_do_not_teach(): void
    {
        $stranger = $this->user('Mrs Bello', 'teacher');

        $this->actingAs($stranger, 'sanctum')->getJson("{$this->host}/ai/performance-summary/{$this->ada->id}")->assertForbidden();
    }

    public function test_a_feature_the_school_switched_off_is_refused(): void
    {
        $this->school->forceFill(['ai_feature_flags' => ['translation' => false]])->save();

        $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/ai/translate", ['text' => 'Bring your books tomorrow.', 'target_language' => 'Yoruba'])
            ->assertStatus(403)->assertJsonPath('error', 'AI translation is switched off by your school administrator.');
    }
}
