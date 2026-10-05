<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MarketingLead;
use App\Models\MarketingTouch;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The marketing site's endpoints (Landing PRD §14.2) and the console lead list.
 *
 * What matters most here: a lead's personal data is minimal, encrypted and
 * only ever shown to operators; the public endpoints cannot be used to list
 * SchoolPilot's customers; and abuse is cheap to refuse.
 */
class MarketingSiteTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $overrides = []): array
    {
        return array_merge([
            'intent' => 'demo',
            'full_name' => 'Adaeze Okafor',
            'phone' => '0803 123 4567',
            'school_name' => 'Bright Stars College',
            'role' => 'proprietor',
            'student_band' => '201-500',
            'sections' => ['primary', 'jss'],
            'consent' => true,
            'landing_page' => '/',
            'utm_source' => 'facebook',
            'pages' => ['/', '/pricing', '/features/cbt'],
        ], $overrides);
    }

    private function operator(): User
    {
        $user = User::create(['name' => 'Ops', 'email' => 'ops@schoolpilot.test', 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);

        return $user;
    }

    private function school(string $subdomain, string $status = 'active'): School
    {
        return School::create([
            'name' => ucfirst($subdomain).' School',
            'slug' => $subdomain,
            'subdomain' => $subdomain,
            'email' => "head@{$subdomain}.test",
            'status' => $status,
        ]);
    }

    public function test_a_demo_request_is_stored_with_normalised_phone_score_and_attribution(): void
    {
        $this->postJson('/api/v1/public/leads', $this->lead())
            ->assertCreated()
            ->assertJson(['received' => true, 'first_name' => 'Adaeze']);

        $lead = MarketingLead::sole();
        $this->assertSame('+2348031234567', $lead->phone);
        $this->assertSame('facebook', $lead->utm_source);
        $this->assertSame('new', $lead->status);
        // proprietor 3 + band 2 + pricing 2 + one feature page 1
        $this->assertSame(8, $lead->score);
        $this->assertTrue($lead->isHot());
        $this->assertNotNull($lead->consent_at);
    }

    public function test_personal_fields_are_encrypted_at_rest(): void
    {
        $this->postJson('/api/v1/public/leads', $this->lead(['email' => 'ada@brightstars.ng', 'message' => 'Call me']))
            ->assertCreated();

        $raw = DB::table('marketing_leads')->first();
        $this->assertStringNotContainsString('Adaeze', $raw->full_name);
        $this->assertStringNotContainsString('2348031234567', $raw->phone);
        $this->assertStringNotContainsString('ada@', $raw->email);
        $this->assertStringNotContainsString('Call me', $raw->message);
    }

    public function test_nigerian_phone_formats_are_accepted_and_others_refused(): void
    {
        // Each from its own address, so the per-IP limit is not what answers.
        $from = fn (int $n) => $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$n}"]);

        foreach (['08031234567', '+2348031234567', '2348031234567', '0809-123-4567'] as $n => $phone) {
            $from($n)->postJson('/api/v1/public/leads', $this->lead(['phone' => $phone]))->assertCreated();
        }

        $from(10)->postJson('/api/v1/public/leads', $this->lead(['phone' => '12345']))
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $from(11)->postJson('/api/v1/public/leads', $this->lead(['phone' => '+447700900123']))
            ->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_consent_is_required_and_never_assumed(): void
    {
        $this->postJson('/api/v1/public/leads', $this->lead(['consent' => false]))
            ->assertStatus(422)->assertJsonValidationErrors('consent');

        $payload = $this->lead();
        unset($payload['consent']);
        $this->postJson('/api/v1/public/leads', $payload)->assertStatus(422);

        $this->assertSame(0, MarketingLead::count());
    }

    public function test_required_fields_and_closed_sets_are_validated(): void
    {
        $this->postJson('/api/v1/public/leads', ['intent' => 'demo', 'consent' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['full_name', 'phone', 'school_name', 'role', 'student_band']);

        $this->postJson('/api/v1/public/leads', $this->lead(['role' => 'dean', 'student_band' => '7']))
            ->assertStatus(422)->assertJsonValidationErrors(['role', 'student_band']);
    }

    public function test_a_contact_message_does_not_need_a_student_band(): void
    {
        $this->postJson('/api/v1/public/leads', $this->lead(['intent' => 'contact', 'student_band' => null]))
            ->assertCreated();
    }

    public function test_the_honeypot_looks_successful_but_keeps_nothing(): void
    {
        $this->postJson('/api/v1/public/leads', $this->lead(['website' => 'http://spam.example']))
            ->assertCreated();

        $this->assertSame(0, MarketingLead::count());
    }

    public function test_turnstile_is_enforced_when_configured(): void
    {
        config(['services.marketing.turnstile_secret' => 'secret']);
        Http::fake(fn ($request) => Http::response(['success' => $request['response'] === 'good']));

        $this->postJson('/api/v1/public/leads', $this->lead(['turnstile_token' => 'bad']))
            ->assertStatus(422)->assertJsonValidationErrors('turnstile');
        $this->postJson('/api/v1/public/leads', $this->lead())
            ->assertStatus(422);
        $this->postJson('/api/v1/public/leads', $this->lead(['turnstile_token' => 'good']))->assertCreated();

        $this->assertSame(1, MarketingLead::count());
    }

    public function test_lead_submissions_are_throttled_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/public/leads', $this->lead())->assertCreated();
        }

        $this->postJson('/api/v1/public/leads', $this->lead())->assertStatus(429);
    }

    public function test_the_apex_domain_host_is_not_treated_as_a_school(): void
    {
        $this->postJson('http://schoolpilot.org.ng/api/v1/public/leads', $this->lead())->assertCreated();
        $this->getJson('http://schoolpilot.org.ng/api/v1/public/stats')->assertOk();
    }

    public function test_a_touch_records_attribution_and_refuses_personal_data(): void
    {
        $this->postJson('/api/v1/public/leads/touch', [
            'ref_code' => 'SP-7KQ2',
            'cta' => 'whatsapp',
            'landing_page' => '/pricing',
            'utm_campaign' => 'result-management',
        ])->assertCreated();

        $this->assertSame('result-management', MarketingTouch::sole()->utm_campaign);

        $this->postJson('/api/v1/public/leads/touch', ['ref_code' => 'SP-8ABC', 'phone' => '08031234567'])
            ->assertStatus(422);
        $this->postJson('/api/v1/public/leads/touch', ['ref_code' => 'not-a-code'])
            ->assertStatus(422);

        $this->assertSame(1, MarketingTouch::count());
    }

    public function test_school_resolve_answers_exact_addresses_only(): void
    {
        $this->school('greenfield');
        $this->school('closedschool', 'suspended');

        $this->getJson('/api/v1/public/schools/resolve?slug=greenfield')
            ->assertOk()
            ->assertExactJson(['exists' => true, 'login_url' => 'https://greenfield.schoolpilot.org.ng/login']);

        // No partial matches, no suggestions, no suspended schools.
        foreach (['green', 'greenfields', 'closedschool'] as $slug) {
            $this->getJson("/api/v1/public/schools/resolve?slug={$slug}")
                ->assertOk()
                ->assertExactJson(['exists' => false, 'login_url' => null]);
        }

        $this->getJson('/api/v1/public/schools/resolve?slug=%25')->assertStatus(422);
    }

    public function test_stats_are_hidden_below_their_thresholds(): void
    {
        $this->school('greenfield');
        Cache::flush();

        $this->getJson('/api/v1/public/stats')
            ->assertOk()
            ->assertExactJson(['schools' => null, 'students' => null, 'teachers' => null]);

        config(['services.marketing.stats_thresholds.schools' => 1]);
        Cache::flush();

        $this->getJson('/api/v1/public/stats')->assertJson(['schools' => 1, 'students' => null]);
    }

    public function test_operators_see_and_work_leads_and_school_admins_cannot(): void
    {
        $this->postJson('/api/v1/public/leads', $this->lead())->assertCreated();
        $lead = MarketingLead::sole();
        $operator = $this->operator();

        $this->actingAs($operator)->getJson('/api/v1/platform/leads')
            ->assertOk()
            ->assertJsonPath('data.0.phone', '+2348031234567')
            ->assertJsonPath('data.0.hot', true)
            ->assertJsonPath('counts.new', 1);

        $this->actingAs($operator)->putJson("/api/v1/platform/leads/{$lead->id}", ['status' => 'lost'])
            ->assertStatus(422)->assertJsonValidationErrors('lost_reason');

        $this->actingAs($operator)->putJson("/api/v1/platform/leads/{$lead->id}", [
            'status' => 'contacted',
            'owner_id' => $operator->id,
        ])->assertOk()->assertJsonPath('data.status', 'contacted');

        $this->assertNotNull($lead->fresh()->last_contact_at);
        $this->assertTrue(AuditLog::where('action', 'marketing_lead.updated')->exists());

        $school = $this->school('greenfield');
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@greenfield.test', 'password' => bcrypt('password')]);
        $admin->userProfile()->create(['school_id' => $school->id, 'role' => 'school_admin']);

        $this->actingAs($admin)->getJson('/api/v1/platform/leads')->assertForbidden();
    }

    public function test_a_whatsapp_ref_code_joins_the_chat_to_its_web_visit(): void
    {
        $this->postJson('/api/v1/public/leads/touch', [
            'ref_code' => 'SP-7KQ2', 'landing_page' => '/migrate', 'utm_source' => 'google', 'pages' => ['/migrate'],
        ])->assertCreated();
        $this->postJson('/api/v1/public/leads', $this->lead(['utm_source' => null, 'landing_page' => null, 'pages' => []]))
            ->assertCreated();

        $lead = MarketingLead::sole();
        $operator = $this->operator();

        $this->actingAs($operator)->postJson("/api/v1/platform/leads/{$lead->id}/ref", ['ref_code' => 'SP-NOPE'])
            ->assertStatus(422);

        $this->actingAs($operator)->postJson("/api/v1/platform/leads/{$lead->id}/ref", ['ref_code' => 'SP-7KQ2'])
            ->assertOk()
            ->assertJsonPath('data.ref_code', 'SP-7KQ2')
            ->assertJsonPath('data.utm_source', 'google')
            ->assertJsonPath('data.landing_page', '/migrate');
    }

    public function test_unconverted_leads_are_pruned_after_twelve_months(): void
    {
        $this->postJson('/api/v1/public/leads', $this->lead())->assertCreated();
        $this->postJson('/api/v1/public/leads', $this->lead(['full_name' => 'Kept Live']))->assertCreated();

        [$old, $live] = MarketingLead::orderBy('id')->get()->all();
        $old->forceFill(['created_at' => now()->subMonths(13)])->save();
        $live->forceFill(['created_at' => now()->subMonths(13), 'status' => 'live'])->save();

        $this->artisan('marketing:prune-leads')->assertSuccessful();

        $this->assertSame([$live->id], MarketingLead::pluck('id')->all());
    }
}
