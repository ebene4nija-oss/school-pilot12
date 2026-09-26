<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use App\Services\AiManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module switches (SchoolPilot's) and AI features (the school's, within
 * SchoolPilot's AI switch).
 */
class SchoolFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $teacher;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield']);
        $this->admin = $this->person('Head', 'admin@gf.test', 'school_admin', $this->school->id);
        $this->teacher = $this->person('Mr Bello', 'bello@gf.test', 'teacher', $this->school->id);
        $this->operator = $this->person('Ops', 'ops@schoolpilot.test', 'super_admin', null);
    }

    private function person(string $name, string $email, string $role, ?int $schoolId): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $schoolId, 'role' => $role]);

        return $user;
    }

    public function test_an_unconfigured_school_has_every_module_and_ai_feature(): void
    {
        $res = $this->actingAs($this->teacher, 'sanctum')->getJson('/api/v1/school/features')->assertOk();

        $this->assertTrue(collect($res->json('modules'))->every(fn ($m) => $m['enabled']));
        $this->assertTrue($res->json('ai.available'));
        $this->assertTrue(collect($res->json('ai.features'))->every(fn ($f) => $f['active']));
    }

    public function test_switching_a_module_off_closes_its_endpoints(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/hostel/rooms')->assertOk();

        $this->actingAs($this->operator, 'sanctum')
            ->putJson("/api/v1/platform/schools/{$this->school->id}/features", ['modules' => ['hostel' => false]])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/hostel/rooms')
            ->assertForbidden()
            ->assertJsonPath('code', 'module_disabled')
            ->assertJsonPath('module', 'hostel');

        // Other modules are untouched.
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/library/books')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.school.modules_changed', 'school_id' => $this->school->id]);
        $this->assertSame(['hostel' => true], AuditLog::where('action', 'platform.school.modules_changed')->first()->old_values);
    }

    public function test_the_clinic_switch_covers_the_clinic_and_not_the_health_check(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->putJson("/api/v1/platform/schools/{$this->school->id}/features", ['modules' => ['clinic' => false]])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/health/clinic-visits')->assertForbidden();
        $this->getJson('/api/v1/health')->assertOk();
    }

    public function test_only_the_operator_switches_modules(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/platform/schools/{$this->school->id}/features", ['modules' => ['cbt' => false]])
            ->assertForbidden();

        $this->actingAs($this->operator, 'sanctum')
            ->putJson("/api/v1/platform/schools/{$this->school->id}/features", ['modules' => ['faculties' => false]])
            ->assertStatus(422);
    }

    public function test_a_school_turns_an_ai_feature_off_and_the_service_honours_it(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/ai-features', ['features' => ['tutor' => false]])
            ->assertOk();

        $ai = app(AiManagerService::class);
        $this->assertFalse($ai->isAiEnabled($this->school->id, 'tutor'));
        $this->assertTrue($ai->isAiEnabled($this->school->id, 'lesson_plans'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'school.ai_features.updated', 'user_id' => $this->admin->id]);
    }

    public function test_a_school_cannot_turn_on_ai_that_schoolpilot_switched_off(): void
    {
        $this->school->update(['ai_enabled' => false]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/ai/settings', ['ai_enabled' => true])
            ->assertStatus(422);

        $this->assertFalse($this->school->fresh()->ai_enabled);

        // Its own choices are kept, but none is active.
        $res = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/ai-features', ['features' => ['lesson_plans' => true]])
            ->assertOk();

        $this->assertFalse($res->json('ai.available'));
        $this->assertFalse(collect($res->json('ai.features'))->contains('active', true));
    }

    public function test_teachers_cannot_change_ai_features_and_unknown_ones_are_refused(): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->putJson('/api/v1/school/ai-features', ['features' => ['tutor' => false]])
            ->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/ai-features', ['features' => ['grading' => true]])
            ->assertStatus(422);
    }

    /** A school's own AI key is encrypted at rest and never sent back. */
    public function test_a_schools_ai_key_is_encrypted_and_never_returned(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/ai/settings', ['ai_api_settings' => ['api_key' => 'sk-ant-SCHOOL-SECRET-123', 'model' => 'claude-haiku-4-5']])
            ->assertOk()
            ->assertJsonPath('school_ai_config.custom_api_configured', true);

        $this->assertStringNotContainsString('SCHOOL-SECRET', $res->getContent());

        $raw = \Illuminate\Support\Facades\DB::table('schools')->where('id', $this->school->id)->value('ai_api_settings');
        $this->assertStringNotContainsString('SCHOOL-SECRET', (string) $raw);

        $this->assertSame('sk-ant-SCHOOL-SECRET-123', app(AiManagerService::class)->getApiConfig($this->school->id)['api_key']);
        $this->assertArrayNotHasKey('ai_api_settings', $this->school->fresh()->toArray());
    }

    public function test_existing_plain_keys_are_encrypted_by_the_migration(): void
    {
        \Illuminate\Support\Facades\DB::table('schools')->where('id', $this->school->id)
            ->update(['ai_api_settings' => json_encode(['api_key' => 'sk-OLD-PLAIN'])]);

        $migration = require database_path('migrations/2026_09_26_120000_encrypt_school_ai_api_settings.php');
        $migration->up();
        $migration->up(); // a second run must not double-encrypt

        $raw = \Illuminate\Support\Facades\DB::table('schools')->where('id', $this->school->id)->value('ai_api_settings');
        $this->assertStringNotContainsString('sk-OLD-PLAIN', (string) $raw);
        $this->assertSame('sk-OLD-PLAIN', $this->school->fresh()->ai_api_settings['api_key']);
    }
}
