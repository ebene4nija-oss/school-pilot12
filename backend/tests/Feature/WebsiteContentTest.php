<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The marketing site's blog, comparison table, share preview and search-engine
 * verification tokens, all managed from the operator console.
 */
class WebsiteContentTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->operator = User::create(['name' => 'Ops', 'email' => 'ops@schoolpilot.test', 'password' => bcrypt('password')]);
        $this->operator->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'How to compute CA and exam scores',
            'excerpt' => 'A walk-through of weights, totals and positions.',
            'body' => "## Step one\n\nSet the weights.",
            'status' => 'published',
        ];
    }

    public function test_blog_and_comparison_are_operator_only(): void
    {
        $this->getJson('/api/v1/platform/blog')->assertUnauthorized();

        $teacher = User::create(['name' => 'T', 'email' => 't@x.test', 'password' => bcrypt('password')]);
        $this->actingAs($teacher)->getJson('/api/v1/platform/blog')->assertForbidden();
        $this->actingAs($teacher)->putJson('/api/v1/platform/comparison', ['rows' => [], 'competitors' => []])->assertForbidden();
    }

    public function test_a_published_post_reaches_the_public_feed_and_a_draft_does_not(): void
    {
        $live = $this->actingAs($this->operator)->postJson('/api/v1/platform/blog', $this->payload())->assertCreated();
        $this->assertSame('how-to-compute-ca-and-exam-scores', $live->json('data.slug'));
        $this->assertNotNull($live->json('data.published_at'));

        $this->actingAs($this->operator)->postJson('/api/v1/platform/blog', $this->payload(['title' => 'Unfinished', 'status' => 'draft']))->assertCreated();

        $feed = $this->getJson('/api/v1/public/blog')->assertOk();
        $this->assertCount(1, $feed->json('data'));
        $this->assertSame('## Step one', explode("\n", $feed->json('data.0.body'))[0]);
        $this->assertArrayNotHasKey('status', $feed->json('data.0'));
    }

    public function test_a_post_carries_a_category_tidy_tags_and_a_reading_time(): void
    {
        $post = $this->actingAs($this->operator)->postJson('/api/v1/platform/blog', $this->payload([
            'category' => ' Results ',
            'tags' => ['CA', 'broadsheet', 'ca', ' '],
        ]))->assertCreated();

        $this->assertSame('Results', $post->json('data.category'));
        $this->assertSame(['CA', 'broadsheet'], $post->json('data.tags'));
        $this->assertSame(1, $post->json('data.reading_minutes'));
        $this->assertSame(['CA', 'broadsheet'], $this->getJson('/api/v1/public/blog')->json('data.0.tags'));
    }

    public function test_a_scheduled_post_stays_off_the_feed_until_its_date(): void
    {
        $this->actingAs($this->operator)->postJson('/api/v1/platform/blog', $this->payload(['publish_at' => now()->addDays(3)->toIso8601String()]))->assertCreated();
        $this->assertCount(0, $this->getJson('/api/v1/public/blog')->json('data'));

        $this->travel(4)->days();
        $this->assertCount(1, $this->getJson('/api/v1/public/blog')->json('data'));
    }

    public function test_images_can_be_placed_inside_a_post(): void
    {
        $url = $this->actingAs($this->operator)->post('/api/v1/platform/blog/images', ['image' => UploadedFile::fake()->image('chart.png', 800, 500)], ['Accept' => 'application/json'])
            ->assertCreated()->json('url');
        $this->assertStringContainsString('website/blog/inline/', $url);

        $this->actingAs($this->operator)->post('/api/v1/platform/blog/images', ['image' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_slugs_stay_unique_and_published_at_is_stamped_once(): void
    {
        $a = $this->actingAs($this->operator)->postJson('/api/v1/platform/blog', $this->payload())->json('data');
        $b = $this->actingAs($this->operator)->postJson('/api/v1/platform/blog', $this->payload())->json('data');
        $this->assertSame($a['slug'].'-2', $b['slug']);

        $this->actingAs($this->operator)->putJson("/api/v1/platform/blog/{$a['id']}", $this->payload(['title' => 'Renamed']))
            ->assertOk()
            ->assertJsonPath('data.slug', $a['slug'])
            ->assertJsonPath('data.published_at', $a['published_at']);

        $this->actingAs($this->operator)->putJson("/api/v1/platform/blog/{$b['id']}", $this->payload(['slug' => $a['slug']]))
            ->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_only_youtube_and_vimeo_links_are_accepted_as_video(): void
    {
        $this->actingAs($this->operator)->postJson('/api/v1/platform/blog', $this->payload(['video_url' => 'https://evil.example/watch?v=dQw4w9WgXcQ']))
            ->assertStatus(422)->assertJsonValidationErrors('video_url');

        $this->actingAs($this->operator)->postJson('/api/v1/platform/blog', $this->payload(['video_url' => 'javascript:alert(1)']))
            ->assertStatus(422);

        $this->actingAs($this->operator)->postJson('/api/v1/platform/blog', $this->payload(['video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']))
            ->assertCreated()->assertJsonPath('data.video', ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ']);
    }

    public function test_video_links_are_normalised(): void
    {
        $this->assertSame(['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'], BlogPost::parseVideo('https://youtu.be/dQw4w9WgXcQ?t=5'));
        $this->assertSame(['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'], BlogPost::parseVideo('https://youtube.com/shorts/dQw4w9WgXcQ'));
        $this->assertSame(['provider' => 'vimeo', 'id' => '123456789'], BlogPost::parseVideo('https://vimeo.com/123456789'));
        $this->assertSame(['provider' => 'vimeo', 'id' => '123456789'], BlogPost::parseVideo('https://player.vimeo.com/video/123456789'));
        $this->assertNull(BlogPost::parseVideo('https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'));
        $this->assertNull(BlogPost::parseVideo('https://youtube.com/watch?v=short'));
        $this->assertNull(BlogPost::parseVideo(null));
    }

    public function test_a_cover_needs_to_be_large_enough_to_double_as_a_share_preview(): void
    {
        $id = $this->actingAs($this->operator)->postJson('/api/v1/platform/blog', $this->payload())->json('data.id');

        $this->actingAs($this->operator)->postJson("/api/v1/platform/blog/$id/cover", ['image' => UploadedFile::fake()->image('c.png', 800, 400)])
            ->assertStatus(422)->assertJsonValidationErrors('image');

        $this->actingAs($this->operator)->postJson("/api/v1/platform/blog/$id/cover", ['image' => UploadedFile::fake()->image('c.png', 1200, 630)])
            ->assertCreated();
        $this->assertCount(1, Storage::disk('public')->files('website/blog'));

        $this->actingAs($this->operator)->deleteJson("/api/v1/platform/blog/$id")->assertOk();
        $this->assertCount(0, Storage::disk('public')->files('website/blog'));
    }

    public function test_the_comparison_stays_hidden_until_a_verified_competitor_is_published(): void
    {
        $this->getJson('/api/v1/public/comparison')->assertOk()->assertJson(['data' => null]);

        $doc = [
            'rows' => [['label' => 'Result computation', 'schoolpilot' => '₦500 per student per term'], ['label' => 'CBT', 'schoolpilot' => 'Included']],
            'competitors' => [[
                'name' => 'Rival', 'website' => 'https://rival.example', 'source_url' => 'https://rival.example/pricing',
                'verified_on' => null, 'published' => true, 'values' => ['₦500', 'Extra'],
            ]],
        ];

        $this->actingAs($this->operator)->putJson('/api/v1/platform/comparison', $doc)
            ->assertStatus(422)->assertJsonValidationErrors('competitors.0.verified_on');

        $doc['competitors'][0]['verified_on'] = now()->toDateString();
        $this->actingAs($this->operator)->putJson('/api/v1/platform/comparison', $doc)->assertOk();

        $public = $this->getJson('/api/v1/public/comparison')->assertOk();
        $this->assertSame('Rival', $public->json('data.competitors.0.name'));
        $this->assertSame(['₦500', 'Extra'], $public->json('data.competitors.0.values'));
        $this->assertArrayNotHasKey('source_url', $public->json('data.competitors.0'));

        $doc['competitors'][0]['published'] = false;
        $this->actingAs($this->operator)->putJson('/api/v1/platform/comparison', $doc)->assertOk();
        $this->getJson('/api/v1/public/comparison')->assertJson(['data' => null]);
    }

    public function test_each_competitor_needs_one_value_per_row(): void
    {
        $this->actingAs($this->operator)->putJson('/api/v1/platform/comparison', [
            'rows' => [['label' => 'CBT', 'schoolpilot' => 'Included']],
            'competitors' => [['name' => 'Rival', 'published' => false, 'values' => []]],
        ])->assertStatus(422);
    }

    public function test_a_default_share_preview_can_be_uploaded_at_the_right_size(): void
    {
        $this->actingAs($this->operator)
            ->postJson('/api/v1/platform/website/screens/social-preview', ['image' => UploadedFile::fake()->image('og.png', 1000, 600)])
            ->assertStatus(422)->assertJsonValidationErrors('image');

        $this->actingAs($this->operator)
            ->postJson('/api/v1/platform/website/screens/social-preview', ['image' => UploadedFile::fake()->image('og.png', 1200, 630)])
            ->assertCreated();

        $this->assertNotNull($this->getJson('/api/v1/public/site-config')->json('screens.social-preview'));
    }

    public function test_search_console_tokens_are_saved_and_only_clean_tokens_are_published(): void
    {
        $this->actingAs($this->operator)->putJson('/api/v1/platform/settings', [
            'values' => [
                'services.marketing.site.google_site_verification' => 'AbC123_-xyzAbC123_-xyz',
                'services.marketing.site.bing_site_verification' => '"><script>alert(1)</script>',
            ],
        ])->assertOk();

        $this->getJson('/api/v1/public/site-config')->assertOk()->assertJson([
            'google_site_verification' => 'AbC123_-xyzAbC123_-xyz',
            'bing_site_verification' => null,
        ]);
    }
}
