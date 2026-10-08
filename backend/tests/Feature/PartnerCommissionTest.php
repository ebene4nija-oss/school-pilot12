<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\School;
use App\Models\Term;
use App\Models\TermBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Partners earn a lifetime commission on every term bill their schools pay:
 * 30% in public, or a privately negotiated rate frozen onto each commission.
 */
class PartnerCommissionTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Term $term;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield']);
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-07', 'end_date' => '2027-07-16']);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-07', 'end_date' => '2026-12-11', 'is_current' => true]);

        $this->operator = User::create(['name' => 'Ops', 'email' => 'ops@schoolpilot.test', 'password' => bcrypt('x')]);
        $this->operator->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);
    }

    private function partner(array $attrs = []): Partner
    {
        $partner = Partner::create($attrs + [
            'code' => Partner::newCode(),
            'name' => 'Ada Partner',
            'email' => 'ada'.uniqid().'@partner.test',
            'status' => 'active',
        ]);
        $this->school->update(['partner_id' => $partner->id]);

        return $partner;
    }

    private function bill(int $amountKobo = 1_000_000): TermBill
    {
        return TermBill::create([
            'school_id' => $this->school->id, 'term_id' => $this->term->id, 'plan' => 'results',
            'active_students' => 10, 'rate_kobo' => 50000, 'amount_kobo' => $amountKobo, 'status' => 'issued',
        ]);
    }

    private function setStatus(TermBill $bill, string $status, ?string $note = null)
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/platform/bills/{$bill->id}/status", ['status' => $status, 'note' => $note])
            ->assertOk();
    }

    public function test_a_paid_bill_earns_the_public_thirty_percent(): void
    {
        $partner = $this->partner();
        $bill = $this->bill(1_000_000); // ₦10,000

        $this->setStatus($bill, 'paid');

        $commission = PartnerCommission::where('partner_id', $partner->id)->sole();
        $this->assertSame(300_000, $commission->amount_kobo);
        $this->assertSame(3000, $commission->rate_bps);
        $this->assertSame('accrued', $commission->status);
    }

    public function test_a_negotiated_rate_applies_and_is_frozen_on_the_commission(): void
    {
        $partner = $this->partner(['commission_rate_override' => 4000]);
        $first = $this->bill(1_000_000);
        $this->setStatus($first, 'paid');

        $this->assertSame(400_000, PartnerCommission::where('term_bill_id', $first->id)->value('amount_kobo'));

        // Raising the rate later does not rewrite the earlier commission.
        $partner->update(['commission_rate_override' => 5000]);
        $this->assertSame(400_000, PartnerCommission::where('term_bill_id', $first->id)->value('amount_kobo'));
        $this->assertSame(4000, PartnerCommission::where('term_bill_id', $first->id)->value('rate_bps'));
    }

    public function test_marking_paid_twice_does_not_pay_out_twice(): void
    {
        $this->partner();
        $bill = $this->bill();

        $this->setStatus($bill, 'paid');
        $this->setStatus($bill, 'paid');

        $this->assertSame(1, PartnerCommission::count());
    }

    public function test_waived_and_reopened_bills_earn_nothing(): void
    {
        $this->partner();
        $waived = $this->bill();
        $this->setStatus($waived, 'waived', 'Pilot school');

        $this->assertSame(0, PartnerCommission::count());

        $this->setStatus($waived, 'paid');
        $this->assertSame('accrued', PartnerCommission::sole()->status);

        $this->setStatus($waived, 'issued');
        $this->assertSame('void', PartnerCommission::sole()->status);

        // Paid again after the reopening: the same row comes back to life.
        $this->setStatus($waived, 'paid');
        $this->assertSame(1, PartnerCommission::count());
        $this->assertSame('accrued', PartnerCommission::sole()->status);
    }

    public function test_a_school_without_a_partner_or_with_an_inactive_one_earns_nobody_anything(): void
    {
        $bill = $this->bill();
        $this->setStatus($bill, 'paid');
        $this->assertSame(0, PartnerCommission::count());

        $this->partner(['status' => 'suspended']);
        $this->setStatus($bill, 'issued');
        $this->setStatus($bill, 'paid');
        $this->assertSame(0, PartnerCommission::count());
    }

    public function test_the_negotiated_rate_is_hidden_from_serialisation(): void
    {
        $partner = $this->partner(['commission_rate_override' => 4500]);
        $this->setStatus($this->bill(), 'paid');

        $this->assertArrayNotHasKey('commission_rate_override', $partner->fresh()->toArray());
        $this->assertArrayNotHasKey('rate_bps', PartnerCommission::sole()->toArray());
    }
}
