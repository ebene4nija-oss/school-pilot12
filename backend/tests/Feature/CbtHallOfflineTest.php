<?php

namespace Tests\Feature;

use App\Models\CbtAttempt;
use App\Models\CbtAttemptAnswer;
use App\Models\CbtAttemptEvent;
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
 * An exam hall whose internet drops mid-paper.
 *
 * The candidate's PC keeps answering into local storage and the paper ends on
 * its own clock. When the line comes back — possibly after the deadline — the
 * answers go up through offline-sync with the submit. That only works if the
 * overdue sweep has not closed the attempt first, which is what the grace
 * window is for.
 */
class CbtHallOfflineTest extends TestCase
{
    use RefreshDatabase;

    private function sitting(): array
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

        return [$as, $attemptId, $questions];
    }

    public function test_answers_held_through_an_outage_past_the_deadline_still_count()
    {
        [$as, $attemptId, $questions] = $this->sitting();

        // Answered in the hall, then the internet went. The paper's 30
        // minutes run out and the line is back 40 minutes later.
        $answeredAt = now()->addMinutes(10)->toIso8601String();
        $this->travel(70)->minutes();

        $this->artisan('cbt:expire-attempts')->assertSuccessful();
        $this->assertSame('in_progress', CbtAttempt::find($attemptId)->status, 'the sweep must wait for a hall that is offline');

        $as()->postJson('/api/v1/cbt/offline-sync', [
            'attempt_id' => $attemptId,
            'submit' => true,
            'answers' => [
                ['question_id' => $questions[0]->id, 'response' => ['option' => 'B'], 'client_sequence' => 1, 'client_timestamp' => $answeredAt],
                ['question_id' => $questions[1]->id, 'response' => ['option' => 'A'], 'client_sequence' => 2, 'client_timestamp' => $answeredAt, 'flagged_for_review' => true],
            ],
        ])->assertOk()->assertJsonPath('synced_count', 2)->assertJsonPath('attempt_status', 'graded');

        $as()->getJson("/api/v1/cbt/attempts/{$attemptId}/result")->assertOk()->assertJsonPath('result.raw_score', 2);

        // The flag survives the trip, and the invigilator can see it was late.
        $this->assertTrue((bool) CbtAttemptAnswer::where('attempt_id', $attemptId)->where('question_id', $questions[1]->id)->value('flagged_for_review'));
        $late = CbtAttemptEvent::where('attempt_id', $attemptId)->where('event_type', 'late_sync')->first();
        $this->assertNotNull($late);
        $this->assertSame(40, $late->metadata['minutes_after_deadline']);
    }

    public function test_the_sweep_still_closes_an_abandoned_paper_once_the_grace_runs_out()
    {
        [, $attemptId] = $this->sitting();

        $this->travel(30 + config('schoolpilot.cbt_offline_grace_minutes') + 1)->minutes();
        $this->artisan('cbt:expire-attempts')->assertSuccessful();

        $this->assertNotSame('in_progress', CbtAttempt::find($attemptId)->status);
    }
}
