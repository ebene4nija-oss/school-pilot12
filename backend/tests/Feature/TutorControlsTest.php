<?php

namespace Tests\Feature;

use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TutorConversation;
use App\Models\TutorSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The school's control over the student AI tutor (TASK 15). */
class TutorControlsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Student $student;
    private Subject $maths;
    private Subject $english;
    private User $admin;
    private string $url = 'http://unity.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Unity College', 'slug' => 'unity', 'subdomain' => 'unity', 'domain' => 'unity.schoolpilot.test']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2', 'level_category' => 'junior_secondary']);
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);
        $this->english = Subject::create(['school_id' => $this->school->id, 'name' => 'English']);

        $u = User::create(['name' => 'Ngozi', 'email' => 'ngozi@unity.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => 'student']);
        $this->student = Student::create(['school_id' => $this->school->id, 'user_id' => $u->id, 'class_id' => $class->id, 'admission_number' => 'ADM1']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@unity.test', 'password' => bcrypt('password123')]);
        $this->admin->userProfile()->create(['school_id' => $this->school->id, 'role' => 'school_admin']);

        config()->set('services.anthropic.api_key', 'sk-ant-test-key');
        config()->set('services.anthropic.stub', false);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'What do you already know?']]], 200)]);
    }

    private function chat(array $payload)
    {
        return $this->actingAs($this->student->user, 'sanctum')->postJson("{$this->url}/ai/tutor-chat", $payload);
    }

    public function test_a_section_the_school_has_not_enabled_is_refused_before_any_ai_call(): void
    {
        TutorSetting::create(['school_id' => $this->school->id, 'sections' => ['primary']]);

        $this->chat(['message' => 'Help with fractions'])->assertForbidden();
        Http::assertNothingSent();
        $this->assertSame(0, TutorConversation::count(), 'A refused message leaves no conversation behind.');
    }

    public function test_only_allowed_subjects_get_help(): void
    {
        TutorSetting::create(['school_id' => $this->school->id, 'subject_ids' => [$this->maths->id]]);

        $this->chat(['message' => 'Help me'])->assertForbidden();
        $this->chat(['message' => 'Help with an essay', 'subject_id' => $this->english->id])->assertForbidden();
        $this->chat(['message' => 'Help with fractions', 'subject_id' => $this->maths->id])->assertOk();
    }

    public function test_the_tutor_is_paused_during_a_cbt_exam(): void
    {
        $exam = CbtExam::create(['school_id' => $this->school->id, 'subject_id' => $this->maths->id, 'title' => 'Mid-term', 'status' => 'published']);
        $attempt = CbtAttempt::create(['school_id' => $this->school->id, 'exam_id' => $exam->id, 'student_id' => $this->student->id, 'attempt_number' => 1, 'seed' => 1, 'status' => 'in_progress']);

        $this->chat(['message' => 'What is the answer to question 3?'])->assertForbidden()->assertJsonPath('error', 'The tutor is paused while you are sitting a CBT exam. Finish or submit it first.');

        $attempt->update(['status' => 'submitted']);
        $this->chat(['message' => 'Can you explain question 3 now?'])->assertOk();
    }

    public function test_the_daily_limit_holds(): void
    {
        TutorSetting::create(['school_id' => $this->school->id, 'daily_message_limit' => 2]);

        $this->chat(['message' => 'One'])->assertOk();
        $this->chat(['message' => 'Two'])->assertOk();
        $this->chat(['message' => 'Three'])->assertForbidden();
    }

    public function test_practice_mode_asks_for_questions_not_answers(): void
    {
        $this->chat(['message' => 'simultaneous equations', 'mode' => 'practice'])->assertOk();

        Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'five practice questions') && str_contains(json_encode($req->data()), 'simultaneous equations'));
    }

    public function test_admins_set_the_rules_and_see_counts_without_content(): void
    {
        $this->chat(['message' => 'Explain photosynthesis', 'subject_id' => $this->maths->id])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->url}/ai/tutor/settings", ['sections' => ['secondary'], 'subject_ids' => null, 'daily_message_limit' => 40, 'exam_lock' => true])->assertOk();
        $usage = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->url}/ai/tutor/usage")->assertOk()->json();

        $this->assertSame(1, $usage['messages']);
        $this->assertSame(1, $usage['active_students']);
        $this->assertSame(['secondary' => 1], $usage['by_section']);
        $this->assertStringNotContainsString('photosynthesis', json_encode($usage));

        $this->actingAs($this->student->user, 'sanctum')->getJson("{$this->url}/ai/tutor/usage")->assertForbidden();
    }
}
