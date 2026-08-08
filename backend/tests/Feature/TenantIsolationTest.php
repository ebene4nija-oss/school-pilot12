<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_query_cannot_return_another_schools_data()
    {
        // 1. Create School A
        $schoolA = School::create([
            'name' => 'School A',
            'slug' => 'school-a',
            'subdomain' => 'schoola',
        ]);

        $userA = User::factory()->create();
        UserProfile::create([
            'school_id' => $schoolA->id,
            'user_id' => $userA->id,
            'role' => 'school_admin',
        ]);

        $studentA = Student::create([
            'school_id' => $schoolA->id,
            'user_id' => $userA->id,
            'blood_group' => 'O+',
            'allergies' => ['Peanuts'],
        ]);

        // 2. Create School B
        $schoolB = School::create([
            'name' => 'School B',
            'slug' => 'school-b',
            'subdomain' => 'schoolb',
        ]);

        $userB = User::factory()->create();
        UserProfile::create([
            'school_id' => $schoolB->id,
            'user_id' => $userB->id,
            'role' => 'school_admin',
        ]);

        $studentB = Student::create([
            'school_id' => $schoolB->id,
            'user_id' => $userB->id,
            'blood_group' => 'A+',
            'allergies' => ['Dust'],
        ]);

        // 3. Act as User A (School A Admin)
        $this->actingAs($userA);

        $students = Student::all();

        // 4. Assert School A only sees Student A, not Student B
        $this->assertCount(1, $students);
        $this->assertEquals($studentA->id, $students->first()->id);
        $this->assertNotEquals($studentB->id, $students->first()->id);
    }

    /**
     * Tenant resolution actually runs under test.
     *
     * Ten test files used to pass the host as
     * `withServerVariables(['HTTP_HOST' => ...])`, which Laravel discards:
     * `prepareUrlForRequest` builds the URL from the UrlGenerator root and
     * Symfony then overwrites HTTP_HOST from it. So the middleware's
     * 404-on-unknown-subdomain branch had never executed, and tests named for
     * tenant scoping were really exercising the global scope instead.
     *
     * This pins the mechanism down so the bypass cannot quietly return.
     */
    public function test_unknown_subdomain_is_rejected_by_tenant_resolution()
    {
        School::create(['name' => 'Real School', 'slug' => 'real', 'subdomain' => 'real']);

        $this->getJson('http://nosuchschool.localhost/api/v1/health')->assertStatus(404);
        $this->getJson('http://real.localhost/api/v1/health')->assertStatus(200);
    }

    /**
     * `config(['app.url' => ...])` set inside a test does *not* change the
     * host — the UrlGenerator takes its root at boot. Only `forceRootUrl`
     * works, which is what the converted test files use.
     */
    public function test_force_root_url_is_what_drives_relative_uris()
    {
        School::create(['name' => 'Real School', 'slug' => 'real', 'subdomain' => 'real']);

        config(['app.url' => 'http://nosuchschool.localhost']);
        $this->getJson('/api/v1/health')->assertStatus(200); // config alone: no effect

        \Illuminate\Support\Facades\URL::forceRootUrl('http://nosuchschool.localhost');
        $this->getJson('/api/v1/health')->assertStatus(404); // now the host really applies
    }
}
