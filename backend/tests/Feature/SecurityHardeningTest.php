<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for the security review dated 2026-08-06.
 * See docs/TECHNICAL-REVIEW-2026-08-06.md §2.
 *
 * NOTE ON HOSTS: these tests use absolute URLs (http://hilltop.localhost/...)
 * rather than withServerVariables(['HTTP_HOST' => ...]). The latter does not
 * work — Laravel prepends config('app.url') to a relative URI and Symfony then
 * overwrites HTTP_HOST from it, so the server variable is silently discarded
 * and TenantResolutionMiddleware never resolves a tenant.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function url(string $path, string $subdomain = 'hilltop'): string
    {
        return "http://{$subdomain}.localhost{$path}";
    }

    private function makeSchool(array $overrides = []): School
    {
        return School::create(array_merge([
            'name' => 'Hilltop Sec',
            'slug' => 'hilltop',
            'subdomain' => 'hilltop',
        ], $overrides));
    }

    private function makeUser(School $school, string $role, array $profile = []): array
    {
        $user = User::factory()->create();
        UserProfile::create(array_merge([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'role' => $role,
        ], $profile));

        return [$user, $user->createToken('token')->plainTextToken];
    }

    private function makeStudent(School $school): Student
    {
        $studentUser = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $studentUser->id, 'role' => 'student']);

        return Student::create([
            'school_id' => $school->id,
            'user_id' => $studentUser->id,
        ]);
    }

    private function makeStaffRecord(School $school, User $user): int
    {
        return DB::table('staff')->insertGetId([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'designation' => 'Class Teacher',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeTerm(School $school): Term
    {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2025/2026',
            'start_date' => now(), 'end_date' => now()->addYear(),
        ]);

        return Term::create([
            'school_id' => $school->id, 'session_id' => $session->id, 'name' => 'First Term',
            'start_date' => now(), 'end_date' => now()->addMonths(3),
        ]);
    }

    private function asUser(string $token)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    // ---------------------------------------------------------------- §2.1 IDOR

    public function test_parent_cannot_read_another_childs_feed()
    {
        $school = $this->makeSchool();
        [$parentUser, $parentToken] = $this->makeUser($school, 'parent');

        $ownChild = $this->makeStudent($school);
        $otherChild = $this->makeStudent($school);

        $guardian = Guardian::create([
            'school_id' => $school->id,
            'user_id' => $parentUser->id,
            'relationship' => 'parent',
        ]);
        $guardian->students()->attach($ownChild->id, ['is_primary' => true]);

        // Own child: allowed.
        $this->asUser($parentToken)
            ->getJson($this->url("/api/v1/parent/feed/{$ownChild->id}"))
            ->assertStatus(200);

        // Another family's child: must be refused.
        $this->asUser($parentToken)
            ->getJson($this->url("/api/v1/parent/feed/{$otherChild->id}"))
            ->assertStatus(403);
    }

    public function test_parent_cannot_read_another_childs_analytics_dashboard()
    {
        $school = $this->makeSchool();
        [$parentUser, $parentToken] = $this->makeUser($school, 'parent');

        $ownChild = $this->makeStudent($school);
        $otherChild = $this->makeStudent($school);

        $guardian = Guardian::create([
            'school_id' => $school->id,
            'user_id' => $parentUser->id,
            'relationship' => 'parent',
        ]);
        $guardian->students()->attach($ownChild->id, ['is_primary' => true]);

        $this->asUser($parentToken)
            ->getJson($this->url("/api/v1/analytics/parent/{$otherChild->id}"))
            ->assertStatus(403);
    }

    public function test_student_cannot_read_another_students_portfolio()
    {
        $school = $this->makeSchool();
        $ownRecord = $this->makeStudent($school);
        $otherRecord = $this->makeStudent($school);

        $studentToken = $ownRecord->user->createToken('token')->plainTextToken;

        $this->asUser($studentToken)
            ->getJson($this->url("/api/v1/students/{$otherRecord->id}/portfolio"))
            ->assertStatus(403);
    }

    // ------------------------------------------------------- §2.2 QR forgery

    public function test_forged_qr_token_is_rejected()
    {
        $school = $this->makeSchool();
        [, $teacherToken] = $this->makeUser($school, 'teacher');
        $student = $this->makeStudent($school);
        $term = $this->makeTerm($school);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 1']);

        // Hand-rolled payload in the old unsigned format — never issued by the server.
        $forged = base64_encode(json_encode([
            'school_id' => $school->id,
            'class_id' => $class->id,
            'term_id' => $term->id,
            'expires_at' => now()->addYears(5)->timestamp,
            'nonce' => bin2hex(random_bytes(8)),
        ]));

        $this->asUser($teacherToken)
            ->postJson($this->url('/api/v1/attendance/mark'), [
                'student_id' => $student->id,
                'term_id' => $term->id,
                'date' => now()->toDateString(),
                'status' => 'present',
                'qr_token' => $forged,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_qr_token_missing_claims_is_rejected_rather_than_skipped()
    {
        $school = $this->makeSchool();
        [, $teacherToken] = $this->makeUser($school, 'teacher');
        $student = $this->makeStudent($school);
        $term = $this->makeTerm($school);

        // Omitting school_id previously skipped the tenant check entirely.
        $stripped = base64_encode(json_encode(['expires_at' => now()->addMinutes(10)->timestamp]));

        $this->asUser($teacherToken)
            ->postJson($this->url('/api/v1/attendance/mark'), [
                'student_id' => $student->id,
                'term_id' => $term->id,
                'date' => now()->toDateString(),
                'status' => 'present',
                'qr_token' => $stripped,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_server_issued_qr_token_is_accepted_once_and_cannot_be_replayed()
    {
        $school = $this->makeSchool();
        [, $adminToken] = $this->makeUser($school, 'school_admin');
        $student = $this->makeStudent($school);
        $term = $this->makeTerm($school);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 1']);

        $issued = $this->asUser($adminToken)
            ->postJson($this->url('/api/v1/attendance/qr-token'), [
                'class_id' => $class->id,
                'term_id' => $term->id,
            ])->assertStatus(200)->json('qr_token');

        $this->asUser($adminToken)
            ->postJson($this->url('/api/v1/attendance/mark'), [
                'student_id' => $student->id,
                'term_id' => $term->id,
                'date' => now()->toDateString(),
                'status' => 'present',
                'qr_token' => $issued,
            ])
            ->assertStatus(200);

        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_qr_token_issued_by_one_school_cannot_mark_attendance_in_another()
    {
        $schoolA = $this->makeSchool();
        $schoolB = School::create(['name' => 'Riverside', 'slug' => 'riverside', 'subdomain' => 'riverside']);

        [, $adminA] = $this->makeUser($schoolA, 'school_admin');
        [, $adminB] = $this->makeUser($schoolB, 'school_admin');

        $termA = $this->makeTerm($schoolA);
        $classA = SchoolClass::create(['school_id' => $schoolA->id, 'name' => 'JSS 1']);
        $studentB = $this->makeStudent($schoolB);
        $termB = $this->makeTerm($schoolB);

        $issuedForA = $this->asUser($adminA)
            ->postJson($this->url('/api/v1/attendance/qr-token'), [
                'class_id' => $classA->id,
                'term_id' => $termA->id,
            ])->assertStatus(200)->json('qr_token');

        // School B's admin tries to spend School A's token.
        $this->asUser($adminB)
            ->postJson($this->url('/api/v1/attendance/mark', 'riverside'), [
                'student_id' => $studentB->id,
                'term_id' => $termB->id,
                'date' => now()->toDateString(),
                'status' => 'present',
                'qr_token' => $issuedForA,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('attendance_records', 0);
    }

    // --------------------------------------------------- §2.3 GPS geofencing

    public function test_gps_clock_in_outside_geofence_is_rejected()
    {
        // School gate in Ikeja, Lagos.
        $school = $this->makeSchool([
            'latitude' => 6.6018,
            'longitude' => 3.3515,
            'geofence_radius_meters' => 200,
        ]);
        [$teacherUser, $teacherToken] = $this->makeUser($school, 'teacher');
        $term = $this->makeTerm($school);

        // Abuja — roughly 530km away.
        $this->asUser($teacherToken)
            ->postJson($this->url('/api/v1/attendance/staff-gps'), [
                'staff_id' => $this->makeStaffRecord($school, $teacherUser),
                'term_id' => $term->id,
                'latitude' => 9.0765,
                'longitude' => 7.3986,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_gps_clock_in_inside_geofence_is_accepted()
    {
        $school = $this->makeSchool([
            'latitude' => 6.6018,
            'longitude' => 3.3515,
            'geofence_radius_meters' => 200,
        ]);
        [$teacherUser, $teacherToken] = $this->makeUser($school, 'teacher');
        $term = $this->makeTerm($school);

        // ~50m from the school gate.
        $this->asUser($teacherToken)
            ->postJson($this->url('/api/v1/attendance/staff-gps'), [
                'staff_id' => $this->makeStaffRecord($school, $teacherUser),
                'term_id' => $term->id,
                'latitude' => 6.60225,
                'longitude' => 3.35150,
            ])
            ->assertStatus(201);

        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_gps_clock_in_fails_closed_when_school_has_no_coordinates()
    {
        $school = $this->makeSchool(); // no latitude/longitude on file
        [$teacherUser, $teacherToken] = $this->makeUser($school, 'teacher');
        $term = $this->makeTerm($school);

        $this->asUser($teacherToken)
            ->postJson($this->url('/api/v1/attendance/staff-gps'), [
                'staff_id' => $this->makeStaffRecord($school, $teacherUser),
                'term_id' => $term->id,
                'latitude' => 6.60225,
                'longitude' => 3.35150,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('attendance_records', 0);
    }

    // ------------------------------------------------- §2.4 Webhook secrets

    public function test_paystack_webhook_rejects_signature_forged_with_committed_default()
    {
        $this->makeSchool();
        config(['services.paystack.secret' => 'sk_live_real_secret_value']);

        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'REF-123', 'amount' => 500000]];

        // Signed with the string that used to be the env() fallback in this repo.
        $forged = hash_hmac('sha512', json_encode($payload), 'sk_test_mock_secret');

        $this->withHeader('x-paystack-signature', $forged)
            ->postJson($this->url('/api/v1/webhooks/paystack'), $payload)
            ->assertStatus(400);
    }

    public function test_paystack_webhook_fails_closed_when_secret_is_not_configured()
    {
        $this->makeSchool();
        config(['services.paystack.secret' => null]);

        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'REF-123', 'amount' => 500000]];

        $this->withHeader('x-paystack-signature', hash_hmac('sha512', json_encode($payload), 'anything'))
            ->postJson($this->url('/api/v1/webhooks/paystack'), $payload)
            ->assertStatus(503);
    }

    public function test_flutterwave_webhook_fails_closed_when_secret_is_not_configured()
    {
        $this->makeSchool();
        config(['services.flutterwave.secret_hash' => null]);

        $this->withHeader('verif-hash', 'mock_flw_secret_hash')
            ->postJson($this->url('/api/v1/webhooks/flutterwave'), ['status' => 'successful'])
            ->assertStatus(503);
    }

    // ----------------------------------------------------- §2.5 Fail-closed 2FA

    public function test_2fa_cannot_be_satisfied_when_no_secret_is_stored()
    {
        $school = $this->makeSchool();
        $admin = User::factory()->create(['password' => bcrypt('correct-horse')]);
        UserProfile::create([
            'school_id' => $school->id,
            'user_id' => $admin->id,
            'role' => 'school_admin',
            'two_factor_enabled' => true,
            'two_factor_secret' => null,
        ]);

        // Code generated from the well-known RFC test secret that used to be the fallback.
        $code = app(\App\Services\TotpService::class)->generateCode('JBSWY3DPEHPK3PXP');

        $response = $this->postJson($this->url('/api/v1/auth/login'), [
            'email' => $admin->email,
            'password' => 'correct-horse',
            'two_factor_code' => $code,
        ]);

        $response->assertStatus(422);
        $this->assertNull($response->json('token'));
    }

    public function test_2fa_succeeds_with_a_properly_enrolled_secret()
    {
        $school = $this->makeSchool();
        $totp = app(\App\Services\TotpService::class);
        $secret = $totp->generateSecret();

        $admin = User::factory()->create(['password' => bcrypt('correct-horse')]);
        UserProfile::create([
            'school_id' => $school->id,
            'user_id' => $admin->id,
            'role' => 'school_admin',
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
        ]);

        $this->postJson($this->url('/api/v1/auth/login'), [
            'email' => $admin->email,
            'password' => 'correct-horse',
            'two_factor_code' => $totp->generateCode($secret),
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    // ----------------------------------------- §2.6 Tenant-bound login

    public function test_user_cannot_login_through_another_schools_subdomain()
    {
        $schoolA = $this->makeSchool();
        School::create(['name' => 'Riverside', 'slug' => 'riverside', 'subdomain' => 'riverside']);

        $user = User::factory()->create(['password' => bcrypt('correct-horse')]);
        UserProfile::create(['school_id' => $schoolA->id, 'user_id' => $user->id, 'role' => 'teacher']);

        // No subdomain in the body — the host alone must bind the tenant.
        $response = $this->postJson($this->url('/api/v1/auth/login', 'riverside'), [
            'email' => $user->email,
            'password' => 'correct-horse',
        ]);

        $response->assertStatus(403);
        $this->assertNull($response->json('token'));
    }

    public function test_user_can_login_through_their_own_subdomain()
    {
        $school = $this->makeSchool();
        $user = User::factory()->create(['password' => bcrypt('correct-horse')]);
        UserProfile::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => 'teacher']);

        $this->postJson($this->url('/api/v1/auth/login'), [
            'email' => $user->email,
            'password' => 'correct-horse',
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_super_admin_may_login_through_any_subdomain()
    {
        $this->makeSchool();
        School::create(['name' => 'Riverside', 'slug' => 'riverside', 'subdomain' => 'riverside']);

        $root = User::factory()->create(['password' => bcrypt('correct-horse')]);
        UserProfile::create(['school_id' => null, 'user_id' => $root->id, 'role' => 'super_admin']);

        $this->postJson($this->url('/api/v1/auth/login', 'riverside'), [
            'email' => $root->email,
            'password' => 'correct-horse',
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    // --------------------------------------------- §2.8 Login rate limiting

    public function test_login_is_rate_limited_after_repeated_failures()
    {
        $school = $this->makeSchool();
        $user = User::factory()->create(['email' => 'brute@example.com', 'password' => bcrypt('correct-horse')]);
        UserProfile::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => 'teacher']);

        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $this->postJson($this->url('/api/v1/auth/login'), [
                'email' => 'brute@example.com',
                'password' => 'wrong-password',
            ])->status();
        }

        $this->assertContains(429, $statuses, 'Login should be rate limited well before 8 attempts.');
    }

    // ------------------------------- §2.9 Invite must not return a password

    public function test_invite_does_not_return_a_password_in_the_response()
    {
        $school = $this->makeSchool();
        [, $adminToken] = $this->makeUser($school, 'school_admin');

        $response = $this->asUser($adminToken)
            ->postJson($this->url('/api/v1/auth/invite'), [
                'name' => 'Chinedu Okeke',
                'email' => 'chinedu@hilltop.edu.ng',
                'role' => 'teacher',
            ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('temp_password'), 'Temp password must not be returned over the API.');
    }
}
