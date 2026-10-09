<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Partner;
use App\Models\School;
use App\Models\Term;
use App\Models\TermBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The partner's own view, how they get in, and how tightly they are held there.
 * The negotiated rate must not be readable from anything a partner or the
 * public can reach.
 */
class PartnerPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private School $school;

    private School $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::create(['name' => 'Ops', 'email' => 'ops@schoolpilot.test', 'password' => bcrypt('x')]);
        $this->operator->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);

        $this->platform = School::create(['name' => 'SchoolPilot', 'slug' => 'admin', 'subdomain' => 'admin', 'is_platform' => true]);
        $this->school = School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield']);
    }

    /** @return array{0: Partner, 1: string} an approved partner and their temporary password */
    private function approvedPartner(array $extra = []): array
    {
        $res = $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/platform/partners', ['name' => 'Ada Partner', 'email' => 'ada@p.test'] + $extra)
            ->assertCreated();

        return [Partner::where('email', 'ada@p.test')->sole(), $res->json('login.temporary_password')];
    }

    private function signIn(string $password, string $subdomain = 'admin')
    {
        return $this->postJson('/api/v1/auth/login', ['email' => 'ada@p.test', 'password' => $password, 'subdomain' => $subdomain]);
    }

    private function payBill(int $amountKobo): void
    {
        $session = AcademicSession::firstOrCreate(['school_id' => $this->school->id, 'name' => '2026/2027'], ['start_date' => '2026-09-07', 'end_date' => '2027-07-16']);
        $term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'Term '.uniqid(), 'start_date' => '2026-09-07', 'end_date' => '2026-12-11']);
        $bill = TermBill::create([
            'school_id' => $this->school->id, 'term_id' => $term->id, 'plan' => 'results',
            'active_students' => 1, 'rate_kobo' => $amountKobo, 'amount_kobo' => $amountKobo, 'status' => 'issued',
        ]);
        $this->actingAs($this->operator, 'sanctum')->postJson("/api/v1/platform/bills/{$bill->id}/status", ['status' => 'paid'])->assertOk();
    }

    public function test_approving_a_partner_gives_them_a_one_time_login(): void
    {
        [$partner, $password] = $this->approvedPartner();

        $this->assertNotNull($partner->user_id);
        $this->assertSame('partner', User::find($partner->user_id)->userProfile->role);
        $this->signIn($password)->assertOk()->assertJsonPath('user.role', 'partner');
    }

    public function test_approving_a_partner_emails_a_setup_link_never_the_password(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        [, $password] = $this->approvedPartner();

        $this->assertNotEmpty($password);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\CredentialLinkMail::class, function ($mail) use ($password) {
            return $mail->hasTo('ada@p.test')
                && str_contains($mail->body, '/reset-password?')
                && ! str_contains($mail->body, $password);
        });
    }

    public function test_the_operator_is_told_when_the_email_could_not_be_sent(): void
    {
        \Illuminate\Support\Facades\Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $res = $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/platform/partners', ['name' => 'Ada Partner', 'email' => 'ada@p.test'])
            ->assertCreated();

        $this->assertFalse($res->json('login.email_sent'));
        $this->assertNotEmpty($res->json('login.temporary_password'));
        $this->assertNotNull(Partner::where('email', 'ada@p.test')->value('user_id'));
    }

    public function test_a_partner_signs_in_on_the_platform_host_but_not_on_a_schools(): void
    {
        [, $password] = $this->approvedPartner();

        $this->signIn($password, 'greenfield')->assertForbidden();
    }

    public function test_a_pending_application_has_no_login_until_approved(): void
    {
        $this->postJson('/api/v1/public/partners/apply', [
            'name' => 'Bola', 'email' => 'bola@p.test', 'phone' => '08031234567', 'consent' => true,
        ])->assertCreated();

        $partner = Partner::where('email', 'bola@p.test')->sole();
        $this->assertSame('pending', $partner->status);
        $this->assertNull($partner->user_id);

        $res = $this->actingAs($this->operator, 'sanctum')->putJson("/api/v1/platform/partners/{$partner->id}", ['status' => 'active'])->assertOk();
        $this->assertNotEmpty($res->json('login.temporary_password'));

        // Approving again does not mint a second login.
        $again = $this->actingAs($this->operator, 'sanctum')->putJson("/api/v1/platform/partners/{$partner->id}", ['status' => 'active'])->assertOk();
        $this->assertNull($again->json('login'));
    }

    public function test_applying_with_a_known_email_looks_the_same_and_changes_nothing(): void
    {
        $this->approvedPartner();

        $this->postJson('/api/v1/public/partners/apply', [
            'name' => 'Someone', 'email' => 'ada@p.test', 'phone' => '08031234567', 'consent' => true,
        ])->assertCreated()->assertJsonPath('received', true);

        $this->assertSame(1, Partner::count());
    }

    public function test_a_partner_is_held_to_their_own_portal_even_on_a_school_host(): void
    {
        [$partner] = $this->approvedPartner();
        $user = User::find($partner->user_id);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/partner/summary')->assertOk();
        $this->actingAs($user, 'sanctum')->withHeader('Host', 'greenfield.schoolpilot.test')->getJson('/api/v1/students')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/platform/partners')->assertForbidden();
    }

    public function test_the_portal_shows_earnings_but_never_the_rate_or_the_bill(): void
    {
        [$partner] = $this->approvedPartner(['commission_rate_percent' => 45]);
        $this->actingAs($this->operator, 'sanctum')->putJson("/api/v1/platform/schools/{$this->school->id}/partner", ['partner_id' => $partner->id])->assertOk();
        $this->payBill(1_000_000); // ₦10,000 at 45% = ₦4,500

        $user = User::find($partner->user_id);

        $summary = $this->actingAs($user, 'sanctum')->getJson('/api/v1/partner/summary')->assertOk();
        $this->assertSame(4500, (int) $summary->json('owed_naira'));
        $this->assertSame(1, $summary->json('schools'));
        $this->assertStringContainsString('?p='.$partner->code, $summary->json('referral_link'));

        $everything = '';
        foreach (['summary', 'schools', 'commissions', 'payouts', 'bank'] as $path) {
            $everything .= $this->actingAs($user, 'sanctum')->getJson("/api/v1/partner/{$path}")->assertOk()->getContent();
        }

        foreach (['rate', 'bps', 'commission_rate', 'override', '10000', '1000000'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $everything, "partner portal leaked '{$needle}'");
        }
    }

    public function test_a_partner_cannot_read_another_partners_earnings(): void
    {
        [$ada] = $this->approvedPartner();
        $this->actingAs($this->operator, 'sanctum')->postJson('/api/v1/platform/partners', ['name' => 'Bola', 'email' => 'bola@p.test'])->assertCreated();
        $this->actingAs($this->operator, 'sanctum')->putJson("/api/v1/platform/schools/{$this->school->id}/partner", ['partner_id' => $ada->id]);
        $this->payBill(1_000_000);

        $bola = User::where('email', 'bola@p.test')->sole();
        $summary = $this->actingAs($bola, 'sanctum')->getJson('/api/v1/partner/summary')->assertOk();

        $this->assertSame(0, (int) $summary->json('owed_naira'));
        $this->assertSame(0, $summary->json('schools'));
    }

    public function test_bank_details_are_saved_encrypted_and_read_back_masked(): void
    {
        [$partner] = $this->approvedPartner();
        $user = User::find($partner->user_id);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/partner/bank', [
            'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Ada Partner',
        ])->assertOk()->assertJsonPath('account_number_masked', '••••••6789');

        $this->assertNotSame('0123456789', \DB::table('partners')->where('id', $partner->id)->value('account_number'));
        $this->assertStringNotContainsString('0123456789', $this->actingAs($user, 'sanctum')->getJson('/api/v1/partner/bank')->getContent());

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/partner/bank', ['bank_name' => 'X', 'account_number' => '123', 'account_name' => 'A'])->assertStatus(422);
    }

    public function test_the_public_terms_state_only_the_published_rate(): void
    {
        $this->approvedPartner(['commission_rate_percent' => 45]);

        $res = $this->getJson('/api/v1/public/partners/terms')->assertOk();

        $this->assertSame(30, (int) $res->json('commission_percent'));
        $this->assertStringNotContainsString('45', $res->getContent());
    }

    public function test_a_lead_arriving_through_a_partner_link_is_credited_and_the_school_inherits_it(): void
    {
        [$partner] = $this->approvedPartner();

        $this->postJson('/api/v1/public/leads', [
            'intent' => 'demo', 'full_name' => 'Head Teacher', 'phone' => '08031234567', 'school_name' => 'New School',
            'role' => 'principal', 'student_band' => '51-200', 'consent' => true, 'partner_code' => $partner->code,
        ])->assertCreated();

        $lead = \App\Models\MarketingLead::sole();
        $this->assertSame($partner->id, $lead->partner_id);

        $this->actingAs($this->operator, 'sanctum')->postJson('/api/v1/platform/schools', [
            'name' => 'New School', 'subdomain' => 'newschool', 'email' => 'h@new.test',
            'admin_name' => 'Head', 'admin_email' => 'head@new.test', 'lead_id' => $lead->id,
        ])->assertCreated();

        $this->assertSame($partner->id, School::where('subdomain', 'newschool')->value('partner_id'));
    }

    public function test_an_unknown_or_inactive_partner_code_is_ignored(): void
    {
        $this->postJson('/api/v1/public/leads', [
            'intent' => 'demo', 'full_name' => 'Head Teacher', 'phone' => '08031234567', 'school_name' => 'New School',
            'role' => 'principal', 'student_band' => '51-200', 'consent' => true, 'partner_code' => 'PZZZZZZ',
        ])->assertCreated();

        $this->assertNull(\App\Models\MarketingLead::sole()->partner_id);
    }
}
