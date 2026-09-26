<?php

namespace Tests\Feature;

use App\Models\AiUsageDaily;
use App\Models\PlatformSetting;
use App\Models\School;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Platform settings and AI configuration from the operator console.
 *
 * Every one of these used to live only in backend.env, reachable over SSH and
 * nothing else. The properties that matter: a saved key reaches the service
 * that uses it, no response ever carries a secret back out, and nothing is
 * stored in the clear.
 */
class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $schoolAdmin;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::create([
            'name' => 'Platform Admin', 'email' => 'ops@schoolpilot.test', 'password' => bcrypt('password'),
        ]);
        $this->operator->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);

        $this->school = School::create([
            'name' => 'Greenfield Academy', 'slug' => 'greenfield', 'subdomain' => 'greenfield', 'status' => 'active',
        ]);

        $this->schoolAdmin = User::create([
            'name' => 'Greenfield Admin', 'email' => 'admin@greenfield.test', 'password' => bcrypt('password'),
        ]);
        $this->schoolAdmin->userProfile()->create(['school_id' => $this->school->id, 'role' => 'school_admin']);

        PlatformSettings::forget();
    }

    private function save(array $values = [], array $clear = [])
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->putJson('/api/v1/platform/settings', ['values' => $values, 'clear' => $clear]);
    }

    private function setting(array $payload, string $key): array
    {
        foreach ($payload['groups'] as $group) {
            foreach ($group['settings'] as $setting) {
                if ($setting['key'] === $key) {
                    return $setting;
                }
            }
        }

        $this->fail("No setting {$key} in the payload.");
    }

    // ------------------------------------------------------------------ secrets

    /** The whole point: a key saved in the console is the key the service uses. */
    public function test_a_saved_key_reaches_the_service_config(): void
    {
        $this->save(['services.anthropic.api_key' => 'sk-ant-live-abcdefgh1234'])->assertOk();

        $this->assertSame('sk-ant-live-abcdefgh1234', config('services.anthropic.api_key'));
    }

    public function test_a_secret_is_never_returned(): void
    {
        $response = $this->save(['services.anthropic.api_key' => 'sk-ant-live-abcdefgh1234'])->assertOk();

        $this->assertStringNotContainsString('sk-ant-live-abcdefgh1234', $response->getContent());

        $index = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/settings')->assertOk();
        $this->assertStringNotContainsString('sk-ant-live-abcdefgh1234', $index->getContent());

        $setting = $this->setting($index->json(), 'services.anthropic.api_key');
        $this->assertTrue($setting['is_set']);
        $this->assertNull($setting['value']);
        // Enough to tell which key is live.
        $this->assertSame('••••1234', $setting['hint']);
        $this->assertSame('console', $setting['source']);
    }

    /** A database dump — which is what a backup is — must not carry keys. */
    public function test_values_are_ciphertext_at_rest(): void
    {
        $this->save(['services.anthropic.api_key' => 'sk-ant-live-abcdefgh1234'])->assertOk();

        $raw = DB::table('platform_settings')->where('key', 'services.anthropic.api_key')->value('value');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('sk-ant-live', $raw);
    }

    /** A form can resubmit without the operator re-typing every key. */
    public function test_an_empty_secret_leaves_the_stored_one_alone(): void
    {
        $this->save(['services.anthropic.api_key' => 'sk-ant-live-abcdefgh1234'])->assertOk();
        $this->save(['services.anthropic.api_key' => ''])->assertOk();

        $this->assertSame('sk-ant-live-abcdefgh1234', config('services.anthropic.api_key'));
    }

    public function test_clearing_a_secret_falls_back_to_the_server_value(): void
    {
        config(['services.sms.api_key' => 'env-sms-key-000000']);
        PlatformSettings::forget();
        PlatformSettings::apply();

        $this->save(['services.sms.api_key' => 'console-sms-key-111111'])->assertOk();
        $this->assertSame('console-sms-key-111111', config('services.sms.api_key'));

        $this->save([], ['services.sms.api_key'])->assertOk();
        $this->assertSame('env-sms-key-000000', config('services.sms.api_key'));

        $this->assertDatabaseMissing('platform_settings', ['key' => 'services.sms.api_key']);
    }

    public function test_a_key_set_only_on_the_server_is_reported_as_such(): void
    {
        config(['services.whatsapp.api_key' => 'env-whatsapp-key-9999']);

        $setting = $this->setting(
            $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/settings')->assertOk()->json(),
            'services.whatsapp.api_key',
        );

        $this->assertSame('server', $setting['source']);
        $this->assertTrue($setting['is_set']);
    }

    /**
     * Rotating APP_KEY makes stored values unreadable. That must degrade to the
     * env value, not take every request down with it.
     */
    public function test_an_unreadable_value_does_not_break_the_app(): void
    {
        DB::table('platform_settings')->insert([
            'key' => 'services.anthropic.api_key',
            'value' => 'not-ciphertext-under-this-app-key',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        config(['services.anthropic.api_key' => 'env-key']);
        PlatformSettings::forget();

        PlatformSettings::apply();

        $this->assertSame('env-key', config('services.anthropic.api_key'));

        $setting = $this->setting(
            $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/settings')->assertOk()->json(),
            'services.anthropic.api_key',
        );
        $this->assertSame('unreadable', $setting['source']);
    }

    // -------------------------------------------------------------- AI config

    /** Entered in Naira, stored in kobo, read by the ledger in kobo. */
    public function test_the_spend_cap_is_entered_in_naira_and_applied_in_kobo(): void
    {
        $this->save(['services.anthropic.daily_cap_kobo' => '25000'])->assertOk();

        $this->assertSame(2500000, config('services.anthropic.daily_cap_kobo'));

        $setting = $this->setting(
            $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/settings')->json(),
            'services.anthropic.daily_cap_kobo',
        );
        $this->assertEquals(25000, $setting['value']);
    }

    public function test_the_model_must_be_one_offered(): void
    {
        $this->save(['services.anthropic.comment_model' => 'gpt-4'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('values.services.anthropic.comment_model');

        $this->save(['services.anthropic.comment_model' => 'claude-sonnet-5'])->assertOk();
        $this->assertSame('claude-sonnet-5', config('services.anthropic.comment_model'));
    }

    public function test_the_alert_threshold_is_a_fraction(): void
    {
        $this->save(['services.anthropic.alert_threshold' => '80'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('values.services.anthropic.alert_threshold');
    }

    /** A malformed service account would save quietly and fail every push. */
    public function test_firebase_credentials_must_be_a_real_service_account(): void
    {
        $this->save(['services.fcm.credentials' => '{"type":"service_account"}'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('values.services.fcm.credentials');

        $json = json_encode([
            'type' => 'service_account',
            'client_email' => 'push@schoolpilot-prod.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n",
        ]);

        $response = $this->save(['services.fcm.credentials' => $json])->assertOk();

        // The service account's address is shown; the private key is not.
        $setting = $this->setting($response->json(), 'services.fcm.credentials');
        $this->assertSame('push@schoolpilot-prod.iam.gserviceaccount.com', $setting['hint']);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $response->getContent());
    }

    public function test_bool_settings_apply_as_booleans(): void
    {
        $this->save(['services.sms.password_links' => true])->assertOk();

        $this->assertTrue(config('services.sms.password_links'));
    }

    // ---------------------------------------------------------------- guards

    /** Stub mode serves canned remarks with a real child's name in them. */
    public function test_settings_outside_the_registry_are_refused(): void
    {
        $this->save(['services.anthropic.stub' => true])->assertStatus(422);
        $this->save(['app.key' => 'base64:hijack'])->assertStatus(422);

        $this->assertDatabaseCount('platform_settings', 0);
    }

    public function test_a_school_admin_cannot_read_or_change_platform_settings(): void
    {
        $this->actingAs($this->schoolAdmin, 'sanctum')->getJson('/api/v1/platform/settings')->assertForbidden();

        $this->actingAs($this->schoolAdmin, 'sanctum')
            ->putJson('/api/v1/platform/settings', ['values' => ['services.anthropic.api_key' => 'sk-ant-evil']])
            ->assertForbidden();

        $this->assertDatabaseCount('platform_settings', 0);
    }

    /** Which keys changed goes in the audit log. What they changed to does not. */
    public function test_changes_are_audited_without_their_values(): void
    {
        $this->save(['services.anthropic.api_key' => 'sk-ant-live-abcdefgh1234'])->assertOk();

        $log = DB::table('audit_logs')->where('action', 'platform.settings.updated')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('services.anthropic.api_key', $log->new_values);
        $this->assertStringNotContainsString('sk-ant-live', $log->new_values);
    }

    // -------------------------------------------------------------- AI usage

    public function test_ai_usage_reports_today_against_the_cap(): void
    {
        $this->save(['services.anthropic.daily_cap_kobo' => '1000'])->assertOk();

        AiUsageDaily::create([
            'school_id' => $this->school->id,
            'usage_date' => now()->toDateString(),
            'cost_kobo' => 80000,
            'calls' => 12,
            'usd_to_ngn' => 1500,
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/platform/ai-usage')
            ->assertOk()
            ->assertJsonPath('cap_naira', 1000)
            ->assertJsonPath('data.0.school', 'Greenfield Academy')
            ->assertJsonPath('data.0.spent_naira', 800)
            ->assertJsonPath('data.0.cap_used_percentage', 80)
            ->assertJsonPath('data.0.at_cap', false);
    }

    public function test_an_operator_can_switch_ai_off_for_one_school(): void
    {
        $this->school->update(['ai_enabled' => true]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->school->id}/ai", ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('school.ai_enabled', false);

        $this->assertFalse((bool) $this->school->fresh()->ai_enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.school.ai_toggled']);
    }

    public function test_the_platform_row_has_no_ai_switch(): void
    {
        $platform = School::create([
            'name' => 'SchoolPilot HQ', 'slug' => 'admin', 'subdomain' => 'admin',
            'status' => 'active', 'is_platform' => true,
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$platform->id}/ai", ['enabled' => true])
            ->assertNotFound();
    }
}
