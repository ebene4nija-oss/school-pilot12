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

        /*
         * Points come from the server's own table, not the request.
         *
         * This test previously posted `points_earned => 120` and asserted 120
         * came back — which is precisely the hole: any student could award
         * themselves any number of points, and the leaderboard was decorative.
         * The inflated value is still sent here, and must be ignored.
         */
        $response = $this->actingAs($user)
            ->withHeaders(['X-School-Tenant' => 'gamification-test'])
            ->postJson('/api/v1/gamification/activity', [
                'student_id' => $student->id,
                'activity_type' => 'cbt_completion',
                'points_earned' => 120,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('points_awarded', 25)
            ->assertJsonPath('data.points', 25)
            ->assertJsonPath('data.current_streak', 1);

        $badges = $response->json('data.badges');
        $this->assertContains('CBT Champion', $badges);
        $this->assertNotContains(
            'Century Scholar',
            $badges,
            'A single CBT is worth 25 points, so the 100-point badge must not unlock.'
        );

        // Fetch leaderboard
        $leaderboardRes = $this->actingAs($user)
            ->withHeaders(['X-School-Tenant' => 'gamification-test'])
            ->getJson('/api/v1/gamification/leaderboard');

        $leaderboardRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.points', 25);
    }

    /** A student must not be able to record activity for another child. */
    public function test_student_cannot_award_points_to_another_student()
    {
        $school = School::create([
            'name' => 'Scoped Academy',
            'slug' => 'scoped',
            'subdomain' => 'scoped',
            'domain' => 'scoped.schoolpilot.test',
        ]);

        $make = function (string $email) use ($school) {
            $user = User::create([
                'name' => 'Pupil',
                'email' => $email,
                'password' => bcrypt('password'),
                'role' => 'student',
            ]);
            UserProfile::create([
                'user_id' => $user->id,
                'school_id' => $school->id,
                'role' => 'student',
            ]);

            return [$user, Student::create([
                'school_id' => $school->id,
                'user_id' => $user->id,
                'admission_number' => 'STU-' . uniqid(),
            ])];
        };

        [$attacker] = $make('attacker@scoped.test');
        [, $victim] = $make('victim@scoped.test');

        $this->actingAs($attacker)
            ->postJson('http://scoped.schoolpilot.test/api/v1/gamification/activity', [
                'student_id' => $victim->id,
                'activity_type' => 'cbt_completion',
            ])
            ->assertStatus(200);

        // The points landed on the attacker's own record, never the victim's.
        $this->assertDatabaseMissing('student_gamifications', ['student_id' => $victim->id]);
    }

    /** An unknown activity type cannot be invented to mint points. */
    public function test_unknown_activity_type_is_rejected()
    {
        $school = School::create([
            'name' => 'Reject Academy',
            'slug' => 'reject',
            'subdomain' => 'reject',
            'domain' => 'reject.schoolpilot.test',
        ]);

        $user = User::create([
            'name' => 'Pupil',
            'email' => 'pupil@reject.test',
            'password' => bcrypt('password'),
            'role' => 'student',
        ]);
        UserProfile::create(['user_id' => $user->id, 'school_id' => $school->id, 'role' => 'student']);
        Student::create(['school_id' => $school->id, 'user_id' => $user->id, 'admission_number' => 'STU-R1']);

        $this->actingAs($user)
            ->postJson('http://reject.schoolpilot.test/api/v1/gamification/activity', [
                'activity_type' => 'i_am_the_best',
            ])
            ->assertStatus(422);
    }
}
