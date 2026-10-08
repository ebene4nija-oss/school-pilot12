<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manual payment recording and bank-transfer reconciliation: the invoice can
 * never be pushed past what it owes, a card payment can only be confirmed by
 * the gateway, and a settled payment cannot be un-settled by a "reject".
 */
class PaymentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $bursar;

    private User $parent;

    private Invoice $invoice;

    private string $host = 'http://crescent.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Crescent', 'slug' => 'crescent', 'subdomain' => 'crescent', 'domain' => 'crescent.schoolpilot.test']);
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31']);
        $term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-08', 'end_date' => '2026-12-18']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->bursar = $this->user('Mrs Bello', 'bursar');
        $this->parent = $this->user('Mr Obi', 'parent');
        $student = Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('Ada Obi', 'student')->id, 'class_id' => $class->id, 'admission_number' => 'ADA001']);
        $guardian = Guardian::create(['school_id' => $this->school->id, 'user_id' => $this->parent->id, 'relationship' => 'father']);
        $guardian->students()->attach($student->id);
        $this->invoice = Invoice::create(['school_id' => $this->school->id, 'term_id' => $term->id, 'student_id' => $student->id, 'invoice_number' => 'INV-1', 'total_amount' => 100000, 'amount_paid' => 0, 'status' => 'unpaid']);
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'-'.uniqid().'@crescent.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function pay(float $amount, string $gateway = 'cash')
    {
        return $this->actingAs($this->bursar, 'sanctum')
            ->postJson("{$this->host}/finance/payments", ['invoice_id' => $this->invoice->id, 'amount' => $amount, 'gateway' => $gateway]);
    }

    private function payment(string $gateway, string $status, float $amount = 40000): Payment
    {
        return Payment::create(['school_id' => $this->school->id, 'invoice_id' => $this->invoice->id, 'reference' => 'REF-'.uniqid(), 'amount' => $amount, 'gateway' => $gateway, 'status' => $status]);
    }

    private function reconcile(Payment $payment, string $action)
    {
        return $this->actingAs($this->bursar, 'sanctum')
            ->postJson("{$this->host}/finance/payments/reconcile-bank-transfer", ['payment_id' => $payment->id, 'action' => $action]);
    }

    public function test_a_payment_larger_than_the_balance_is_refused(): void
    {
        $this->pay(100000.01)->assertStatus(422)->assertJsonPath('balance', 100000);

        $this->assertSame(0, Payment::allTenants()->count());
        $this->assertEquals(0, $this->invoice->fresh()->amount_paid);
    }

    public function test_partial_then_exact_payments_settle_the_invoice(): void
    {
        $this->pay(60000)->assertOk();
        $this->assertSame('partial', $this->invoice->fresh()->status);

        $this->pay(40000)->assertOk();
        $this->assertSame('paid', $this->invoice->fresh()->status);

        $this->pay(1)->assertStatus(422);
    }

    public function test_a_card_payment_cannot_be_approved_by_hand(): void
    {
        $payment = $this->payment('paystack', 'pending');

        $this->reconcile($payment, 'approve')->assertStatus(422);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertEquals(0, $this->invoice->fresh()->amount_paid);
    }

    public function test_approving_a_transfer_twice_counts_it_once(): void
    {
        $payment = $this->payment('bank_transfer', 'pending');

        $this->reconcile($payment, 'approve')->assertOk();
        $this->reconcile($payment, 'approve')->assertStatus(409);

        $this->assertEquals(40000, $this->invoice->fresh()->amount_paid);
        $this->assertSame('partial', $this->invoice->fresh()->status);
    }

    public function test_approving_a_transfer_that_overshoots_the_balance_is_refused(): void
    {
        $this->pay(80000)->assertOk();
        $payment = $this->payment('bank_transfer', 'pending', 40000);

        $this->reconcile($payment, 'approve')->assertStatus(422);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertEquals(80000, $this->invoice->fresh()->amount_paid);
    }

    public function test_a_settled_payment_cannot_be_rejected(): void
    {
        $this->pay(30000)->assertOk();
        $settled = Payment::allTenants()->first();

        $this->reconcile($settled, 'reject')->assertStatus(409);

        $this->assertSame('successful', $settled->fresh()->status);
        $this->assertEquals(30000, $this->invoice->fresh()->amount_paid);
    }

    public function test_a_pending_transfer_can_be_rejected(): void
    {
        $payment = $this->payment('bank_transfer', 'pending');

        $this->reconcile($payment, 'reject')->assertOk();

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertEquals(0, $this->invoice->fresh()->amount_paid);
    }

    public function test_a_structured_whatsapp_never_sends_invented_figures_or_goes_to_a_placeholder(): void
    {
        $studentId = Student::allTenants()->first()->id;
        $url = "{$this->host}/notifications/whatsapp/structured";
        $admin = $this->user('Principal', 'school_admin');

        // No number on file and none given: refused, not sent to +2348000000000.
        $this->actingAs($admin, 'sanctum')
            ->postJson($url, ['type' => 'fee_balance', 'student_id' => $studentId, 'details' => ['balance' => '1,000']])
            ->assertStatus(422);

        // A number but incomplete figures: refused, not padded with demo values.
        $this->actingAs($admin, 'sanctum')
            ->postJson($url, ['type' => 'fee_balance', 'student_id' => $studentId, 'phone' => '+2348031234567', 'details' => ['balance' => '1,000']])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Missing details for a fee_balance message: total_amount, amount_paid, due_date.']);
    }
}
