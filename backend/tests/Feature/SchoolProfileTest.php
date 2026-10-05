<?php

namespace Tests\Feature;

use App\Models\AdmissionSetting;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * School public portal & branded login: the subdomain's front door, and the
 * admin screen that fills it.
 */
class SchoolProfileTest extends TestCase
{
    use RefreshDatabase;

    private string $host = 'http://greenfield.schoolpilot.test/api/v1';

    private School $school;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Greenfield International School', 'slug' => 'greenfield', 'subdomain' => 'greenfield',
            'email' => 'info@greenfield.test', 'phone' => '08031234567', 'address' => '12 Allen Avenue, Ikeja',
        ]);
        $this->admin = $this->person('Head', 'admin@gf.test', 'school_admin', $this->school->id);
        $this->teacher = $this->person('Mr Bello', 'bello@gf.test', 'teacher', $this->school->id);
    }

    private function person(string $name, string $email, string $role, ?int $schoolId): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $schoolId, 'role' => $role]);

        return $user;
    }

    private function publish(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/school/profile', [
            'motto' => 'Excellence in Learning, Character & Leadership',
            'about' => 'A co-educational school in Ikeja.',
            'state' => 'Lagos',
            'lga' => 'Ikeja',
            'published' => true,
        ])->assertOk();

        $this->app['auth']->forgetGuards();
    }

    public function test_an_unpublished_school_shows_its_identity_and_nothing_else(): void
    {
        $this->school->update(['motto' => 'Excellence', 'about' => 'Draft text']);

        $res = $this->getJson("{$this->host}/public/school-profile")
            ->assertOk()
            ->assertJsonPath('published', false)
            ->assertJsonPath('admissions_open', false)
            ->assertJsonPath('school.name', 'Greenfield International School')
            ->assertJsonPath('school.motto', 'Excellence');

        // Contact and about wait until the admin publishes.
        $this->assertSame(
            ['name', 'short_name', 'subdomain', 'motto', 'logo_url', 'hero_image_url', 'brand_color'],
            array_keys($res->json('school')),
        );
    }

    public function test_a_published_school_shows_contact_sections_and_admissions(): void
    {
        SchoolClass::create(['school_id' => $this->school->id, 'name' => 'Primary 1', 'level_category' => 'primary']);
        SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1', 'level_category' => 'junior_secondary']);
        AdmissionSetting::create(['school_id' => $this->school->id, 'public_form_enabled' => true, 'open_sections' => ['primary']]);

        $this->publish();

        $this->getJson("{$this->host}/public/school-profile")
            ->assertOk()
            ->assertJsonPath('published', true)
            ->assertJsonPath('admissions_open', true)
            ->assertJsonPath('school.state', 'Lagos')
            ->assertJsonPath('school.phone', '08031234567')
            ->assertJsonPath('school.sections', ['Primary', 'Junior Secondary (JSS)']);
    }

    public function test_the_public_profile_is_an_allowlist(): void
    {
        $this->publish();

        $school = $this->getJson("{$this->host}/public/school-profile")->assertOk()->json('school');

        $this->assertEqualsCanonicalizing([
            'name', 'short_name', 'subdomain', 'motto', 'logo_url', 'hero_image_url', 'brand_color',
            'about', 'address', 'state', 'lga', 'phone', 'email', 'website', 'social_links', 'school_type', 'sections',
        ], array_keys($school));

        foreach (['id', 'status', 'ai_api_settings', 'latitude', 'longitude', 'hero_image_confirmed_by', 'result_pin_retail_price'] as $private) {
            $this->assertArrayNotHasKey($private, $school);
        }
    }

    public function test_unknown_suspended_and_platform_schools_are_not_found(): void
    {
        $this->getJson('http://nowhere.schoolpilot.test/api/v1/public/school-profile')->assertNotFound();

        $this->school->update(['status' => 'suspended']);
        $this->getJson("{$this->host}/public/school-profile")->assertNotFound();

        School::create(['name' => 'SchoolPilot', 'slug' => 'admin', 'subdomain' => 'admin', 'is_platform' => true]);
        $this->getJson('http://admin.schoolpilot.test/api/v1/public/school-profile')->assertNotFound();
    }

    public function test_the_web_app_can_name_the_school_by_header(): void
    {
        $this->getJson('http://localhost/api/v1/public/school-profile', ['X-School-Subdomain' => 'greenfield'])
            ->assertOk()
            ->assertJsonPath('school.subdomain', 'greenfield');

        $this->getJson('http://localhost/api/v1/public/school-profile')->assertNotFound();
    }

    public function test_publishing_needs_enough_for_a_parent_to_use(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/profile', ['published' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('published');

        $this->assertFalse($this->school->fresh()->public_profile_published);

        $this->publish();

        $school = $this->school->fresh();
        $this->assertTrue($school->public_profile_published);
        $this->assertNotNull($school->public_profile_published_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'school.profile.updated', 'school_id' => $this->school->id]);
    }

    public function test_only_a_school_admin_edits_the_profile(): void
    {
        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/v1/school/profile')->assertForbidden();
        $this->actingAs($this->teacher, 'sanctum')->putJson('/api/v1/school/profile', ['motto' => 'x'])->assertForbidden();
        $this->assertNull($this->school->fresh()->motto);
    }

    public function test_the_name_is_not_the_school_admins_to_change(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/profile', ['name' => 'Something Else', 'motto' => 'Kept'])
            ->assertOk();

        $this->assertSame('Greenfield International School', $this->school->fresh()->name);
        $this->assertSame('Kept', $this->school->fresh()->motto);
    }

    public function test_a_brand_colour_must_carry_white_text(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/profile', ['brand_color' => '#FFE066'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('brand_color');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/profile', ['brand_color' => '#0B5D3B'])
            ->assertOk()
            ->assertJsonPath('profile.brand_color', '#0b5d3b');
    }

    public function test_social_links_must_point_at_their_own_network(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/profile', ['social_links' => ['facebook' => 'https://evil.example/greenfield']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('social_links.facebook');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/profile', ['social_links' => ['myspace' => 'https://myspace.com/gf']])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/profile', ['social_links' => ['facebook' => 'https://www.facebook.com/greenfield', 'x' => '']])
            ->assertOk()
            ->assertJsonPath('profile.social_links', ['facebook' => 'https://www.facebook.com/greenfield']);
    }

    public function test_a_hero_photo_needs_the_no_identifiable_pupils_confirmation(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/school/profile/images/hero', ['image' => UploadedFile::fake()->image('campus.jpg', 1600, 900)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('no_identifiable_pupils');

        $url = $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/school/profile/images/hero', [
                'image' => UploadedFile::fake()->image('campus.jpg', 1600, 900),
                'no_identifiable_pupils' => '1',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('profile.hero_image_url');

        $school = $this->school->fresh();
        $this->assertSame($url, $school->hero_image_url);
        $this->assertSame($this->admin->id, $school->hero_image_confirmed_by);
        $this->assertNotNull($school->hero_image_confirmed_at);
        $this->assertCount(1, Storage::disk('public')->files("schools/{$this->school->id}/brand"));

        $log = AuditLog::where('action', 'school.profile.hero_uploaded')->firstOrFail();
        $this->assertTrue($log->new_values['no_identifiable_pupils_confirmed']);
    }

    public function test_replacing_and_removing_an_image_cleans_up_the_old_file(): void
    {
        Storage::fake('public');

        $upload = fn () => $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/school/profile/images/logo', ['image' => UploadedFile::fake()->image('crest.png', 256, 256)], ['Accept' => 'application/json'])
            ->assertOk();

        $upload();
        $upload();
        $this->assertCount(1, Storage::disk('public')->files("schools/{$this->school->id}/brand"));

        $this->actingAs($this->admin, 'sanctum')->deleteJson('/api/v1/school/profile/images/logo')->assertOk();
        $this->assertCount(0, Storage::disk('public')->files("schools/{$this->school->id}/brand"));
        $this->assertNull($this->school->fresh()->logo_url);
    }

    public function test_images_are_raster_and_large_enough(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/school/profile/images/logo', [
                'image' => UploadedFile::fake()->createWithContent('crest.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/school/profile/images/hero', [
                'image' => UploadedFile::fake()->image('small.jpg', 400, 300),
                'no_identifiable_pupils' => '1',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->assertNull($this->school->fresh()->hero_image_url);
    }

    public function test_an_operator_set_logo_url_is_not_deleted_from_storage(): void
    {
        Storage::fake('public');
        $this->school->update(['logo_url' => 'https://cdn.example/greenfield.png']);

        $this->actingAs($this->admin, 'sanctum')->deleteJson('/api/v1/school/profile/images/logo')->assertOk();

        $this->assertNull($this->school->fresh()->logo_url);
    }
}
