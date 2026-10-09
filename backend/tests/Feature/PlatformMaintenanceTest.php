<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The operator clears caches from the console instead of over SSH; each run
 * is audited, and nobody else can do it.
 */
class PlatformMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::create(['name' => 'Platform Admin', 'email' => 'ops@schoolpilot.test', 'password' => bcrypt('password')]);
        $this->operator->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);
    }

    public function test_the_operator_sees_cache_status_and_the_tasks()
    {
        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/maintenance')
            ->assertOk()
            ->assertJsonStructure(['environment', 'drivers' => ['cache', 'queue'], 'cached' => ['config', 'routes', 'compiled_views'], 'tasks', 'recent'])
            ->assertJsonFragment(['task' => 'views']);
    }

    public function test_clearing_the_cache_empties_it_and_is_audited()
    {
        Cache::put('stale-answer', 'old', 600);

        $this->actingAs($this->operator, 'sanctum')->postJson('/api/v1/platform/maintenance', ['task' => 'cache'])
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertNull(Cache::get('stale-answer'));
        $log = AuditLog::where('action', 'platform.maintenance.cache')->firstOrFail();
        $this->assertSame($this->operator->id, $log->user_id);
        $this->assertSame('cache:clear', $log->new_values['command']);

        $this->actingAs($this->operator, 'sanctum')->postJson('/api/v1/platform/maintenance', ['task' => 'views'])->assertOk();
        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/maintenance')
            ->assertJsonPath('recent.0.task', 'views')
            ->assertJsonPath('recent.0.by', 'Platform Admin');
    }

    public function test_only_known_tasks_run_and_only_for_the_operator()
    {
        $this->actingAs($this->operator, 'sanctum')->postJson('/api/v1/platform/maintenance', ['task' => 'migrate:fresh'])->assertStatus(422);

        $school = School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield', 'status' => 'active']);
        $admin = User::create(['name' => 'School Admin', 'email' => 'admin@g.test', 'password' => bcrypt('password')]);
        $admin->userProfile()->create(['school_id' => $school->id, 'role' => 'school_admin']);
        $this->app['auth']->forgetGuards();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/maintenance', ['task' => 'cache'])->assertForbidden();
    }
}
