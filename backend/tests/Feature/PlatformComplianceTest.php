<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Backup status (read from what backup-db.sh leaves behind) and data requests.
 */
class PlatformComplianceTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $admin;

    private School $school;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield']);
        $this->operator = $this->person('Ops', 'ops@schoolpilot.test', 'super_admin', null);
        $this->admin = $this->person('Head', 'head@gf.test', 'school_admin', $this->school->id);

        $this->dir = sys_get_temp_dir().'/sp-backups-'.uniqid();
        mkdir($this->dir);
        config(['schoolpilot.backup_dir' => $this->dir, 'schoolpilot.backup_log' => $this->dir.'/backup.log']);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function person(string $name, string $email, string $role, ?int $schoolId): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $schoolId, 'role' => $role]);

        return $user;
    }

    private function log(string ...$lines): void
    {
        file_put_contents($this->dir.'/backup.log', implode("\n", $lines)."\n");
    }

    public function test_a_recent_backup_reads_as_ok_with_its_offsite_state(): void
    {
        file_put_contents($this->dir.'/schoolpilot-x.dump.gpg', 'x');
        $at = now()->subHours(3)->toIso8601String();
        $this->log('warning: BACKUP_S3_BUCKET unset — backup is local only', "{$at} backup ok: schoolpilot-x.dump.gpg (12M)");

        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/backups')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('last_file', 'schoolpilot-x.dump.gpg')
            ->assertJsonPath('last_size', '12M')
            ->assertJsonPath('offsite', false)
            ->assertJsonPath('files_kept', 1);
    }

    public function test_a_failure_after_the_last_success_reads_as_failing(): void
    {
        $at = now()->subHours(3)->toIso8601String();
        $this->log("{$at} backup ok: schoolpilot-x.dump.gpg (12M)", 'fatal: no GPG public key for ops@schoolpilot.org.ng, so this backup cannot be encrypted.');

        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/backups')
            ->assertOk()
            ->assertJsonPath('status', 'failing')
            ->assertJsonPath('last_error', 'no GPG public key for ops@schoolpilot.org.ng, so this backup cannot be encrypted.');
    }

    public function test_an_old_backup_is_stale_and_no_access_is_unknown(): void
    {
        $this->log(now()->subDays(3)->toIso8601String().' backup ok: old.dump.gpg (1M)');
        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/backups')->assertJsonPath('status', 'stale');

        config(['schoolpilot.backup_dir' => '/no/such/dir', 'schoolpilot.backup_log' => '/no/such/log']);
        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/backups')
            ->assertJsonPath('status', 'unknown')
            ->assertJsonPath('visible', false);
    }

    public function test_restore_tests_are_recorded_and_listed(): void
    {
        $this->actingAs($this->operator, 'sanctum')->postJson('/api/v1/platform/backups/restore-tests', [
            'tested_on' => now()->toDateString(), 'outcome' => 'passed', 'notes' => 'Restored to staging, 1,204 students.',
        ])->assertCreated();

        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/backups')
            ->assertJsonPath('restore_tests.0.outcome', 'passed')
            ->assertJsonPath('restore_tests.0.recorded_by', 'Ops');
    }

    public function test_data_requests_list_exports_and_withdrawals_without_names(): void
    {
        DB::table('school_data_exports')->insert([
            'school_id' => $this->school->id, 'requested_by' => $this->admin->id, 'status' => 'complete',
            'includes_medical' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/data-requests')
            ->assertOk()
            ->assertJsonPath('exports.0.school', 'Greenfield')
            ->assertJsonPath('exports.0.includes_medical', true)
            ->assertJsonPath('exports.0.requested_by_role', 'school_admin')
            ->assertJsonPath('export_totals.with_medical', 1)
            ->assertJsonPath('deletion_requests', null);
    }

    public function test_schools_cannot_read_platform_compliance(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/platform/backups')->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/platform/data-requests')->assertForbidden();
    }
}
