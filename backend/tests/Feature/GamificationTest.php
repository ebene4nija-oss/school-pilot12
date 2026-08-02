<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_record_activity_earn_points_streaks_and_badges()
    {
        $school = School::create([
            'name' => 'Gamification Test Academy',
            'slug' => 'gamification-test',
            'subdomain' => 'gamification-test',
            'domain' => 'gamification.schoolpilot.test',
        ]);

        $user = User::create([
            'name' => 'Student Learner',
            'email' => 'student@gamification.test',
            'password' => bcrypt('password'),
            'role' => 'student',
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'first_name' => 'Student',
            'last_name' => 'Learner',
        ]);

        $student = Student::create([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'admission_number' => 'STU-GAM-001',
            'gender' => 'male',
        ]);

        // Record CBT completion activity
        $response = $this->actingAs($user)
            ->withHeaders(['X-School-Tenant' => 'gamification-test'])
            ->postJson('/api/v1/gamification/activity', [
                'student_id' => $student->id,
                'activity_type' => 'cbt_completion',
                'points_earned' => 120,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.points', 120)
            ->assertJsonPath('data.current_streak', 1);

        $badges = $response->json('data.badges');
        $this->assertContains('Century Scholar', $badges);
        $this->assertContains('CBT Champion', $badges);

        // Fetch leaderboard
        $leaderboardRes = $this->actingAs($user)
            ->withHeaders(['X-School-Tenant' => 'gamification-test'])
            ->getJson('/api/v1/gamification/leaderboard');

        $leaderboardRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.points', 120);
    }
}
