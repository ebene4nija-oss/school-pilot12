<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\School;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Self-service password recovery and sign-out — gaps G2 and G1 in
 * docs/mobile-app.md §B12.
 *
 * Absolute URLs, per the note in SecurityHardeningTest: a relative URI loses
 * the host and TenantResolutionMiddleware then resolves no tenant.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Several tests here deliberately hammer the same endpoint. The
        // limiter is real and correct, but it is not what they are asserting.
        RateLimiter::clear('pwd|parent@hilltop.edu.ng');
    }

    private function url(string $path, string $subdomain = 'hilltop'): string
    {
        return "http://{$subdomain}.localhost{$path}";
    }

    /**
     * Drop the guard's memoised user between two requests in one test.
     *
     * Sanctum authenticates through a RequestGuard, which caches the user it
     * resolved. The container survives for the whole test, so a second request
     * reuses that cached user and never re-checks the bearer token — which
     * makes "the revoked token now fails" pass whether or not it is true.
     */
    private function forgetAuthenticatedUser(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function makeSchool(array $overrides = []): School
    {
        return School::create(array_merge([
            'name' => 'Hilltop Sec',
            'slug' => 'hilltop',
            'subdomain' => 'hilltop',
        ], $overrides));
    }

    private function makeUser(School $school, string $role = 'parent', array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Ngozi Bello',
            'email' => 'parent@hilltop.edu.ng',
            'password' => Hash::make('old-password-1'),
        ], $attributes));

        UserProfile::create([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'role' => $role,
            'phone' => '+2348012345678',
        ]);

        return $user;
    }

    // ------------------------------------------------ Requesting a link (G2)

    public function test_forgot_password_emails_a_working_reset_link()
    {
        Mail::fake();
        $school = $this->makeSchool();
        $user = $this->makeUser($school);

        $this->postJson($this->url('/api/v1/auth/forgot-password'), [
            'email' => 'parent@hilltop.edu.ng',
        ])->assertStatus(200);

        Mail::assertSentCount(1);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'parent@hilltop.edu.ng']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'user.password_reset_requested',
        ]);
    }

    public function test_forgot_password_says_the_same_thing_for_an_unknown_address()
    {
        Mail::fake();
        $this->makeSchool();

        $known = $this->postJson($this->url('/api/v1/auth/forgot-password'), [
            'email' => 'parent@hilltop.edu.ng',
        ]);

        $unknown = $this->postJson($this->url('/api/v1/auth/forgot-password'), [
            'email' => 'nobody@hilltop.edu.ng',
        ]);

        // No account exists at all yet, so both are the "unknown" case — the
        // point is that the shape of the answer never varies.
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('message'), $unknown->json('message'));
        Mail::assertNothingSent();
    }

    public function test_forgot_password_does_not_leak_that_a_user_belongs_to_another_school()
    {
        Mail::fake();
        $this->makeSchool();
        $other = $this->makeSchool(['name' => 'Riverside', 'slug' => 'riverside', 'subdomain' => 'riverside']);
        $this->makeUser($other);

        // Asking hilltop's host about a Riverside parent.
        $response = $this->postJson($this->url('/api/v1/auth/forgot-password'), [
            'email' => 'parent@hilltop.edu.ng',
        ]);

        $response->assertStatus(200);
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'parent@hilltop.edu.ng']);
    }

    public function test_a_second_request_inside_the_throttle_window_does_not_send_again()
    {
        Mail::fake();
        $school = $this->makeSchool();
        $this->makeUser($school);

        $this->postJson($this->url('/api/v1/auth/forgot-password'), ['email' => 'parent@hilltop.edu.ng']);
        $this->postJson($this->url('/api/v1/auth/forgot-password'), ['email' => 'parent@hilltop.edu.ng'])
            ->assertStatus(200);

        // The school is billed per SMS and mailboxes are not a dumping ground.
        Mail::assertSentCount(1);
    }

    public function test_forgot_password_is_rate_limited()
    {
        Mail::fake();
        $this->makeSchool();

        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->postJson($this->url('/api/v1/auth/forgot-password'), [
                'email' => 'parent@hilltop.edu.ng',
            ])->status();
        }

        $this->assertContains(429, $statuses, 'Forgot-password should be rate limited.');
    }

    // ------------------------------------------------- Redeeming a link (G2)

    public function test_a_valid_token_sets_a_new_password_and_kills_every_session()
    {
        $school = $this->makeSchool();
        $user = $this->makeUser($school);
        $liveToken = $user->createToken('phone')->plainTextToken;

        $token = app(PasswordResetService::class)->issueToken($user);

        $this->postJson($this->url('/api/v1/auth/reset-password'), [
            'email' => 'parent@hilltop.edu.ng',
            'token' => $token,
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('brand-new-pass-9', $user->fresh()->password));

        // The session that existed before the reset must not survive it — the
        // reason for resetting may well be that somebody else was using it.
        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->forgetAuthenticatedUser();
        $this->withHeader('Authorization', 'Bearer ' . $liveToken)
            ->getJson($this->url('/api/v1/user'))
            ->assertStatus(401);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'parent@hilltop.edu.ng']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset_completed']);
    }

    public function test_the_new_password_actually_works_at_the_login_endpoint()
    {
        $school = $this->makeSchool();
        $user = $this->makeUser($school);
        $token = app(PasswordResetService::class)->issueToken($user);

        $this->postJson($this->url('/api/v1/auth/reset-password'), [
            'email' => 'parent@hilltop.edu.ng',
            'token' => $token,
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ])->assertStatus(200);

        $this->postJson($this->url('/api/v1/auth/login'), [
            'email' => 'parent@hilltop.edu.ng',
            'password' => 'brand-new-pass-9',
            'subdomain' => 'hilltop',
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_a_token_cannot_be_used_twice()
    {
        $school = $this->makeSchool();
        $user = $this->makeUser($school);
        $token = app(PasswordResetService::class)->issueToken($user);

        $payload = [
            'email' => 'parent@hilltop.edu.ng',
            'token' => $token,
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ];

        $this->postJson($this->url('/api/v1/auth/reset-password'), $payload)->assertStatus(200);
        $this->postJson($this->url('/api/v1/auth/reset-password'), $payload)->assertStatus(422);
    }

    public function test_an_expired_token_is_refused()
    {
        $school = $this->makeSchool();
        $user = $this->makeUser($school);
        $token = app(PasswordResetService::class)->issueToken($user);

        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        $this->postJson($this->url('/api/v1/auth/reset-password'), [
            'email' => 'parent@hilltop.edu.ng',
            'token' => $token,
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
    }

    public function test_a_forged_token_is_refused()
    {
        $school = $this->makeSchool();
        $user = $this->makeUser($school);
        app(PasswordResetService::class)->issueToken($user);

        $this->postJson($this->url('/api/v1/auth/reset-password'), [
            'email' => 'parent@hilltop.edu.ng',
            'token' => 'not-the-token-that-was-issued',
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
    }

    public function test_reset_clears_a_lockout()
    {
        $school = $this->makeSchool();
        $user = $this->makeUser($school, 'parent', [
            'email' => 'parent@hilltop.edu.ng',
            'failed_login_attempts' => 5,
            'locked_until' => now()->addHour(),
        ]);

        $token = app(PasswordResetService::class)->issueToken($user);

        $this->postJson($this->url('/api/v1/auth/reset-password'), [
            'email' => 'parent@hilltop.edu.ng',
            'token' => $token,
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ])->assertStatus(200);

        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
        $this->assertFalse((bool) $fresh->must_change_password);
    }

    public function test_a_weak_password_is_refused()
    {
        $school = $this->makeSchool();
        $user = $this->makeUser($school);
        $token = app(PasswordResetService::class)->issueToken($user);

        $this->postJson($this->url('/api/v1/auth/reset-password'), [
            'email' => 'parent@hilltop.edu.ng',
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
    }

    public function test_a_mismatched_confirmation_is_refused()
    {
        $school = $this->makeSchool();
        $user = $this->makeUser($school);
        $token = app(PasswordResetService::class)->issueToken($user);

        $this->postJson($this->url('/api/v1/auth/reset-password'), [
            'email' => 'parent@hilltop.edu.ng',
            'token' => $token,
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'a-different-pass-9',
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------ Sign-out (G1)

    public function test_logout_revokes_the_token_that_made_the_request()
    {
        $school = $this->makeSchool();
        $user = $this->makeUser($school);
        $token = $user->createToken('phone')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson($this->url('/api/v1/auth/logout'))
            ->assertStatus(200);

        $this->forgetAuthenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson($this->url('/api/v1/user'))
            ->assertStatus(401);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'user.logout',
        ]);
    }

    public function test_logout_leaves_the_users_other_sessions_alone()
    {
        $school = $this->makeSchool();
        $user = $this->makeUser($school);
        $phone = $user->createToken('phone')->plainTextToken;
        $browser = $user->createToken('browser')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $phone)
            ->postJson($this->url('/api/v1/auth/logout'))
            ->assertStatus(200);

        $this->forgetAuthenticatedUser();

        // Signing out of a handset must not sign the same teacher out of the
        // browser they left open in the staff room.
        $this->withHeader('Authorization', 'Bearer ' . $browser)
            ->getJson($this->url('/api/v1/user'))
            ->assertStatus(200);
    }

    public function test_logout_requires_authentication()
    {
        $this->makeSchool();

        $this->postJson($this->url('/api/v1/auth/logout'))->assertStatus(401);
    }

    // ------------------------------- Credentials must not come back over the API

    public function test_admin_reset_sends_a_link_instead_of_returning_a_password()
    {
        Mail::fake();
        $school = $this->makeSchool();

        $admin = User::create([
            'name' => 'Head Teacher',
            'email' => 'head@hilltop.edu.ng',
            'password' => Hash::make('admin-password-1'),
        ]);
        UserProfile::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin']);

        $target = $this->makeUser($school, 'teacher');
        $targetToken = $target->createToken('phone')->plainTextToken;
        $adminToken = $admin->createToken('token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson($this->url('/api/v1/users/' . $target->id . '/reset-password'));

        $response->assertStatus(200)->assertJson(['link_sent' => true]);
        $this->assertNull($response->json('temporary_password'), 'A credential must never come back over the API.');

        Mail::assertSentCount(1);

        $this->forgetAuthenticatedUser();

        // The old password stops working immediately, link or no link.
        $this->assertFalse(Hash::check('old-password-1', $target->fresh()->password));
        $this->withHeader('Authorization', 'Bearer ' . $targetToken)
            ->getJson($this->url('/api/v1/user'))
            ->assertStatus(401);
    }

    public function test_a_reset_link_is_never_written_into_notification_history()
    {
        Mail::fake();
        $school = $this->makeSchool();
        $this->makeUser($school);

        $this->postJson($this->url('/api/v1/auth/forgot-password'), [
            'email' => 'parent@hilltop.edu.ng',
        ])->assertStatus(200);

        $logs = NotificationLog::all();
        $this->assertNotEmpty($logs, 'The send should still be recorded, just not verbatim.');

        // A school admin can read GET /notifications/history. A link stored
        // there would be an account takeover for anyone with admin access, so
        // the row records a description instead.
        foreach ($logs as $log) {
            $this->assertStringNotContainsString('reset-password?', (string) $log->body);
            $this->assertStringNotContainsString('token=', (string) $log->body);
        }
    }

    public function test_invite_sends_a_setup_link()
    {
        Mail::fake();
        $school = $this->makeSchool();

        $admin = User::create([
            'name' => 'Head Teacher',
            'email' => 'head@hilltop.edu.ng',
            'password' => Hash::make('admin-password-1'),
        ]);
        UserProfile::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $admin->createToken('t')->plainTextToken)
            ->postJson($this->url('/api/v1/auth/invite'), [
                'name' => 'Teacher Grace',
                'email' => 'grace@hilltop.edu.ng',
                'role' => 'teacher',
            ]);

        $response->assertStatus(201)->assertJson(['setup_link_sent' => true]);
        Mail::assertSentCount(1);

        // The invitee can act on it without the office being involved again.
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'grace@hilltop.edu.ng']);
    }
}
