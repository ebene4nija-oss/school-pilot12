<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The marketing site's console-managed content: contacts, switches and
 * screenshots an operator sets, and the public config the site's build reads.
 */
class WebsiteSettingsTest extends TestCase
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

    public function test_site_config_is_empty_until_the_console_sets_it(): void
    {
        $this->getJson('/api/v1/public/site-config')
            ->assertOk()
            ->assertJson([
                'whatsapp_number' => null,
                'response_minutes' => 0,
                'demo_school_url' => null,
                'trust_show_ai' => false,
                'legal_reviewed' => false,
                'screens' => ['admin-dashboard' => null, 'cbt-exam' => null],
            ]);
    }

    public function test_website_settings_saved_in_the_console_reach_the_public_config(): void
    {
        $this->actingAs($this->operator)->putJson('/api/v1/platform/settings', [
            'values' => [
                'services.marketing.site.whatsapp_number' => '0803 123 4567',
                'services.marketing.site.response_minutes' => '15',
                'services.marketing.site.demo_school_url' => 'https://demo.schoolpilot.org.ng',
                'services.marketing.site.contact_email' => 'hello@schoolpilot.org.ng',
                'services.marketing.site.legal_reviewed' => true,
            ],
        ])->assertOk();

        $this->getJson('/api/v1/public/site-config')
            ->assertOk()
            ->assertJson([
                'whatsapp_number' => '2348031234567',
                'response_minutes' => 15,
                'demo_school_url' => 'https://demo.schoolpilot.org.ng',
                'contact_email' => 'hello@schoolpilot.org.ng',
                'legal_reviewed' => true,
                'trust_show_ai' => false,
            ]);
    }

    public function test_website_settings_are_validated(): void
    {
        $this->actingAs($this->operator)->putJson('/api/v1/platform/settings', [
            'values' => [
                'services.marketing.site.whatsapp_number' => '12345',
                'services.marketing.site.demo_school_url' => 'http://not-https.example',
                'services.marketing.site.response_minutes' => '5000',
            ],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'values.services.marketing.site.whatsapp_number',
            'values.services.marketing.site.demo_school_url',
            'values.services.marketing.site.response_minutes',
        ]);
    }

    public function test_an_operator_fills_replaces_and_empties_a_screenshot_slot(): void
    {
        $this->actingAs($this->operator)
            ->post('/api/v1/platform/website/screens/admin-dashboard', ['image' => UploadedFile::fake()->image('dash.png', 1280, 800)])
            ->assertCreated();

        $first = $this->getJson('/api/v1/public/site-config')->json('screens.admin-dashboard');
        $this->assertNotNull($first);

        $this->actingAs($this->operator)
            ->post('/api/v1/platform/website/screens/admin-dashboard', ['image' => UploadedFile::fake()->image('dash2.png', 1280, 800)])
            ->assertCreated();

        $second = $this->getJson('/api/v1/public/site-config')->json('screens.admin-dashboard');
        $this->assertNotSame($first, $second);
        $this->assertCount(1, Storage::disk('public')->files('website/screens'));

        $this->actingAs($this->operator)->deleteJson('/api/v1/platform/website/screens/admin-dashboard')->assertOk();
        $this->assertNull($this->getJson('/api/v1/public/site-config')->json('screens.admin-dashboard'));
        $this->assertTrue(AuditLog::where('action', 'website.screenshot.uploaded')->exists());
    }

    public function test_screenshots_must_be_images_of_a_usable_size_in_a_known_slot(): void
    {
        $this->actingAs($this->operator)
            ->post('/api/v1/platform/website/screens/admin-dashboard', ['image' => UploadedFile::fake()->image('tiny.png', 300, 200)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('image');

        $this->actingAs($this->operator)
            ->post('/api/v1/platform/website/screens/parent-app-phone', ['image' => UploadedFile::fake()->image('wide.png', 1280, 800)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('image');

        $this->actingAs($this->operator)
            ->post('/api/v1/platform/website/screens/admin-dashboard', ['image' => UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->actingAs($this->operator)
            ->post('/api/v1/platform/website/screens/not-a-slot', ['image' => UploadedFile::fake()->image('x.png', 1280, 800)], ['Accept' => 'application/json'])
            ->assertNotFound();

        $this->assertCount(0, Storage::disk('public')->files('website/screens'));
    }

    public function test_only_operators_manage_the_website(): void
    {
        $school = School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield', 'email' => 'a@g.test', 'status' => 'active']);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@greenfield.test', 'password' => bcrypt('password')]);
        $admin->userProfile()->create(['school_id' => $school->id, 'role' => 'school_admin']);

        $this->actingAs($admin)->getJson('/api/v1/platform/website/screens')->assertForbidden();
        $this->actingAs($admin)
            ->post('/api/v1/platform/website/screens/admin-dashboard', ['image' => UploadedFile::fake()->image('d.png', 1280, 800)], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
