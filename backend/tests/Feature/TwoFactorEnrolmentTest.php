<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Two-factor enrolment.
 *
 * LAUNCH.md §1 requires mandatory TOTP for admin accounts before the first
 * school goes live. Login had verified codes for some time, but no route in the
 * application could set a secret, so the requirement was unmeetable: the only
 * accounts with 2FA were seeded by hand inside a test.
 *
 * Absolute URLs throughout — see the note in SecurityHardeningTest.
 */
class TwoFactorEnrolmentTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private School $school;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Hilltop Sec',
            'slug' => 'hilltop',
            'subdomain' => 'hilltop',
        ]);

        $this->admin = User::create([
            'name' => 'Mrs Adeyemi',
            'email' => 'head@hilltop.test',
            'password' => Hash::make(self::PASSWORD),
        ]);

        UserProfile::create([
            'school_id' => $this->school->id,
            'user_id' => $this->admin->id,
            'role' => 'school_admin',
        ]);
    }

    private function url(string $path): string
    {
        return "http://hilltop.localhost/api/v1{$path}";
    }

    private function totp(): TotpService
    {
        return app(TotpService::class);
    }

    /**
     * The interoperability test.
     *
     * TotpService used a scrambled base32 alphabet
     * (234567QWERTYUIOPASDFGHJKLZXCVBNM) rather than RFC 4648's. Codes it
     * generated verified against codes it generated, so every existing test
     * passed — but Google Authenticator, Authy and every other app decode per
     * RFC 4648 and would have produced six digits that never matched. Pinned
     * here against RFC 6238 Appendix B so it cannot drift back.
     */
    public function test_rfc6238_known_answer()
    {
        // ASCII "12345678901234567890", base32-encoded per RFC 4648.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $vectors = [
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
        ];

        foreach ($vectors as $time => $expected) {
            $this->assertSame(
                $expected,
                $this->totp()->generateCode($secret, intdiv($time, 30)),
                "RFC 6238 vector at T={$time} did not match; the base32 alphabet is wrong."
            );
        }
    }

    public function test_admin_can_enrol_and_then_sign_in_with_a_code()
    {
        $setup = $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/auth/2fa/setup'), ['password' => self::PASSWORD])
            ->assertStatus(200)
            ->assertJsonStructure(['secret', 'otpauth_uri', 'qr_code']);

        $secret = $setup->json('secret');

        // The URI is what the phone scans; it must name the school and account.
        $this->assertStringContainsString('otpauth://totp/', $setup->json('otpauth_uri'));
        $this->assertStringContainsString('secret=' . $secret, $setup->json('otpauth_uri'));
        $this->assertStringStartsWith('data:image/png;base64,', $setup->json('qr_code'));

        // Setup alone must not switch 2FA on — the admin may never scan it.
        $this->assertFalse((bool) $this->admin->fresh()->userProfile->two_factor_enabled);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/auth/2fa/confirm'), ['code' => $this->totp()->generateCode($secret)])
            ->assertStatus(200)
            ->assertJsonStructure(['recovery_codes']);

        $this->assertTrue($this->admin->fresh()->userProfile->hasCompletedTwoFactor());

        // And the code now actually gates login.
        $this->postJson($this->url('/auth/login'), [
            'email' => $this->admin->email,
            'password' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonPath('requires_2fa', true);

        $this->postJson($this->url('/auth/login'), [
            'email' => $this->admin->email,
            'password' => self::PASSWORD,
            'two_factor_code' => $this->totp()->generateCode($secret),
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_confirm_refuses_a_wrong_code_and_leaves_2fa_off()
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/auth/2fa/setup'), ['password' => self::PASSWORD]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/auth/2fa/confirm'), ['code' => '000000'])
            ->assertStatus(422);

        $this->assertFalse($this->admin->fresh()->userProfile->hasCompletedTwoFactor());
    }

    /** A stolen bearer token must not be enough to move the second factor. */
    public function test_setup_requires_the_account_password()
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/auth/2fa/setup'), ['password' => 'not-the-password'])
            ->assertStatus(422);

        $this->assertNull($this->admin->fresh()->userProfile->two_factor_secret);
    }

    public function test_setup_will_not_silently_replace_a_live_authenticator()
    {
        $secret = $this->enrol();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/auth/2fa/setup'), ['password' => self::PASSWORD])
            ->assertStatus(409);

        // The original secret still works.
        $this->postJson($this->url('/auth/login'), [
            'email' => $this->admin->email,
            'password' => self::PASSWORD,
            'two_factor_code' => $this->totp()->generateCode($secret),
        ])->assertStatus(200);
    }

    /** A lost handset must not cost a proprietor their school. */
    public function test_a_recovery_code_signs_in_and_is_then_spent()
    {
        $this->enrol();
        $codes = $this->recoveryCodes();

        $this->postJson($this->url('/auth/login'), [
            'email' => $this->admin->email,
            'password' => self::PASSWORD,
            'two_factor_code' => $codes[0],
        ])->assertStatus(200)->assertJsonStructure(['token']);

        // Single use.
        $this->postJson($this->url('/auth/login'), [
            'email' => $this->admin->email,
            'password' => self::PASSWORD,
            'two_factor_code' => $codes[0],
        ])->assertStatus(422);

        // The others are untouched.
        $this->postJson($this->url('/auth/login'), [
            'email' => $this->admin->email,
            'password' => self::PASSWORD,
            'two_factor_code' => $codes[1],
        ])->assertStatus(200);
    }

    /** Codes get written on paper and typed back by someone else. */
    public function test_a_recovery_code_is_accepted_however_it_is_typed()
    {
        $this->enrol();
        $codes = $this->recoveryCodes();

        $this->postJson($this->url('/auth/login'), [
            'email' => $this->admin->email,
            'password' => self::PASSWORD,
            'two_factor_code' => strtolower(str_replace('-', ' ', $codes[0])),
        ])->assertStatus(200);
    }

    public function test_recovery_codes_are_not_stored_in_the_clear()
    {
        $this->enrol();
        $codes = $this->recoveryCodes();

        $raw = \DB::table('user_profiles')->where('user_id', $this->admin->id)->value('two_factor_recovery_codes');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString($codes[0], $raw);
    }

    public function test_the_totp_secret_is_encrypted_at_rest()
    {
        $secret = $this->enrol();

        $raw = \DB::table('user_profiles')->where('user_id', $this->admin->id)->value('two_factor_secret');

        $this->assertStringNotContainsString($secret, (string) $raw);
    }

    public function test_disabling_requires_both_factors()
    {
        $secret = $this->enrol();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson($this->url('/auth/2fa'), ['password' => 'wrong', 'code' => $this->totp()->generateCode($secret)])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson($this->url('/auth/2fa'), ['password' => self::PASSWORD, 'code' => '000000'])
            ->assertStatus(422);

        $this->assertTrue($this->admin->fresh()->userProfile->hasCompletedTwoFactor());

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson($this->url('/auth/2fa'), ['password' => self::PASSWORD, 'code' => $this->totp()->generateCode($secret)])
            ->assertStatus(200);

        $this->assertFalse($this->admin->fresh()->userProfile->hasCompletedTwoFactor());
    }

    public function test_regenerating_recovery_codes_invalidates_the_old_set()
    {
        $this->enrol();
        $old = $this->recoveryCodes();

        $new = $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/auth/2fa/recovery-codes'), ['password' => self::PASSWORD])
            ->assertStatus(200)
            ->json('recovery_codes');

        $this->assertNotEquals($old, $new);

        $this->postJson($this->url('/auth/login'), [
            'email' => $this->admin->email,
            'password' => self::PASSWORD,
            'two_factor_code' => $old[0],
        ])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Enforcement — the LAUNCH.md pre-flight item itself
    // -----------------------------------------------------------------

    public function test_enforcement_is_off_by_default()
    {
        // Turning it on before a live school's admins have enrolled would lock
        // all of them out at once, so the default must stay permissive.
        $this->assertFalse((bool) config('auth.require_admin_two_factor'));

        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/students'))
            ->assertStatus(200);
    }

    public function test_when_required_an_unenrolled_admin_is_blocked_but_can_still_enrol()
    {
        config()->set('auth.require_admin_two_factor', true);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/students'))
            ->assertStatus(403)
            ->assertJsonPath('two_factor_setup_required', true);

        // The way out stays open, or the block has no exit.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/auth/2fa/setup'), ['password' => self::PASSWORD])
            ->assertStatus(200);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/auth/logout'))
            ->assertStatus(200);
    }

    public function test_when_required_an_enrolled_admin_passes()
    {
        $this->enrol();
        config()->set('auth.require_admin_two_factor', true);

        $this->actingAs($this->admin->fresh(), 'sanctum')
            ->getJson($this->url('/students'))
            ->assertStatus(200);
    }

    /** Teachers and parents are out of scope; the rule is for admins. */
    public function test_enforcement_does_not_apply_to_teachers()
    {
        config()->set('auth.require_admin_two_factor', true);

        $teacher = User::create([
            'name' => 'Mr Okafor',
            'email' => 'teacher@hilltop.test',
            'password' => Hash::make(self::PASSWORD),
        ]);
        UserProfile::create([
            'school_id' => $this->school->id,
            'user_id' => $teacher->id,
            'role' => 'teacher',
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson($this->url('/students'))
            ->assertStatus(200);
    }

    public function test_a_mandatory_second_factor_cannot_be_turned_off()
    {
        $secret = $this->enrol();
        config()->set('auth.require_admin_two_factor', true);

        $this->actingAs($this->admin->fresh(), 'sanctum')
            ->deleteJson($this->url('/auth/2fa'), [
                'password' => self::PASSWORD,
                'code' => $this->totp()->generateCode($secret),
            ])
            ->assertStatus(403);

        $this->assertTrue($this->admin->fresh()->userProfile->hasCompletedTwoFactor());
    }

    // -----------------------------------------------------------------

    /** Run the real enrolment flow; returns the secret. */
    private function enrol(): string
    {
        $secret = $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/auth/2fa/setup'), ['password' => self::PASSWORD])
            ->json('secret');

        $this->lastRecoveryCodes = $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/auth/2fa/confirm'), ['code' => $this->totp()->generateCode($secret)])
            ->json('recovery_codes');

        return $secret;
    }

    /** @var string[] */
    private array $lastRecoveryCodes = [];

    /** @return string[] */
    private function recoveryCodes(): array
    {
        return $this->lastRecoveryCodes;
    }
}
