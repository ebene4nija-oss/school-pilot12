<?php

namespace Tests\Feature;

use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The demo school has to keep seeding as the schema moves, and the accounts it
 * prints have to actually sign in — a demo that fails at the login screen is
 * worse than no demo.
 */
class DemoSchoolSeederTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email): string
    {
        $token = $this->postJson('http://greenfield.localhost/api/v1/auth/login', [
            'email' => $email,
            'password' => 'password',
            'subdomain' => 'greenfield',
        ])->assertStatus(200)->json('token');

        // Otherwise the guard keeps this account for the next request.
        $this->app['auth']->forgetGuards();

        return $token;
    }

    public function test_seeds_a_complete_school_and_every_demo_account_signs_in(): void
    {
        $this->seed(DemoSchoolSeeder::class);

        $schoolId = DB::table('schools')->where('subdomain', 'greenfield')->value('id');
        $this->assertNotNull($schoolId);
        $this->assertSame(96, DB::table('students')->where('school_id', $schoolId)->count());
        $this->assertSame(96, DB::table('invoices')->where('school_id', $schoolId)->count());
        $this->assertGreaterThan(0, DB::table('score_entries')->where('ai_comment_status', 'pending_approval')->count());

        foreach (['admin', 'bursar', 'nurse', 'teacher', 'parent', 'student'] as $handle) {
            $this->login("{$handle}@greenfield.test");
        }

        $this->withToken($this->login('parent@greenfield.test'))
            ->getJson('http://greenfield.localhost/api/v1/parent/children')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withToken($this->login('admin@greenfield.test'))
            ->getJson('http://greenfield.localhost/api/v1/students')
            ->assertOk();
    }

    public function test_running_it_again_replaces_the_school_rather_than_duplicating_it(): void
    {
        $this->seed(DemoSchoolSeeder::class);
        $users = DB::table('users')->count();

        $this->seed(DemoSchoolSeeder::class);

        $this->assertSame(1, DB::table('schools')->where('subdomain', 'greenfield')->count());
        $this->assertSame($users, DB::table('users')->count());
    }
}
