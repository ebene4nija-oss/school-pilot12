<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\HostelEnrollment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Term;
use App\Models\TermBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What schools pay SchoolPilot: ₦500 (Result Management) or ₦300 after a
 * 30-day trial (Subscription) per active student per term, and ₦1,000 per
 * boarder on either plan.
 */
class PlatformBillingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Term $term;

    private User $operator;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 10:00');

        $this->school = School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield']);
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-07', 'end_date' => '2027-07-16']);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-07', 'end_date' => '2026-12-11', 'is_current' => true]);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);

        // Eight enrolled this term, one of them withdrawn in October (still
        // counts), plus one who left before the term began (does not).
        for ($i = 1; $i <= 9; $i++) {
            $user = User::create(['name' => "Pupil {$i}", 'email' => "p{$i}@gf.test", 'password' => bcrypt('x')]);
            $student = Student::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'class_id' => $class->id, 'gender' => 'female']);
            StudentEnrollment::create([
                'school_id' => $this->school->id, 'student_id' => $student->id, 'session_id' => $session->id, 'class_id' => $class->id,
                'status' => $i >= 8 ? 'closed' : 'active',
                'enrolled_on' => '2026-09-07',
                'closed_on' => match ($i) { 8 => '2026-10-02', 9 => '2026-09-01', default => null },
            ]);

            // Two full boarders and one on day services (not boarding).
            if ($i <= 3) {
                HostelEnrollment::create([
                    'school_id' => $this->school->id, 'student_id' => $student->id, 'session_id' => $session->id,
                    'boarding_category' => $i === 3 ? 'day_services' : 'full', 'status' => 'active', 'starts_on' => '2026-09-07',
                ]);
            }
        }

        $this->operator = User::create(['name' => 'Ops', 'email' => 'ops@schoolpilot.test', 'password' => bcrypt('x')]);
        $this->operator->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);
        $this->admin = User::create(['name' => 'Head', 'email' => 'head@gf.test', 'password' => bcrypt('x')]);
        $this->admin->userProfile()->create(['school_id' => $this->school->id, 'role' => 'school_admin']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function row(): array
    {
        $res = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/billing')->assertOk();

        return collect($res->json('data'))->firstWhere('school_id', $this->school->id);
    }

    private function plan(?string $plan)
    {
        return $this->actingAs($this->operator, 'sanctum')->putJson("/api/v1/platform/schools/{$this->school->id}/billing-plan", ['plan' => $plan]);
    }

    public function test_result_management_bills_day_students_at_500_and_boarders_at_1000(): void
    {
        $this->plan('results')->assertOk();

        $s = $this->row()['statement'];
        $this->assertSame(8, $s['active_students']);
        $this->assertSame(2, $s['boarding_students']);
        $this->assertSame(6, $s['day_students']);
        // 6 × ₦500 + 2 × ₦1,000
        $this->assertEquals(5000, $s['amount_naira']);
        $this->assertFalse($s['in_trial']);
    }

    public function test_the_subscription_starts_a_30_day_trial_that_covers_only_whole_terms(): void
    {
        $this->plan('subscription')->assertOk()->assertJsonPath('trial_ends_on', '2026-11-07');

        // The trial ends before this term does, so the term is billable:
        // 6 × ₦300 + 2 × ₦1,000.
        $s = $this->row()['statement'];
        $this->assertFalse($s['in_trial']);
        $this->assertEquals(3800, $s['amount_naira']);

        // A term that ends inside the trial is free and cannot be billed.
        $this->term->update(['end_date' => '2026-11-01']);
        $this->assertTrue($this->row()['statement']['in_trial']);
        $this->actingAs($this->operator, 'sanctum')->postJson("/api/v1/platform/schools/{$this->school->id}/bills", ['term_id' => $this->term->id])
            ->assertStatus(422);

        // Switching away and back does not restart the trial.
        $this->travelTo('2027-01-10');
        $this->plan('results')->assertOk();
        $this->plan('subscription')->assertOk()->assertJsonPath('trial_ends_on', '2026-11-07');
    }

    public function test_an_issued_bill_freezes_its_figures_and_is_marked_paid_or_waived(): void
    {
        $this->plan('results');

        $billId = $this->actingAs($this->operator, 'sanctum')->postJson("/api/v1/platform/schools/{$this->school->id}/bills", ['term_id' => $this->term->id])
            ->assertCreated()->json('bill_id');

        $this->actingAs($this->operator, 'sanctum')->postJson("/api/v1/platform/schools/{$this->school->id}/bills", ['term_id' => $this->term->id])
            ->assertStatus(422);

        // A rate change and a new pupil do not move what was issued.
        config()->set('services.billing.results_rate_kobo', 70000);
        $this->assertSame(500000, TermBill::find($billId)->amount_kobo);
        $this->assertEquals(5000, $this->row()['statement']['amount_naira']);

        $this->actingAs($this->operator, 'sanctum')->postJson("/api/v1/platform/bills/{$billId}/status", ['status' => 'waived'])
            ->assertStatus(422);
        $this->actingAs($this->operator, 'sanctum')->postJson("/api/v1/platform/bills/{$billId}/status", ['status' => 'paid', 'note' => 'Transfer GTB 0042'])
            ->assertOk();

        $this->assertSame('paid', TermBill::find($billId)->status);
        $this->assertNotNull(TermBill::find($billId)->paid_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.billing.issued']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.billing.paid']);

        $totals = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/billing')->json('totals');
        $this->assertEquals(5000, $totals['paid']['naira']);

        $history = $this->actingAs($this->operator, 'sanctum')->getJson("/api/v1/platform/schools/{$this->school->id}/bills")->assertOk()->json('data');
        $this->assertSame('First Term', $history[0]['term']);
    }

    public function test_a_school_without_a_plan_cannot_be_billed(): void
    {
        $this->assertNull($this->row()['plan']);
        $this->actingAs($this->operator, 'sanctum')->postJson("/api/v1/platform/schools/{$this->school->id}/bills", ['term_id' => $this->term->id])
            ->assertStatus(422)->assertJsonPath('message', 'Choose a plan for this school first.');
    }

    public function test_only_the_operator_sees_or_changes_billing(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/platform/billing')->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/platform/schools/{$this->school->id}/billing-plan", ['plan' => 'subscription'])->assertForbidden();
    }

    public function test_rates_are_editable_in_the_console(): void
    {
        $this->actingAs($this->operator, 'sanctum')->putJson('/api/v1/platform/settings', ['values' => [
            'services.billing.boarding_rate_kobo' => '1200',
        ]])->assertOk();

        $this->assertSame(120000, (int) config('services.billing.boarding_rate_kobo'));
        $this->assertEquals(1200, $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/billing')->json('rates.boarding'));
    }
}
