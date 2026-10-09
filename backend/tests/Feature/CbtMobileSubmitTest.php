<?php

namespace Tests\Feature;

use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\QuestionBankItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phone's own submit: answers left on the device go up through
 * offline-sync with `submit: true`, shaped exactly as the app sends them.
 */
class CbtMobileSubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_phone_submits_its_remaining_answers_and_the_paper_closes()
    {
        $school = School::create(['name' => 'Graceland', 'slug' => 'graceland', 'subdomain' => 'graceland']);
        $mk = function (string $name, string $role) use ($school) {
            $u = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@g.test', 'password' => bcrypt('password')]);
            UserProfile::create(['school_id' => $school->id, 'user_id' => $u->id, 'role' => $role]);

            return $u;
        };
        $teacher = $mk('Teacher One', 'teacher');
        $pupil = $mk('Ada Obi', 'student');
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 2']);
        Student::create(['school_id' => $school->id, 'user_id' => $pupil->id, 'class_id' => $class->id, 'admission_number' => 'G1']);
        $subject = Subject::create(['school_id' => $school->id, 'name' => 'Mathematics']);

        $exam = CbtExam::create(['school_id' => $school->id, 'subject_id' => $subject->id, 'class_id' => $class->id, 'title' => 'CA 1', 'created_by' => $teacher->id, 'duration_minutes' => 30, 'status' => 'published', 'show_results_immediately' => true, 'max_attempts' => 1]);
        $questions = collect([['2 + 2', 'B'], ['3 + 3', 'A']])->map(fn ($q, $i) => tap(QuestionBankItem::create([
            'school_id' => $school->id, 'subject_id' => $subject->id, 'class_id' => $class->id, 'question' => $q[0],
            'question_type' => 'multiple_choice', 'options' => ['A' => '6', 'B' => '4'], 'correct_answer' => $q[1],
            'status' => 'approved', 'marks' => 1, 'created_by' => $teacher->id,
        ]), fn ($item) => CbtExamQuestion::create(['exam_id' => $exam->id, 'question_id' => $item->id, 'order_index' => $i + 1])));

        $as = fn () => $this->actingAs($pupil, 'sanctum')->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);

        $attemptId = $as()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->assertCreated()->json('attempt.id');
        $as()->getJson("/api/v1/cbt/attempts/{$attemptId}")->assertOk();

        // What the app's answer buffer sends (Dart DateTime.toIso8601String()).
        $stamp = now()->format('Y-m-d\TH:i:s.u');
        $as()->postJson("/api/v1/cbt/attempts/{$attemptId}/answers", ['answers' => [
            ['question_id' => $questions[0]->id, 'response' => ['option' => 'B'], 'client_sequence' => 1, 'client_timestamp' => $stamp, 'flagged_for_review' => false],
        ]])->assertOk()->assertJsonPath('saved', 1);

        $submit = $as()->postJson('/api/v1/cbt/offline-sync', [
            'attempt_id' => $attemptId,
            'answers' => [['question_id' => $questions[1]->id, 'response' => ['option' => 'A'], 'client_sequence' => 2, 'client_timestamp' => $stamp, 'flagged_for_review' => true]],
            'submit' => true,
        ]);
        $submit->assertOk()->assertJsonPath('attempt_status', 'graded');

        // Pressing submit again (a retry on a bad signal) is harmless.
        $as()->postJson("/api/v1/cbt/attempts/{$attemptId}/submit")->assertOk();

        $as()->getJson("/api/v1/cbt/attempts/{$attemptId}/result")->assertOk()
            ->assertJsonPath('result.raw_score', 2)
            ->assertJsonPath('result.max_score', 2);
    }
}
