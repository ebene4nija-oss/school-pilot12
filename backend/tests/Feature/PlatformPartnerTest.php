<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\School;
use App\Models\Term;
use App\Models\TermBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The operator console's partner programme: private rates, crediting, payouts. */
class PlatformPartnerTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private School $school;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::create(['name' => 'Ops', 'email' => 'ops@schoolpilot.test', 'password' => bcrypt('x')]);
        $this->operator->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);

        $this->school = School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield']);
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-07', 'end_date' => '2027-07-16']);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-07', 'end_date' => '2026-12-11', 'is_current' => true]);
    }

    private function api()
    {
        return $this->actingAs($this->operator, 'sanctum');
    }

    private function paidBill(int $amountKobo, int $termId): void
    {
        $bill = TermBill::create([
            'school_id' => $this->school->id, 'term_id' => $termId, 'plan' => 'results',
            'active_students' => 1, 'rate_kobo' => $amountKobo, 'amount_kobo' => $amountKobo, 'status' => 'issued',
        ]);
        $this->api()->postJson("/api/v1/platform/bills/{$bill->id}/status", ['status' => 'paid'])->assertOk();
    }

    public function test_only_operators_reach_the_partner_console(): void
    {
        $head = User::create(['name' => 'Head', 'email' => 'head@gf.test', 'password' => bcrypt('x')]);
        $head->userProfile()->create(['school_id' => $this->school->id, 'role' => 'school_admin']);

        $this->actingAs($head, 'sanctum')->getJson('/api/v1/platform/partners')->assertForbidden();
        $this->api()->getJson('/api/v1/platform/partners')->assertOk()->assertJsonPath('default_rate_percent', 30);
    }

    public function test_an_operator_adds_a_partner_at_the_public_rate_or_a_private_one(): void
    {
        $plain = $this->api()->postJson('/api/v1/platform/partners', ['name' => 'Ada', 'email' => 'ada@p.test'])->assertCreated();
        $this->assertSame(30.0, (float) $plain->json('data.rate_percent'));
        $this->assertFalse($plain->json('data.negotiated'));

        $deal = $this->api()->postJson('/api/v1/platform/partners', ['name' => 'Bola', 'email' => 'bola@p.test', 'commission_rate_percent' => 40])->assertCreated();
        $this->assertSame(40.0, (float) $deal->json('data.rate_percent'));
        $this->assertTrue($deal->json('data.negotiated'));
        $this->assertSame(4000, Partner::where('email', 'bola@p.test')->value('commission_rate_override'));
    }

    public function test_changing_a_rate_is_audited_and_clearing_it_restores_the_public_default(): void
    {
        $partner = Partner::create(['code' => Partner::newCode(), 'name' => 'Ada', 'email' => 'ada@p.test', 'status' => 'active']);

        $this->api()->putJson("/api/v1/platform/partners/{$partner->id}", ['commission_rate_percent' => 35])->assertOk();
        $this->assertSame(3500, $partner->fresh()->makeVisible('commission_rate_override')->commission_rate_override);

        $log = AuditLog::where('action', 'partner.updated')->latest('id')->first();
        $this->assertSame(3500, $log->new_values['commission_rate_override']);

        $this->api()->putJson("/api/v1/platform/partners/{$partner->id}", ['commission_rate_percent' => null])->assertOk()
            ->assertJsonPath('data.rate_percent', 30);
    }

    public function test_crediting_a_school_makes_its_paid_bills_earn_for_the_partner(): void
    {
        $partner = Partner::create(['code' => Partner::newCode(), 'name' => 'Ada', 'email' => 'ada@p.test', 'status' => 'active']);

        $this->api()->putJson("/api/v1/platform/schools/{$this->school->id}/partner", ['partner_id' => $partner->id])->assertOk();
        $this->paidBill(1_000_000, $this->term->id);

        $this->assertSame(300_000, PartnerCommission::sole()->amount_kobo);
        $this->api()->getJson('/api/v1/platform/partners')->assertJsonPath('data.0.owed_naira', 3000)->assertJsonPath('data.0.schools', 1);
    }

    public function test_a_payout_settles_what_is_owed_exactly_once(): void
    {
        $partner = Partner::create(['code' => Partner::newCode(), 'name' => 'Ada', 'email' => 'ada@p.test', 'status' => 'active']);
        $this->school->update(['partner_id' => $partner->id]);
        $this->paidBill(1_000_000, $this->term->id);

        $this->api()->postJson("/api/v1/platform/partners/{$partner->id}/payouts", ['paid_on' => '2026-10-09', 'reference' => 'TRF-1'])->assertCreated();

        $this->assertSame('paid', PartnerCommission::sole()->status);
        $this->assertSame(300_000, (int) $partner->payouts()->sole()->amount_kobo);

        // Nothing left, so a second click cannot pay the same money again.
        $this->api()->postJson("/api/v1/platform/partners/{$partner->id}/payouts", ['paid_on' => '2026-10-09'])->assertStatus(422);
        $this->assertSame(1, $partner->payouts()->count());
    }

    public function test_a_lead_can_be_credited_to_a_partner(): void
    {
        $partner = Partner::create(['code' => Partner::newCode(), 'name' => 'Ada', 'email' => 'ada@p.test', 'status' => 'active']);
        $lead = \App\Models\MarketingLead::create([
            'intent' => 'demo', 'full_name' => 'Head Teacher', 'phone' => '+2348031234567', 'school_name' => 'X School',
            'role' => 'principal', 'status' => 'new', 'score' => 10, 'consent_at' => now(),
        ]);

        $this->api()->putJson("/api/v1/platform/leads/{$lead->id}/partner", ['partner_id' => $partner->id])->assertOk();

        $this->assertSame($partner->id, $lead->fresh()->partner_id);
    }
}
