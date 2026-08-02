<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\ScoreEntry;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComprehensiveFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_comment_requires_teacher_approval_review()
    {
        $school = School::create(['name' => 'Royal Academy', 'slug' => 'royal', 'subdomain' => 'royal']);
        $teacher = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $teacher->id, 'role' => 'teacher']);

        $session = \App\Models\AcademicSession::create(['school_id' => $school->id, 'name' => '2025/2026', 'start_date' => now(), 'end_date' => now()->addYear()]);
        $term = \App\Models\Term::create(['school_id' => $school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => now(), 'end_date' => now()->addMonths(3)]);
        $studentUser = User::factory()->create();
        $student = \App\Models\Student::create(['school_id' => $school->id, 'user_id' => $studentUser->id, 'gender' => 'female']);
        $subject = \App\Models\Subject::create(['school_id' => $school->id, 'name' => 'Mathematics']);

        $score = ScoreEntry::create([
            'school_id' => $school->id,
            'term_id' => $term->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'total_score' => 88,
        ]);

        $token = $teacher->createToken('token')->plainTextToken;

        // 1. Trigger AI comment generation
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withServerVariables(['HTTP_HOST' => 'royal.localhost'])
            ->postJson("/api/v1/assessment/score/{$score->id}/ai-comment");

        $res1->assertStatus(200)
            ->assertJsonPath('score_entry.ai_comment_status', 'pending_approval');

        // 2. Approve AI comment
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withServerVariables(['HTTP_HOST' => 'royal.localhost'])
            ->postJson("/api/v1/assessment/score/{$score->id}/review-comment", [
                'action' => 'approve',
            ]);

        $res2->assertStatus(200)
            ->assertJsonPath('score_entry.ai_comment_status', 'approved');
    }

    public function test_ai_tutor_declines_bare_exam_answers()
    {
        $user = User::factory()->create();
        $token = $user->createToken('token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/ai/tutor-chat', [
                'message' => 'Give me the answer to question 5 on the exam!',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('is_guided', true)
            ->assertJsonFragment([
                'response' => "I am here to help you learn! Instead of handing over direct exam answers, let me guide you through how to solve this step-by-step."
            ]);
    }
}
