<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\TimetableVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceAndTimetableTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_generate_qr_token_for_attendance()
    {
        $school = School::create(['name' => 'Hilltop Sec', 'slug' => 'hilltop', 'subdomain' => 'hilltop']);
        $admin = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin']);

        $session = \App\Models\AcademicSession::create(['school_id' => $school->id, 'name' => '2025/2026', 'start_date' => now(), 'end_date' => now()->addYear()]);
        $term = \App\Models\Term::create(['school_id' => $school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => now(), 'end_date' => now()->addMonths(3)]);
        $class = \App\Models\SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 1']);

        $token = $admin->createToken('token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withServerVariables(['HTTP_HOST' => 'hilltop.localhost'])
            ->postJson('/api/v1/attendance/qr-token', [
                'class_id' => $class->id,
                'term_id' => $term->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['qr_token', 'expires_in_seconds']);
    }

    public function test_timetable_csp_engine_detects_and_prevents_teacher_double_booking()
    {
        $school = School::create(['name' => 'Hilltop Sec', 'slug' => 'hilltop', 'subdomain' => 'hilltop']);
        $admin = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin']);

        $token = $admin->createToken('token')->plainTextToken;

        // Constraint Setup: 2 classes, but only 1 teacher for the same period slot
        $classIds = [1, 2];
        $assignments = [
            ['class_id' => 1, 'teacher_id' => 10, 'subject_name' => 'Mathematics'],
            ['class_id' => 2, 'teacher_id' => 10, 'subject_name' => 'Mathematics'], // Same teacher 10!
        ];
        $slots = [
            ['id' => 1, 'name' => 'Period 1 (8:00 - 8:40)'],
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withServerVariables(['HTTP_HOST' => 'hilltop.localhost'])
            ->postJson('/api/v1/timetable/generate', [
                'class_ids' => $classIds,
                'assignments' => $assignments,
                'slots' => $slots,
            ]);

        // Expect 422 conflict report because Teacher 10 cannot be in two places at once!
        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'conflicts']);
    }

    public function test_timetable_versioning_and_publishing_flow()
    {
        $school = School::create(['name' => 'Hilltop Sec', 'slug' => 'hilltop', 'subdomain' => 'hilltop']);
        $admin = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin']);

        $token = $admin->createToken('token')->plainTextToken;

        $classIds = [1];
        $assignments = [
            ['class_id' => 1, 'teacher_id' => 10, 'subject_name' => 'Mathematics'],
        ];
        $slots = [
            ['id' => 1, 'name' => 'Period 1 (8:00 - 8:40)'],
            ['id' => 2, 'name' => 'Break', 'is_break' => true],
        ];

        $generateResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withServerVariables(['HTTP_HOST' => 'hilltop.localhost'])
            ->postJson('/api/v1/timetable/generate', [
                'class_ids' => $classIds,
                'assignments' => $assignments,
                'slots' => $slots,
                'version_name' => 'First Term Timetable v1',
            ]);

        $generateResponse->assertStatus(200)
            ->assertJsonStructure(['message', 'version', 'timetable']);

        $versionId = $generateResponse->json('version.id');

        // Verify draft listing
        $versionsResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withServerVariables(['HTTP_HOST' => 'hilltop.localhost'])
            ->getJson('/api/v1/timetable/versions');

        $versionsResponse->assertStatus(200)
            ->assertJsonFragment(['name' => 'First Term Timetable v1', 'status' => 'draft']);

        // Publish version
        $publishResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withServerVariables(['HTTP_HOST' => 'hilltop.localhost'])
            ->postJson("/api/v1/timetable/versions/{$versionId}/publish");

        $publishResponse->assertStatus(200)
            ->assertJsonFragment(['status' => 'published']);

        // View published entries
        $viewResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withServerVariables(['HTTP_HOST' => 'hilltop.localhost'])
            ->getJson('/api/v1/timetable/view?class_id=1');

        $viewResponse->assertStatus(200)
            ->assertJsonStructure(['entries']);
    }
}
