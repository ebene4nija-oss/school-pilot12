<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Homework;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentTopicMastery;
use App\Models\Subject;
use App\Models\TutorConversation;
use App\Models\TutorMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Doc §7.11. The tutor previously returned one hardcoded sentence to every
 * student for every message, never called an LLM, and stored nothing.
 */
class AiTutorTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $studentUser;
    private Student $student;
    private Subject $subject;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Unity College',
            'slug' => 'unity',
            'subdomain' => 'unity',
            'domain' => 'unity.schoolpilot.test',
        ]);

        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $this->subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);

        $this->studentUser = User::create([
            'name' => 'Ngozi Student',
            'email' => 'ngozi@unity.test',
            'password' => bcrypt('password123'),
        ]);
        $this->studentUser->userProfile()->create(['school_id' => $this->school->id, 'role' => 'student']);

        $this->student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $this->studentUser->id,
            'class_id' => $this->class->id,
            'admission_number' => 'ADM100',
        ]);

        // A real key so the service takes the live path; Http::fake intercepts.
        config()->set('services.anthropic.api_key', 'sk-ant-test-key');
        config()->set('services.anthropic.stub', false);
    }

    private function fakeClaude(string $text = 'Let us start with what you already know about fractions.'): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $text]],
            ], 200),
        ]);
    }

    private function chat(array $payload)
    {
        return $this->actingAs($this->studentUser, 'sanctum')
            ->postJson('http://unity.schoolpilot.test/api/v1/ai/tutor-chat', $payload);
    }

    public function test_tutor_returns_the_models_reply_not_a_canned_string()
    {
        $this->fakeClaude('Think about what happens when you double both sides.');

        $response = $this->chat(['message' => 'How do I solve 2x = 10?'])
            ->assertStatus(200);

        $response->assertJsonPath('response', 'Think about what happens when you double both sides.');
        $this->assertNotSame("Great question! Let's break down key concepts together.", $response->json('response'));
    }

    public function test_conversation_and_both_turns_are_persisted()
    {
        $this->fakeClaude();

        $conversationId = $this->chat(['message' => 'Explain fractions'])->json('conversation_id');

        $this->assertDatabaseCount('tutor_conversations', 1);

        $messages = TutorMessage::where('conversation_id', $conversationId)->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertSame('student', $messages[0]->role);
        $this->assertSame('Explain fractions', $messages[0]->body);
        $this->assertSame('tutor', $messages[1]->role);
    }

    /** A follow-up message continues the same thread rather than starting over. */
    public function test_follow_up_message_continues_the_same_conversation()
    {
        $this->fakeClaude();

        $conversationId = $this->chat(['message' => 'Explain fractions'])->json('conversation_id');
        $this->chat(['message' => 'I still do not understand', 'conversation_id' => $conversationId])
            ->assertStatus(200)
            ->assertJsonPath('conversation_id', $conversationId);

        $this->assertSame(4, TutorMessage::where('conversation_id', $conversationId)->count());
        $this->assertDatabaseCount('tutor_conversations', 1);
    }

    /**
     * The distinguishing requirement in §7.11: grounded in what this child's
     * own teacher assigned, not a generic chatbot.
     */
    public function test_prompt_carries_the_students_own_homework_and_weak_topics()
    {
        $teacher = User::create([
            'name' => 'Mr Bello',
            'email' => 'bello@unity.test',
            'password' => bcrypt('password123'),
        ]);
        $teacher->userProfile()->create(['school_id' => $this->school->id, 'role' => 'teacher']);

        Homework::create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $teacher->id,
            'title' => 'Simultaneous equations worksheet',
            'description' => 'Questions 1-10',
            'due_date' => now()->addDays(3),
        ]);

        StudentTopicMastery::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'subject_id' => $this->subject->id,
            'topic' => 'Quadratic equations',
            'questions_attempted' => 10,
            'questions_correct' => 3,
            'mastery_percentage' => 30,
        ]);

        $this->fakeClaude();
        $this->chat(['message' => 'Help me revise'])->assertStatus(200);

        Http::assertSent(function ($request) {
            $system = $request->data()['system'] ?? '';

            return str_contains($system, 'Simultaneous equations worksheet')
                && str_contains($system, 'Quadratic equations')
                && str_contains($system, 'JSS 2');
        });
    }

    /** "Just give me the answer" is redirected, and the redirect is recorded. */
    public function test_answer_harvesting_is_flagged_and_redirected()
    {
        $this->fakeClaude('Let us take the first step together instead.');

        $this->chat(['message' => 'Just give me the answer to question 3'])
            ->assertStatus(200)
            ->assertJsonPath('was_redirected', true);

        Http::assertSent(function ($request) {
            return str_contains($request->data()['system'] ?? '', 'hand over an answer outright');
        });

        $this->assertTrue(TutorMessage::where('role', 'student')->first()->was_redirected);
    }

    /** A student cannot open someone else's transcript by guessing an id. */
    public function test_student_cannot_read_another_students_conversation()
    {
        $otherUser = User::create([
            'name' => 'Other Child',
            'email' => 'other@unity.test',
            'password' => bcrypt('password123'),
        ]);
        $otherUser->userProfile()->create(['school_id' => $this->school->id, 'role' => 'student']);

        $otherStudent = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $otherUser->id,
            'class_id' => $this->class->id,
            'admission_number' => 'ADM101',
        ]);

        $theirs = TutorConversation::create([
            'school_id' => $this->school->id,
            'student_id' => $otherStudent->id,
            'title' => 'Private revision',
            'last_message_at' => now(),
        ]);

        $this->actingAs($this->studentUser, 'sanctum')
            ->getJson("http://unity.schoolpilot.test/api/v1/ai/tutor/conversations/{$theirs->id}")
            ->assertStatus(404);

        // Nor by posting into it.
        $this->chat(['message' => 'hello', 'conversation_id' => $theirs->id])->assertStatus(404);
    }

    /** The route used to carry no role middleware at all. */
    public function test_a_teacher_cannot_use_the_student_tutor()
    {
        $teacher = User::create([
            'name' => 'Mrs Okoro',
            'email' => 'okoro@unity.test',
            'password' => bcrypt('password123'),
        ]);
        $teacher->userProfile()->create(['school_id' => $this->school->id, 'role' => 'teacher']);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('http://unity.schoolpilot.test/api/v1/ai/tutor-chat', ['message' => 'hi'])
            ->assertStatus(403);
    }

    /**
     * With no key and stubbing off, the tutor must fail loudly rather than
     * return something that reads like tutoring.
     */
    public function test_misconfigured_tutor_fails_loudly_instead_of_fabricating()
    {
        config()->set('services.anthropic.api_key', null);
        config()->set('services.anthropic.stub', false);

        $this->chat(['message' => 'Explain photosynthesis'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'The AI tutor is unavailable: no Anthropic API key is configured.');

        $this->assertDatabaseCount('tutor_messages', 1); // the student turn only
    }

    /** A school that switched the tutor off gets a clear refusal. */
    public function test_school_can_switch_the_tutor_off()
    {
        $this->school->update(['ai_feature_flags' => ['tutor' => false]]);

        $this->fakeClaude();

        $this->chat(['message' => 'Explain photosynthesis'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'The AI tutor is currently switched off by your school.');

        Http::assertNothingSent();
    }

    public function test_mastery_endpoint_buckets_topics()
    {
        foreach ([['Algebra', 90], ['Geometry', 60], ['Trigonometry', 20]] as [$topic, $pct]) {
            StudentTopicMastery::create([
                'school_id' => $this->school->id,
                'student_id' => $this->student->id,
                'subject_id' => $this->subject->id,
                'topic' => $topic,
                'questions_attempted' => 10,
                'questions_correct' => (int) ($pct / 10),
                'mastery_percentage' => $pct,
            ]);
        }

        $this->actingAs($this->studentUser, 'sanctum')
            ->getJson('http://unity.schoolpilot.test/api/v1/ai/tutor/mastery')
            ->assertStatus(200)
            ->assertJsonPath('mastered.0.topic', 'Algebra')
            ->assertJsonPath('developing.0.topic', 'Geometry')
            ->assertJsonPath('needs_work.0.topic', 'Trigonometry');
    }
}
