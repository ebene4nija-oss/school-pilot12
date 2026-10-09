<?php

namespace Tests\Feature;

use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mobile app talks to the platform domain and names its school in
 * X-School-Subdomain. The apex used to be read as a school called
 * "schoolpilot" and refused before the header was consulted, so every school
 * code typed into the app came back "not found".
 */
class MobileTenantHeaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.marketing.tenant_domain' => 'schoolpilot.org.ng']);
        School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield']);
    }

    public function test_the_app_finds_a_school_by_code_on_the_platform_domain(): void
    {
        $this->withHeader('X-School-Subdomain', 'greenfield')
            ->getJson('https://schoolpilot.org.ng/api/v1/health')
            ->assertOk();

        $this->withHeader('X-School-Subdomain', 'greenfield')
            ->getJson('https://www.schoolpilot.org.ng/api/v1/health')
            ->assertOk();
    }

    public function test_an_unknown_code_is_still_not_found(): void
    {
        // A typo must not pass the app's school-code check.
        $this->withHeader('X-School-Subdomain', 'greenfeild')
            ->getJson('https://schoolpilot.org.ng/api/v1/health')
            ->assertNotFound()
            ->assertJsonPath('message', 'School tenant subdomain not found.');

        // Codes are matched case-insensitively, as typed on a phone keyboard.
        $this->withHeader('X-School-Subdomain', 'Greenfield ')
            ->getJson('https://schoolpilot.org.ng/api/v1/health')
            ->assertOk();
    }

    public function test_a_school_subdomain_still_wins_over_the_header(): void
    {
        School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);

        $this->withHeader('X-School-Subdomain', 'other')
            ->getJson('https://greenfield.schoolpilot.org.ng/api/v1/health')
            ->assertOk();

        $this->getJson('https://nosuchschool.schoolpilot.org.ng/api/v1/health')->assertNotFound();
    }
}
