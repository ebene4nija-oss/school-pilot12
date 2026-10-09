<?php

namespace Tests\Feature;

use App\Models\OfflineClientRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The offline CBT lab client's releases: registered and published in Super
 * Admin, served from one stable download link, and announced to installed
 * clients. Plus the blog video placements the marketing site shows.
 */
class OfflineClientReleaseTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::create(['name' => 'Ops', 'email' => 'ops@schoolpilot.test', 'password' => bcrypt('password')]);
        $this->operator->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);
    }

    private function release(array $overrides = []): array
    {
        return $overrides + [
            'version' => '1.0.0',
            'download_url' => 'https://github.com/schoolpilot/offline-cbt/releases/download/v1.0.0/SchoolPilot-Setup-1.0.0.exe',
            'sha256' => str_repeat('AB', 32),
            'size_bytes' => 4718592,
            'notes' => 'First release.',
        ];
    }

    private function publish(array $overrides = []): array
    {
        return $this->actingAs($this->operator)
            ->postJson('/api/v1/platform/downloads/offline-cbt', $this->release($overrides + ['status' => 'published']))
            ->assertCreated()
            ->json('data');
    }

    public function test_releases_are_operator_only(): void
    {
        $this->getJson('/api/v1/platform/downloads/offline-cbt')->assertUnauthorized();

        $teacher = User::create(['name' => 'T', 'email' => 't@x.test', 'password' => bcrypt('password')]);
        $this->actingAs($teacher)->getJson('/api/v1/platform/downloads/offline-cbt')->assertForbidden();
        $this->actingAs($teacher)->postJson('/api/v1/platform/downloads/offline-cbt', $this->release())->assertForbidden();
    }

    public function test_the_link_404s_until_a_release_is_published(): void
    {
        $this->getJson('/api/v1/public/downloads/offline-cbt')->assertNotFound();
        $this->getJson('/api/v1/public/downloads/offline-cbt/latest')->assertOk()->assertJsonPath('data', null);

        // A draft is not served.
        $this->actingAs($this->operator)->postJson('/api/v1/platform/downloads/offline-cbt', $this->release())->assertCreated();
        $this->getJson('/api/v1/public/downloads/offline-cbt')->assertNotFound();
    }

    public function test_the_stable_link_redirects_to_the_current_release_and_counts_downloads(): void
    {
        $first = $this->publish();
        $this->assertNotNull($first['published_at']);
        $this->assertSame(str_repeat('ab', 32), $first['sha256']);

        $this->get('/api/v1/public/downloads/offline-cbt')
            ->assertRedirect($this->release()['download_url']);

        // A newer version takes over the same link straight away.
        $this->publish(['version' => '1.2.0', 'download_url' => 'https://downloads.schoolpilot.org.ng/SchoolPilot-Setup-1.2.0.exe']);
        $this->get('/api/v1/public/downloads/offline-cbt')
            ->assertRedirect('https://downloads.schoolpilot.org.ng/SchoolPilot-Setup-1.2.0.exe');

        $this->assertSame(1, OfflineClientRelease::where('version', '1.0.0')->value('downloads'));
        $this->assertSame(1, OfflineClientRelease::where('version', '1.2.0')->value('downloads'));
    }

    public function test_current_is_the_highest_version_not_the_latest_published(): void
    {
        $this->publish(['version' => '1.10.0']);
        $this->publish(['version' => '1.9.0']); // a late hotfix to an older line

        $this->getJson('/api/v1/public/downloads/offline-cbt/latest')->assertJsonPath('data.version', '1.10.0');
    }

    public function test_retiring_a_bad_release_rolls_the_link_back(): void
    {
        $this->publish(['version' => '1.0.0']);
        $bad = $this->publish(['version' => '1.1.0']);

        $this->actingAs($this->operator)->putJson("/api/v1/platform/downloads/offline-cbt/{$bad['id']}", ['status' => 'retired'])->assertOk();

        $this->getJson('/api/v1/public/downloads/offline-cbt/latest')->assertJsonPath('data.version', '1.0.0');
    }

    public function test_installed_clients_learn_the_minimum_supported_version(): void
    {
        $this->publish(['version' => '1.0.0']);
        $this->publish(['version' => '1.1.0', 'is_required' => true]);
        $this->publish(['version' => '1.2.0']);

        $latest = $this->getJson('/api/v1/public/downloads/offline-cbt/latest')->assertOk();
        $latest->assertJsonPath('data.version', '1.2.0')
            ->assertJsonPath('data.min_supported_version', '1.1.0');
        $this->assertStringEndsWith('/api/v1/public/downloads/offline-cbt', $latest->json('data.download_url'));
    }

    public function test_a_release_needs_a_version_an_https_link_and_a_checksum(): void
    {
        $this->actingAs($this->operator)->postJson('/api/v1/platform/downloads/offline-cbt', $this->release([
            'version' => 'v1',
            'download_url' => 'http://insecure.example/setup.exe',
            'sha256' => 'abc',
        ]))->assertStatus(422)->assertJsonValidationErrors(['version', 'download_url', 'sha256']);

        $this->publish();
        $this->actingAs($this->operator)->postJson('/api/v1/platform/downloads/offline-cbt', $this->release())
            ->assertStatus(422)->assertJsonValidationErrors(['version']);
    }

    public function test_a_published_release_keeps_its_file_and_only_a_draft_can_be_deleted(): void
    {
        $live = $this->publish();

        $this->actingAs($this->operator)->putJson("/api/v1/platform/downloads/offline-cbt/{$live['id']}", [
            'download_url' => 'https://elsewhere.example/other.exe',
        ])->assertStatus(422)->assertJsonValidationErrors(['download_url']);

        // Notes and the required flag may still change.
        $this->actingAs($this->operator)->putJson("/api/v1/platform/downloads/offline-cbt/{$live['id']}", [
            'notes' => 'Fixes the clock on resume.', 'is_required' => true,
        ])->assertOk()->assertJsonPath('data.is_required', true);

        $this->actingAs($this->operator)->deleteJson("/api/v1/platform/downloads/offline-cbt/{$live['id']}")->assertStatus(422);

        $draft = $this->actingAs($this->operator)->postJson('/api/v1/platform/downloads/offline-cbt', $this->release(['version' => '2.0.0']))->json('data');
        $this->actingAs($this->operator)->deleteJson("/api/v1/platform/downloads/offline-cbt/{$draft['id']}")->assertOk();
        $this->assertDatabaseMissing('offline_client_releases', ['version' => '2.0.0']);
    }

    public function test_the_site_config_carries_the_current_release(): void
    {
        $this->getJson('/api/v1/public/site-config')->assertOk()->assertJsonPath('offline_client', null);

        $this->publish(['version' => '1.3.0']);

        $this->getJson('/api/v1/public/site-config')
            ->assertJsonPath('offline_client.version', '1.3.0')
            ->assertJsonPath('offline_client.size_bytes', 4718592);
    }

    public function test_a_blog_video_can_be_placed_on_a_feature_page(): void
    {
        $post = [
            'title' => 'Running a CBT paper with no internet',
            'excerpt' => 'Five minutes in a real computer lab.',
            'body' => 'Watch the walkthrough.',
            'status' => 'published',
        ];

        // A placement needs a video, and only known places are accepted.
        $this->actingAs($this->operator)->postJson('/api/v1/platform/blog', $post + ['video_placement' => 'cbt'])
            ->assertStatus(422)->assertJsonValidationErrors(['video_placement']);
        $this->actingAs($this->operator)->postJson('/api/v1/platform/blog', $post + ['video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'video_placement' => 'nowhere'])
            ->assertStatus(422)->assertJsonValidationErrors(['video_placement']);

        $this->actingAs($this->operator)->postJson('/api/v1/platform/blog', $post + ['video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'video_placement' => 'cbt'])
            ->assertCreated();

        $this->getJson('/api/v1/public/blog')->assertJsonPath('data.0.video_placement', 'cbt');
        $this->actingAs($this->operator)->getJson('/api/v1/platform/blog')->assertJsonPath('video_placements.download', 'Offline CBT client download page');
    }
}
